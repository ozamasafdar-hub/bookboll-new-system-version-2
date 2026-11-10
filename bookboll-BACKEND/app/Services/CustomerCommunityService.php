<?php

namespace App\Services;

use App\Models\Community;
use App\Support\CommunityPlace;
use App\Models\CommunityMember;
use App\Support\CommunityPermissions;
use App\Models\CommunityPost;
use App\Models\CustomerMessage;
use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\StreetCred;
use App\Models\User;
use App\Support\QatarMarketGeo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Customer marketplace communities — parity with storefront community.js.
 * Public IDs are UUIDs; FKs use numeric communities.id internally.
 */
class CustomerCommunityService
{
    private const EDITABLE = [
        'name', 'description', 'sport', 'sports', 'cover_image', 'avatar_image',
        'images', 'is_private', 'city_id', 'country_id', 'settings',
    ];

    public function __construct(protected CommunityNotifier $notify) {}

    public function probe()
    {
        return sendSuccess('Communities available', ['enabled' => true]);
    }

    public function list(Request $request, ?User $viewer = null)
    {
        $cityId = $request->query('city_id');
        $sport  = trim((string) $request->query('sport', ''));
        $q      = trim((string) $request->query('q', ''));
        $iso    = strtoupper(trim((string) $request->query('country_iso', '')));
        $countryId = $request->query('country_id');

        $query = Community::query()
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->orderByDesc('created_at');

        $qatarId = QatarMarketGeo::countryId();
        $isQatar = ($iso === QatarMarketGeo::ISO)
            || ($countryId && (int) $countryId === $qatarId)
            || ($cityId && $qatarId && QatarMarketGeo::touchesQatar([(int) $cityId]));

        if ($isQatar) {
            $cityIds = QatarMarketGeo::allCityIds();
            $query->where(function ($q) use ($cityIds, $qatarId) {
                if ($cityIds) {
                    $q->whereIn('city_id', $cityIds);
                }
                if ($qatarId) {
                    $q->orWhere('country_id', $qatarId);
                }
            });
        } elseif ($cityId) {
            $query->where('city_id', $cityId);
        } elseif ($iso !== '' || $countryId) {
            // Country known, city not: show the whole country rather than the
            // global list, as the web does.
            $scopeCountryId = $countryId ? (int) $countryId : (int) Country::where('iso2', $iso)->value('id');
            if ($scopeCountryId) {
                $query->where(function ($q) use ($scopeCountryId) {
                    $q->where('country_id', $scopeCountryId)
                        ->orWhereIn('city_id', City::query()->where('country_id', $scopeCountryId)->select('id'));
                });
            }
        }

        $rows = $query->get();

        if ($sport !== '') {
            $sportLower = strtolower($sport);
            $rows = $rows->filter(function (Community $c) use ($sportLower) {
                if (strtolower((string) $c->sport) === $sportLower) {
                    return true;
                }
                $sports = is_array($c->sports) ? $c->sports : [];

                return collect($sports)->contains(fn ($s) => strtolower((string) $s) === $sportLower);
            })->values();
        }

        if ($q !== '') {
            $t = strtolower($q);
            $rows = $rows->filter(function (Community $c) use ($t) {
                return str_contains(strtolower((string) $c->name), $t)
                    || str_contains(strtolower((string) $c->description), $t);
            })->values();
        }

        $mapped = $this->sortByPopularity($this->decorateCounts($rows))
            ->map(fn (Community $c) => $this->mapCommunity($c))->values()->all();

        return sendSuccess('Communities fetched', ['communities' => $mapped]);
    }

    public function show(string $uuid, ?User $viewer = null)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        [$withCount] = $this->decorateCounts(collect([$community]))->all();
        $membership = null;
        if ($viewer?->uuid) {
            $membership = $this->mapMembership(
                CommunityMember::where('community_id', $community->id)
                    ->where('customer_id', $viewer->uuid)
                    ->first()
            );
        }

        $data = $this->mapCommunity($withCount);
        $data['membership'] = $membership;
        // The viewer's effective role, decided here. The client otherwise has
        // to infer it, and an owner whose membership row is missing would read
        // as a visitor in their own community.
        $data['viewer_role'] = CommunityPermissions::role(
            $community,
            $viewer?->uuid ? CommunityMember::where('community_id', $community->id)->where('customer_id', $viewer->uuid)->first() : null,
            $viewer?->uuid
        );

        return sendSuccess('Community fetched', ['community' => $data]);
    }

    public function mine(User $user)
    {
        if (! $user->uuid) {
            return sendSuccess('My communities', ['communities' => []]);
        }

        $mems = CommunityMember::where('customer_id', $user->uuid)->get();
        if ($mems->isEmpty()) {
            return sendSuccess('My communities', ['communities' => []]);
        }

        $byId = $mems->keyBy('community_id');
        $communities = Community::whereIn('id', $mems->pluck('community_id'))
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->get();

        $decorated = $this->decorateCounts($communities)->map(function (Community $c) use ($byId) {
            $row = $this->mapCommunity($c);
            $row['membership'] = $this->mapMembership($byId->get($c->id));

            return $row;
        })->sort(function ($a, $b) {
            $ao = ($a['membership']['role'] ?? '') === 'owner' ? 0 : 1;
            $bo = ($b['membership']['role'] ?? '') === 'owner' ? 0 : 1;

            return $ao <=> $bo ?: strcmp($a['name'] ?? '', $b['name'] ?? '');
        })->values()->all();

        return sendSuccess('My communities', ['communities' => $decorated]);
    }

    /**
     * Everyone waiting to be let into a community I run — across all of them at
     * once, so the notification bell doesn't ask community by community.
     */
    public function pendingMembers(User $user)
    {
        if (! $user->uuid) {
            return sendSuccess('Pending members', ['members' => []]);
        }

        $managedIds = CommunityMember::where('customer_id', $user->uuid)
            ->where('status', 'accepted')
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('community_id');

        if ($managedIds->isEmpty()) {
            return sendSuccess('Pending members', ['members' => []]);
        }

        $communities = Community::whereIn('id', $managedIds)
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->get()->keyBy('id');

        $pending = CommunityMember::whereIn('community_id', $communities->keys())
            ->where('status', 'pending')
            ->where('customer_id', '!=', $user->uuid)
            ->orderBy('joined_at')
            ->get();

        $members = $pending->map(fn (CommunityMember $m) => [
            'id'             => $m->uuid,
            'community_id'   => $communities->get($m->community_id)?->uuid,
            'community_name' => $communities->get($m->community_id)?->name ?? '',
            'customer_id'    => $m->customer_id,
            'joined_at'      => optional($m->joined_at)?->toIso8601String(),
        ])->values()->all();

        return sendSuccess('Pending members', ['members' => $members]);
    }

    public function create(User $user, array $fields)
    {
        if (! $user->uuid) {
            return sendError('Not signed in');
        }

        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') {
            return sendError('Give your community a name.');
        }

        // Validated before the transaction opens: a bad enum is the caller's
        // mistake, not a half-created community to roll back.
        [$settings, $settingsError] = CommunityPermissions::mergeSettings(null, is_array($fields['settings'] ?? null) ? $fields['settings'] : []);
        if ($settingsError) {
            return sendError($settingsError, ['settings' => [$settingsError]], 422);
        }

        try {
            $community = DB::transaction(function () use ($user, $fields, $name, $settings) {
                $community = Community::create([
                    'owner_customer_id' => $user->uuid,
                    'name'              => $name,
                    'description'       => trim((string) ($fields['description'] ?? '')),
                    'sport'             => trim((string) ($fields['sport'] ?? '')),
                    'sports'            => $fields['sports'] ?? [],
                    'city_id'           => $fields['city_id'] ?? null,
                    'country_id'        => $fields['country_id'] ?? null,
                    'cover_image'       => $fields['cover_image'] ?? '',
                    'avatar_image'      => $fields['avatar_image'] ?? null,
                    'images'            => $fields['images'] ?? [],
                    'is_private'        => (bool) ($fields['is_private'] ?? false),
                    'settings'          => $settings,
                    'moderation_status' => 'active',
                ]);

                CommunityMember::create([
                    'community_id' => $community->id,
                    'customer_id'  => $user->uuid,
                    'role'         => 'owner',
                    'status'       => 'accepted',
                    'joined_at'    => now(),
                ]);

                return $community;
            });
        } catch (\Throwable $e) {
            return sendError($e->getMessage() ?: 'Could not create the community.');
        }

        return sendSuccess('Community created', [
            'community' => $this->mapCommunity($community->setAttribute('memberCount', 1)),
        ]);
    }

    public function update(User $user, string $uuid, array $patch)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        $role = CommunityPermissions::role($community, $membership, $user->uuid);

        if (! CommunityPermissions::canConfigure($role)) {
            // A moderator may change the group-chat audience and nothing else.
            // The patch is accepted whole or refused whole: silently dropping
            // the rest would report success for a request half ignored.
            if ($role === 'moderator' && CommunityPermissions::isModeratorChatPatch($patch)) {
                [$merged, $error] = CommunityPermissions::mergeSettings($community, $patch['settings']);
                if ($error) {
                    return sendError($error, ['settings' => [$error]], 422);
                }

                $community->settings = $merged;
                $community->save();

                return sendSuccess('Community updated', ['community' => $this->mapCommunity($community->fresh())]);
            }

            if ($role === 'moderator') {
                return sendError('Moderators can only change who can send group messages.', [], 403);
            }

            return sendError('You cannot edit this community.', [], 403);
        }

        $clean = [];
        foreach (self::EDITABLE as $key) {
            if (array_key_exists($key, $patch)) {
                $clean[$key] = $patch[$key];
            }
        }
        if (array_key_exists('settings', $clean)) {
            // Merged over what is stored, so a patch naming one permission
            // cannot erase the other or any custom key saved beside them.
            [$merged, $error] = CommunityPermissions::mergeSettings(
                $community,
                is_array($clean['settings']) ? $clean['settings'] : []
            );
            if ($error) {
                return sendError($error, ['settings' => [$error]], 422);
            }
            $clean['settings'] = $merged;
        }
        if (isset($clean['name'])) {
            $clean['name'] = trim((string) $clean['name']);
            if ($clean['name'] === '') {
                return sendError('Give your community a name.');
            }
        }
        if (isset($clean['description'])) {
            $clean['description'] = trim((string) $clean['description']);
        }
        if (isset($clean['sport'])) {
            $clean['sport'] = trim((string) $clean['sport']);
        }
        if (array_key_exists('is_private', $clean)) {
            $clean['is_private'] = (bool) $clean['is_private'];
        }

        if ($clean) {
            $community->fill($clean)->save();
        }

        return sendSuccess('Community updated', ['community' => $this->mapCommunity($community->fresh())]);
    }

    public function join(User $user, string $uuid)
    {
        if (! $user->uuid) {
            return sendError('Not signed in');
        }

        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $existing = $this->membershipFor($community->id, $user->uuid);
        if ($existing && $existing->status === 'accepted') {
            return sendSuccess('Joined', ['membership' => $this->mapMembership($existing)]);
        }

        $status = $community->is_private ? 'pending' : 'accepted';
        $member = CommunityMember::updateOrCreate(
            [
                'community_id' => $community->id,
                'customer_id'  => $user->uuid,
            ],
            [
                'role'      => 'member',
                'status'    => $status,
                'joined_at' => $existing?->joined_at ?? now(),
            ]
        );

        // A pending request is the managers' decision; a join is their news.
        if ($status === 'pending') {
            $this->notify->joinRequested($community, $user);
        } else {
            $this->notify->memberJoined($community, $user);
        }

        return sendSuccess('Joined', ['membership' => $this->mapMembership($member->fresh())]);
    }

    public function leave(User $user, string $uuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $m = $this->membershipFor($community->id, $user->uuid);
        if ($m?->role === 'owner') {
            return sendError("You own this community — you can't leave it.");
        }
        if ($m) {
            $wasMember = $m->status === 'accepted';
            $m->delete();
            if ($wasMember) {
                $this->notify->memberLeft($community, $user);
            }
        }

        return sendSuccess('Left community', ['ok' => true]);
    }

    public function members(string $uuid, ?User $viewer = null)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        if ($community->is_private) {
            $membership = $this->membershipFor($community->id, $viewer?->uuid);
            if (! $this->isMember($membership) && $community->owner_customer_id !== $viewer?->uuid) {
                return sendSuccess('Members', ['members' => []]);
            }
        }

        $mems = CommunityMember::where('community_id', $community->id)
            ->orderBy('joined_at')
            ->get();

        if ($mems->isEmpty()) {
            return sendSuccess('Members', ['members' => []]);
        }

        $people = User::whereIn('uuid', $mems->pluck('customer_id')->unique())
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        $rank = ['owner' => 0, 'admin' => 1, 'moderator' => 2, 'member' => 3];
        $rows = $mems->map(function (CommunityMember $m) use ($people) {
            $p = $people->get($m->customer_id);
            $mapped = $this->mapMembership($m);
            $mapped['name'] = $p?->name ?: 'Player';
            $mapped['avatar'] = $p?->image ?: '';

            return $mapped;
        })->sort(function ($a, $b) use ($rank) {
            $ap = ($a['status'] ?? '') === 'pending' ? 1 : 0;
            $bp = ($b['status'] ?? '') === 'pending' ? 1 : 0;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            $ar = $rank[$a['role'] ?? 'member'] ?? 3;
            $br = $rank[$b['role'] ?? 'member'] ?? 3;
            if ($ar !== $br) {
                return $ar <=> $br;
            }

            return strcmp($a['joined_at'] ?? '', $b['joined_at'] ?? '');
        })->values()->all();

        return sendSuccess('Members', ['members' => $rows]);
    }

    public function respondToMember(User $user, string $uuid, string $memberUuid, bool $accept)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }
        if (! $this->canManage($this->membershipFor($community->id, $user->uuid))) {
            return sendError('Not allowed.', [], 403);
        }

        $member = CommunityMember::where('community_id', $community->id)
            ->where('uuid', $memberUuid)
            ->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }

        if ($accept) {
            $member->status = 'accepted';
            $member->save();
            $this->notify->requestAccepted($community, $member);
        } else {
            $member->delete();
            $this->notify->requestDeclined($community, $member);
        }

        return sendSuccess('Member updated', ['ok' => true]);
    }

    /** Resolve community from member uuid (storefront only has member id). */
    public function respondToMemberById(User $user, string $memberUuid, bool $accept)
    {
        $member = CommunityMember::where('uuid', $memberUuid)->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        $community = Community::find($member->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->respondToMember($user, $community->uuid, $memberUuid, $accept);
    }

    public function removeMember(User $user, string $uuid, string $memberUuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }
        $actorRole = CommunityPermissions::role($community, $this->membershipFor($community->id, $user->uuid), $user->uuid);
        if (! CommunityPermissions::canConfigure($actorRole)) {
            return sendError('Not allowed.', [], 403);
        }

        $member = CommunityMember::where('community_id', $community->id)
            ->where('uuid', $memberUuid)
            ->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        if ($member->role === 'owner') {
            return sendError('Cannot remove the owner.');
        }

        // Removing an admin is a demotion by another route, so it follows the
        // same rule: the owner's call alone. Otherwise two admins could remove
        // each other and the community would turn on who clicked first.
        if ($member->role === 'admin' && $actorRole !== 'owner') {
            return sendError('Only the owner can remove an admin.', [], 403);
        }

        if ((string) $member->customer_id === (string) $user->uuid) {
            return sendError('Use Leave community to remove yourself.', [], 422);
        }

        $member->delete();
        $this->notify->memberRemoved($community, $member, $user);

        return sendSuccess('Member removed', ['ok' => true]);
    }

    public function removeMemberById(User $user, string $memberUuid)
    {
        $member = CommunityMember::where('uuid', $memberUuid)->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        $community = Community::find($member->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->removeMember($user, $community->uuid, $memberUuid);
    }

    public function setMemberRole(User $user, string $uuid, string $memberUuid, string $role)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        // Scoped to THIS community: a member uuid is not proof of access to it.
        $member = CommunityMember::where('community_id', $community->id)
            ->where('uuid', $memberUuid)
            ->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }

        $actorRole = CommunityPermissions::role($community, $this->membershipFor($community->id, $user->uuid), $user->uuid);
        $targetRole = CommunityPermissions::role($community, $member, $member->customer_id);
        $isSelf = (string) $member->customer_id === (string) $user->uuid;

        [$allowed, $why, $status] = CommunityPermissions::canSetRole($actorRole, $targetRole, $role, $isSelf);
        if (! $allowed) {
            return sendError($why, [], $status);
        }

        if ($member->status !== 'accepted') {
            return sendError('Accept this member before giving them a role.', [], 422);
        }

        $member->role = $role;
        $member->save();
        $this->notify->roleChanged($community, $member, $role, $user);

        // The role is read back from the row, because the client only reports
        // success when the role it asked for is the role that came back.
        return sendSuccess('Role updated', [
            'ok'     => true,
            'member' => array_merge($this->mapMembership($member->fresh()), ['community_id' => $community->uuid]),
        ]);
    }

    public function setMemberRoleById(User $user, string $memberUuid, string $role)
    {
        $member = CommunityMember::where('uuid', $memberUuid)->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        $community = Community::find($member->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->setMemberRole($user, $community->uuid, $memberUuid, $role);
    }

    public function feed(string $uuid, ?User $viewer = null)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        if ($community->is_private) {
            $membership = $this->membershipFor($community->id, $viewer?->uuid);
            if (! $this->isMember($membership) && $community->owner_customer_id !== $viewer?->uuid) {
                return sendSuccess('Feed', ['posts' => []]);
            }
        }

        $posts = CommunityPost::where('community_id', $community->id)
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        if ($posts->isEmpty()) {
            return sendSuccess('Feed', ['posts' => []]);
        }

        $people = User::whereIn('uuid', $posts->pluck('author_id')->unique())
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        $mapped = $posts->map(function (CommunityPost $p) use ($people, $community) {
            $author = $people->get($p->author_id);

            return [
                'id'                => $p->uuid,
                'community_id'      => $community->uuid,
                'author_id'         => $p->author_id,
                'body'              => $p->body ?? '',
                'photos'            => array_values(array_filter($p->photos ?? [])),
                'created_at'        => optional($p->created_at)?->toIso8601String(),
                'authorName'        => $author?->name ?: 'Player',
                'authorAvatar'      => $author?->image ?: '',
                'moderation_status' => $p->moderation_status ?: 'active',
            ];
        })->values()->all();

        return sendSuccess('Feed', ['posts' => $mapped]);
    }

    public function createPost(User $user, string $uuid, array $data)
    {
        if (! $user->uuid) {
            return sendError('Not signed in');
        }

        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        $role = CommunityPermissions::role($community, $membership, $user->uuid);
        if (! CommunityPermissions::isMember($role)) {
            return sendError('Join the community to post.', [], 403);
        }

        // The configured feed audience, enforced here and not only in the UI.
        $settings = CommunityPermissions::settings($community);
        if (! CommunityPermissions::canContribute($role, $settings, 'posts')) {
            return sendError(
                $settings['post_permission'] === 'admins'
                    ? 'Only the owner and admins can post in this community.'
                    : 'Only admins and moderators can post in this community.',
                [],
                403
            );
        }

        $body = trim((string) ($data['body'] ?? ''));
        $photos = array_values(array_filter($data['photos'] ?? []));
        if ($body === '' && ! $photos) {
            return sendError('Write something or add a photo.');
        }

        $post = CommunityPost::create([
            'community_id'      => $community->id,
            'author_id'         => $user->uuid,
            'body'              => $body,
            'photos'            => $photos,
            'moderation_status' => 'active',
        ]);

        $this->notify->postCreated($community, $post, $user);

        return sendSuccess('Post created', [
            'post' => [
                'id'           => $post->uuid,
                'community_id' => $community->uuid,
                'author_id'    => $post->author_id,
                'body'         => $post->body ?? '',
                'photos'       => $photos,
                'created_at'   => optional($post->created_at)?->toIso8601String(),
            ],
        ]);
    }

    public function deletePost(User $user, string $uuid, string $postUuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $post = CommunityPost::where('community_id', $community->id)
            ->where('uuid', $postUuid)
            ->first();
        if (! $post) {
            return sendError('Post not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        $role = CommunityPermissions::role($community, $membership, $user->uuid);
        $isAuthor = $post->author_id === $user->uuid;

        // Authors keep their own posts; moderators remove unwanted ones. The
        // role is read from THIS community's membership, so a moderator
        // elsewhere has no powers here.
        if (! $isAuthor && ! CommunityPermissions::canModerate($role)) {
            return sendError('Not allowed.', [], 403);
        }

        $post->delete();
        if (! $isAuthor) {
            $this->notify->postRemoved($community, $post, $user);
        }

        return sendSuccess('Post deleted', ['ok' => true]);
    }

    public function deletePostById(User $user, string $postUuid)
    {
        $post = CommunityPost::where('uuid', $postUuid)->first();
        if (! $post) {
            return sendError('Post not found.', [], 404);
        }
        $community = Community::find($post->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->deletePost($user, $community->uuid, $postUuid);
    }

    public function loadGroupThread(User $user, string $key, int $limit = 200)
    {
        if ($this->isMeetKey($key)) {
            return $this->loadMeetThread($user, $key, $limit);
        }

        $communityUuid = $this->communityUuidFromGroupKey($key);
        if (! $communityUuid) {
            return sendError('Invalid conversation key.');
        }

        $community = $this->findVisibleByUuid($communityUuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        if (! $this->isMember($membership)) {
            return sendError('Members only.', [], 403);
        }

        return sendSuccess('Messages', ['messages' => $this->groupThreadRows($key, $limit)]);
    }

    /** A group thread, oldest first, each row carrying its sender's name and avatar. */
    protected function groupThreadRows(string $key, int $limit): array
    {
        $rows = CustomerMessage::where('conversation_key', $key)
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $people = User::whereIn('uuid', $rows->pluck('sender_id')->unique())
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        return $rows->map(function (CustomerMessage $m) use ($people) {
            $p = $people->get($m->sender_id);

            return [
                'id'               => $m->uuid,
                'conversation_key' => $m->conversation_key,
                'sender_id'        => $m->sender_id,
                'recipient_id'     => $m->recipient_id,
                'body'             => $m->body,
                'created_at'       => optional($m->created_at)?->toIso8601String(),
                'read_at'          => optional($m->read_at)?->toIso8601String(),
                'senderName'       => $p?->name ?: 'Player',
                'senderAvatar'     => $p?->image ?: '',
            ];
        })->values()->all();
    }

    // ── Event (meetup) chat — `meet__{gameUuid}` on the same endpoints ──────────
    //
    // The apps talk to one group-message API for both community chats and the
    // chat of a hosted game. A community key is `community__{uuid}`; a game's is
    // `meet__{uuid}`. Who may read and send a game thread is decided by the game
    // itself: the host, and everyone whose join request was accepted.

    public const MEET_PREFIX = 'meet__';

    protected function isMeetKey(string $key): bool
    {
        return str_starts_with($key, self::MEET_PREFIX);
    }

    protected function gameFromMeetKey(string $key): ?HostedGame
    {
        $uuid = substr($key, strlen(self::MEET_PREFIX));
        if (! Str::isUuid($uuid)) {
            return null;
        }

        return HostedGame::where('uuid', $uuid)->first();
    }

    /** 'host', 'member' (accepted joiner) or null for someone with no place in this event. */
    protected function meetRole(HostedGame $game, User $user): ?string
    {
        if (! $user->uuid) {
            return null;
        }
        if ((string) $game->host_customer_id === (string) $user->uuid) {
            return 'host';
        }

        $accepted = GameJoinRequest::where('game_id', $game->id)
            ->where('joiner_customer_id', $user->uuid)
            ->where('status', 'accepted')
            ->exists();

        return $accepted ? 'member' : null;
    }

    protected function loadMeetThread(User $user, string $key, int $limit)
    {
        $game = $this->gameFromMeetKey($key);
        if (! $game) {
            return sendError('Event not found.', [], 404);
        }
        if (! $this->meetRole($game, $user)) {
            return sendError('Only people going to this event can read its chat.', [], 403);
        }

        return sendSuccess('Messages', ['messages' => $this->groupThreadRows($key, $limit)]);
    }

    protected function sendMeetMessage(User $user, string $key, string $body)
    {
        $game = $this->gameFromMeetKey($key);
        if (! $game) {
            return sendError('Event not found.', [], 404);
        }
        if (! $this->meetRole($game, $user)) {
            return sendError('Only people going to this event can post in its chat.', [], 403);
        }
        if ($game->status === 'cancelled') {
            return sendError('This event was cancelled, so its chat is closed.', [], 422);
        }

        $message = CustomerMessage::create([
            'conversation_key' => $key,
            'sender_id'        => $user->uuid,
            'recipient_id'     => null,
            'body'             => $body,
        ]);

        $this->notify->meetChatMessage($game, $message, $user);

        return sendSuccess('Message sent', [
            'message' => [
                'id'               => $message->uuid,
                'conversation_key' => $message->conversation_key,
                'sender_id'        => $message->sender_id,
                'recipient_id'     => null,
                'body'             => $message->body,
                'created_at'       => optional($message->created_at)?->toIso8601String(),
                'read_at'          => null,
                'senderName'       => $user->name ?: 'Player',
                'senderAvatar'     => $user->image ?: '',
            ],
        ]);
    }

    /** Remove one message from an event chat: its author, or the host. */
    protected function deleteMeetMessage(User $user, CustomerMessage $message)
    {
        $game = $this->gameFromMeetKey((string) $message->conversation_key);
        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        $role = $this->meetRole($game, $user);
        if (! $role) {
            return sendError('Members only.', [], 403);
        }
        if ($message->sender_id !== $user->uuid && $role !== 'host') {
            return sendError('Not allowed.', [], 403);
        }

        $message->delete();

        return sendSuccess('Message deleted', ['ok' => true]);
    }

    /**
     * Remove one group-chat message.
     *
     * The community is taken from the MESSAGE's own conversation key, never from
     * the key in the request: otherwise a moderator of community A could delete
     * a message in community B by sending B's message id with A's key. The
     * submitted key still has to match, so a caller cannot act on a thread it
     * did not mean to address.
     *
     * Allowed for the message's author, and for the people who run the
     * community. Moderators are named in the product spec but are not yet a
     * role this backend stores — community_members carries owner and admin only
     * — so they gain this through the role hierarchy work, not here.
     *
     * Soft delete: moderation has to stay reviewable, and SoftDeletes takes the
     * message out of the thread, the unread counts and the inbox preview.
     */
    public function deleteGroupMessage(User $user, string $messageUuid, string $key)
    {
        if (! $user->uuid) {
            return sendError('Not allowed.', [], 403);
        }

        $message = CustomerMessage::where('uuid', $messageUuid)->first();
        if (! $message) {
            return sendError('Message not found.', [], 404);
        }

        // The message decides which community this is, not the caller.
        $actualKey = (string) $message->conversation_key;
        if ($key !== '' && $key !== $actualKey) {
            return sendError('That message is not part of this conversation.', [], 403);
        }

        if ($this->isMeetKey($actualKey)) {
            return $this->deleteMeetMessage($user, $message);
        }

        $communityUuid = $this->communityUuidFromGroupKey($actualKey);
        if (! $communityUuid) {
            return sendError('Not a community conversation.', [], 422);
        }

        $community = $this->findVisibleByUuid($communityUuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        $role = CommunityPermissions::role($community, $membership, $user->uuid);
        if (! CommunityPermissions::isMember($role)) {
            return sendError('Members only.', [], 403);
        }

        $isAuthor = $message->sender_id === $user->uuid;
        if (! $isAuthor && ! CommunityPermissions::canModerate($role)) {
            return sendError('Not allowed.', [], 403);
        }

        $message->delete();
        if (! $isAuthor) {
            $this->notify->chatMessageRemoved($community, $message, $user);
        }

        // Reported only after the row is actually gone, because the client
        // leaves the message on screen unless the server confirms removal.
        return sendSuccess('Message deleted', ['ok' => true]);
    }

    public function sendGroupMessage(User $user, string $key, string $body)
    {
        $clean = trim($body);
        if (! $user->uuid || $clean === '') {
            return sendError('Empty message');
        }

        if ($this->isMeetKey($key)) {
            return $this->sendMeetMessage($user, $key, $clean);
        }

        $communityUuid = $this->communityUuidFromGroupKey($key);
        if (! $communityUuid) {
            return sendError('Invalid conversation key.');
        }

        $community = $this->findVisibleByUuid($communityUuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        $role = CommunityPermissions::role($community, $membership, $user->uuid);
        if (! CommunityPermissions::isMember($role)) {
            return sendError('Members only.', [], 403);
        }

        // Accepted members always READ the thread; sending follows the
        // configured audience. The legacy chat_locked flag is folded into that
        // one decision rather than checked separately.
        $settings = CommunityPermissions::settings($community);
        if (! CommunityPermissions::canContribute($role, $settings, 'chat')) {
            return sendError(
                $settings['chat_permission'] === 'admins'
                    ? 'Only the owner and admins can send messages here.'
                    : 'Only admins and moderators can send messages here.',
                [],
                403
            );
        }

        $message = CustomerMessage::create([
            'conversation_key' => $key,
            'sender_id'        => $user->uuid,
            'recipient_id'     => null,
            'body'             => $clean,
        ]);

        $this->notify->chatMessage($community, $message, $user);

        return sendSuccess('Message sent', [
            'message' => [
                'id'               => $message->uuid,
                'conversation_key' => $message->conversation_key,
                'sender_id'        => $message->sender_id,
                'recipient_id'     => null,
                'body'             => $message->body,
                'created_at'       => optional($message->created_at)?->toIso8601String(),
            ],
        ]);
    }

    public function markChatRead(User $user, string $uuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community || ! $user->uuid) {
            return sendSuccess('OK', ['ok' => true]);
        }

        CommunityMember::where('community_id', $community->id)
            ->where('customer_id', $user->uuid)
            ->update(['last_read_at' => now()]);

        return sendSuccess('Marked read', ['ok' => true]);
    }

    public function chatUnread(User $user, string $uuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community || ! $user->uuid) {
            return sendSuccess('Unread', ['count' => 0]);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        if (! $this->isMember($membership)) {
            return sendSuccess('Unread', ['count' => 0]);
        }

        $key = 'community__'.$community->uuid;
        $q = CustomerMessage::where('conversation_key', $key)
            ->where('sender_id', '!=', $user->uuid);
        if ($membership->last_read_at) {
            $q->where('created_at', '>', $membership->last_read_at);
        }

        return sendSuccess('Unread', ['count' => (int) $q->count()]);
    }

    public function membershipCheck(string $communityUuid, string $customerId)
    {
        $community = Community::where('uuid', $communityUuid)->first();
        if (! $community) {
            return sendSuccess('Membership check', [
                'membership' => null,
                'is_member'  => false,
            ]);
        }

        $m = $this->membershipFor($community->id, $customerId);

        return sendSuccess('Membership check', [
            'membership' => $this->mapMembership($m),
            'is_member'  => $this->isMember($m),
        ]);
    }

    public function giveStreetCred(User $user, array $data)
    {
        $gameId = $data['game_id'] ?? null;
        $receiverId = $data['receiver_id'] ?? null;
        $badge = $data['badge'] ?? null;

        if (! $gameId || ! $receiverId || ! isset(StreetCred::BADGE_LABELS[$badge])) {
            return sendError('Invalid props.');
        }
        if (! $user->uuid) {
            return sendError('Not signed in');
        }
        if ($user->uuid === $receiverId) {
            return sendError("You can't give props to yourself 😄");
        }

        StreetCred::updateOrCreate(
            [
                'game_uuid'     => $gameId,
                'giver_uuid'    => $user->uuid,
                'receiver_uuid' => $receiverId,
                'badge'         => $badge,
            ],
            []
        );

        return sendSuccess('Props given', ['ok' => true]);
    }

    public function myPropsForGame(User $user, string $gameId)
    {
        if (! $user->uuid || ! $gameId) {
            return sendSuccess('Props', ['props' => (object) []]);
        }

        $rows = StreetCred::where('game_uuid', $gameId)
            ->where('giver_uuid', $user->uuid)
            ->get(['receiver_uuid', 'badge']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->receiver_uuid] ??= [];
            $out[$r->receiver_uuid][] = $r->badge;
        }

        return sendSuccess('Props', ['props' => $out]);
    }

    public function streetCredCounts(string $customerId)
    {
        $rows = StreetCred::where('receiver_uuid', $customerId)->get(['badge']);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->badge] = ($counts[$row->badge] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $badge => $count) {
            $meta = StreetCred::BADGE_LABELS[$badge] ?? null;
            if (! $meta) {
                continue;
            }
            $out[] = [
                'badge' => $badge,
                'count' => $count,
                'label' => $meta['label'],
                'emoji' => $meta['emoji'],
            ];
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return sendSuccess('Street cred', ['streetCred' => $out]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function findVisibleByUuid(string $uuid): ?Community
    {
        $c = Community::where('uuid', $uuid)->first();
        if (! $c) {
            return null;
        }
        $status = $c->moderation_status ?: 'active';
        if ($status === 'removed') {
            return null;
        }

        return $c;
    }

    protected function membershipFor(int $communityId, ?string $customerId): ?CommunityMember
    {
        if (! $customerId) {
            return null;
        }

        return CommunityMember::where('community_id', $communityId)
            ->where('customer_id', $customerId)
            ->first();
    }

    protected function canManage(?CommunityMember $membership): bool
    {
        return $membership
            && $membership->status === 'accepted'
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    protected function isMember(?CommunityMember $membership): bool
    {
        return $membership && $membership->status === 'accepted';
    }

    protected function decorateCounts($communities)
    {
        $collection = $communities instanceof \Illuminate\Support\Collection
            ? $communities
            : collect($communities);

        if ($collection->isEmpty()) {
            return $collection;
        }

        $ids = $collection->pluck('id')->all();
        $counts = CommunityMember::whereIn('community_id', $ids)
            ->where('status', 'accepted')
            ->selectRaw('community_id, COUNT(*) as cnt')
            ->groupBy('community_id')
            ->pluck('cnt', 'community_id');

        return $collection->map(function (Community $c) use ($counts) {
            $c->setAttribute('memberCount', (int) ($counts[$c->id] ?? 0));

            return $c;
        });
    }

    /**
     * Most popular first, by accepted members — the order the marketplace home
     * and the directory present. Name then id break ties so the sequence is
     * total and stable: two communities with the same following must not swap
     * places between reloads. Ordered here as well as in the client so the
     * ranking does not depend on which surface is asking, and so it still holds
     * if this list is ever paginated — a client cannot rank rows the server
     * never sent.
     *
     * @param  \Illuminate\Support\Collection  $communities  decorateCounts() applied
     * @return \Illuminate\Support\Collection
     */
    protected function sortByPopularity($communities)
    {
        return $communities->sortBy(fn (Community $c) => [
            -(int) ($c->memberCount ?? 0),
            mb_strtolower((string) ($c->name ?? '')),
            (int) $c->id,
        ])->values();
    }

    protected function mapCommunity(Community $c): array
    {
        return [
            'id'                => $c->uuid,
            'owner_customer_id' => $c->owner_customer_id ? (string) $c->owner_customer_id : null,
            'name'              => $c->name ?? '',
            'description'       => $c->description ?? '',
            'sport'             => $c->sport ?? '',
            'sports'            => $c->sports ?? [],
            'city_id'           => $c->city_id ? (string) $c->city_id : null,
            'country_id'        => $c->country_id ? (string) $c->country_id : null,
            // Names beside the ids: "Doha, Qatar" is what a reader needs.
            ...CommunityPlace::names($c->city_id, $c->country_id),
            'cover_image'       => $c->cover_image ?? '',
            'avatar_image'      => $c->avatar_image ?? '',
            'images'            => $c->images ?? [],
            'is_private'        => (bool) $c->is_private,
            // Always explicit. An absent permission is indistinguishable from
            // a server that dropped it, which is the exact failure the client's
            // read-back check exists to catch.
            'settings'          => CommunityPermissions::settings($c),
            'moderation_status' => $c->moderation_status ?: 'active',
            'memberCount'       => (int) ($c->memberCount ?? 0),
            'created_at'        => optional($c->created_at)?->toIso8601String(),
        ];
    }

    protected function mapMembership(?CommunityMember $m): ?array
    {
        if (! $m) {
            return null;
        }

        return [
            'id'           => $m->uuid,
            'community_id' => null, // filled by callers when needed via community uuid
            'customer_id'  => $m->customer_id,
            'role'         => $m->role,
            'status'       => $m->status,
            'last_read_at' => optional($m->last_read_at)?->toIso8601String(),
            'joined_at'    => optional($m->joined_at)?->toIso8601String(),
        ];
    }

    protected function communityUuidFromGroupKey(string $key): ?string
    {
        if (! str_starts_with($key, 'community__')) {
            return null;
        }
        $uuid = substr($key, strlen('community__'));

        return Str::isUuid($uuid) ? $uuid : null;
    }
}
