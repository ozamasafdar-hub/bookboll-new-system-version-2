<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CustomerCommunityService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class CustomerCommunityController extends Controller
{
    public function __construct(protected CustomerCommunityService $communities) {}

    public function probe()
    {
        return $this->communities->probe();
    }

    public function index(Request $request)
    {
        return $this->communities->list($request, $this->optionalSanctumUser($request));
    }

    public function show(Request $request, string $uuid)
    {
        return $this->communities->show($uuid, $this->optionalSanctumUser($request));
    }

    public function mine()
    {
        return $this->communities->mine(auth()->user());
    }

    /** Everyone waiting to join any community I run. */
    public function pendingMembers()
    {
        return $this->communities->pendingMembers(auth()->user());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'         => 'required|string|max:120',
            'description'  => 'nullable|string|max:5000',
            'sport'        => 'nullable|string|max:80',
            'sports'       => 'nullable|array',
            'sports.*'     => 'nullable|string|max:80',
            'city_id'      => 'nullable|numeric|exists:cities,id',
            'country_id'   => 'nullable|numeric|exists:countries,id',
            'cover_image'  => 'nullable|string|max:2048',
            'avatar_image' => 'nullable|string|max:2048',
            'images'       => 'nullable|array',
            'images.*'     => 'nullable|string|max:2048',
            'is_private'   => 'nullable|boolean',
            'settings'     => 'nullable|array',
        ]);

        return $this->communities->create(auth()->user(), $data);
    }

    public function update(Request $request, string $uuid)
    {
        $data = $request->validate([
            'name'         => 'sometimes|string|max:120',
            'description'  => 'nullable|string|max:5000',
            'sport'        => 'nullable|string|max:80',
            'sports'       => 'nullable|array',
            'sports.*'     => 'nullable|string|max:80',
            'city_id'      => 'nullable|string|max:64',
            'country_id'   => 'nullable|string|max:64',
            'cover_image'  => 'nullable|string|max:2048',
            'avatar_image' => 'nullable|string|max:2048',
            'images'       => 'nullable|array',
            'images.*'     => 'nullable|string|max:2048',
            'is_private'   => 'nullable|boolean',
            'settings'     => 'nullable|array',
        ]);

        return $this->communities->update(auth()->user(), $uuid, $data);
    }

    public function join(string $uuid)
    {
        return $this->communities->join(auth()->user(), $uuid);
    }

    public function leave(string $uuid)
    {
        return $this->communities->leave(auth()->user(), $uuid);
    }

    public function members(Request $request, string $uuid)
    {
        return $this->communities->members($uuid, $this->optionalSanctumUser($request));
    }

    public function respondToMember(Request $request, string $uuid, string $memberUuid)
    {
        $data = $request->validate(['accept' => 'required|boolean']);

        return $this->communities->respondToMember(
            auth()->user(),
            $uuid,
            $memberUuid,
            (bool) $data['accept']
        );
    }

    public function respondToMemberById(Request $request, string $memberUuid)
    {
        $data = $request->validate(['accept' => 'required|boolean']);

        return $this->communities->respondToMemberById(
            auth()->user(),
            $memberUuid,
            (bool) $data['accept']
        );
    }

    public function removeMember(string $uuid, string $memberUuid)
    {
        return $this->communities->removeMember(auth()->user(), $uuid, $memberUuid);
    }

    public function removeMemberById(string $memberUuid)
    {
        return $this->communities->removeMemberById(auth()->user(), $memberUuid);
    }

    public function setMemberRole(Request $request, string $uuid, string $memberUuid)
    {
        $data = $request->validate([
            'role' => 'required|string|in:admin,moderator,member',
        ]);

        return $this->communities->setMemberRole(
            auth()->user(),
            $uuid,
            $memberUuid,
            $data['role']
        );
    }

    public function setMemberRoleById(Request $request, string $memberUuid)
    {
        $data = $request->validate([
            'role' => 'required|string|in:admin,moderator,member',
        ]);

        return $this->communities->setMemberRoleById(
            auth()->user(),
            $memberUuid,
            $data['role']
        );
    }

    public function feed(Request $request, string $uuid)
    {
        return $this->communities->feed($uuid, $this->optionalSanctumUser($request));
    }

    public function createPost(Request $request, string $uuid)
    {
        $data = $request->validate([
            'body'     => 'nullable|string|max:5000',
            'photos'   => 'nullable|array',
            'photos.*' => 'nullable|string|max:2048',
        ]);

        return $this->communities->createPost(auth()->user(), $uuid, $data);
    }

    public function deletePost(string $uuid, string $postUuid)
    {
        return $this->communities->deletePost(auth()->user(), $uuid, $postUuid);
    }

    public function deletePostById(string $postUuid)
    {
        return $this->communities->deletePostById(auth()->user(), $postUuid);
    }

    public function loadGroupThread(Request $request)
    {
        $data = $request->validate([
            'key'   => 'required|string|max:200',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        return $this->communities->loadGroupThread(
            auth()->user(),
            $data['key'],
            (int) ($data['limit'] ?? 200)
        );
    }

    public function sendGroupMessage(Request $request)
    {
        $data = $request->validate([
            'key'  => 'required|string|max:200',
            'body' => 'required|string|max:5000',
            'mentions' => 'sometimes|array|max:20',
            'mentions.*.user_id' => 'required|uuid',
            'mentions.*.start' => 'required|integer|min:0|max:10000',
            'mentions.*.length' => 'required|integer|min:2|max:600',
        ]);

        return $this->communities->sendGroupMessage(
            auth()->user(),
            $data['key'],
            $data['body'],
            $data['mentions'] ?? []
        );
    }

    /** Moderation: remove one message from a community's group chat. */
    public function deleteGroupMessage(Request $request, string $id)
    {
        // The key is what the client says the thread is; the service checks it
        // against the message's own thread rather than trusting it.
        $data = $request->validate([
            'key' => 'nullable|string|max:200',
        ]);

        return $this->communities->deleteGroupMessage(
            auth()->user(),
            $id,
            (string) ($data['key'] ?? '')
        );
    }

    public function markChatRead(string $uuid)
    {
        return $this->communities->markChatRead(auth()->user(), $uuid);
    }

    public function chatUnread(string $uuid)
    {
        return $this->communities->chatUnread(auth()->user(), $uuid);
    }

    public function membershipCheck(Request $request)
    {
        $data = $request->validate([
            'community_id' => 'required|uuid',
            'customer_id'  => 'required|uuid',
        ]);

        return $this->communities->membershipCheck($data['community_id'], $data['customer_id']);
    }

    public function giveStreetCred(Request $request)
    {
        $data = $request->validate([
            'game_id'     => 'required|uuid',
            'receiver_id' => 'required|uuid',
            'badge'       => 'required|string|max:64',
        ]);

        return $this->communities->giveStreetCred(auth()->user(), $data);
    }

    public function myPropsForGame(Request $request)
    {
        $data = $request->validate([
            'game_id' => 'required|uuid',
        ]);

        return $this->communities->myPropsForGame(auth()->user(), $data['game_id']);
    }

    public function streetCredCounts(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|uuid',
        ]);

        return $this->communities->streetCredCounts($data['customer_id']);
    }

    /** How many direct messages are waiting for me — the header badge. */
    public function unreadMessages()
    {
        $count = \App\Models\CustomerMessage::where('recipient_id', auth()->user()->uuid)
            ->whereNull('read_at')
            ->count();

        return sendSuccess('Unread messages', ['count' => $count]);
    }

    protected function optionalSanctumUser(Request $request): ?User
    {
        $user = $request->user('sanctum');
        if ($user instanceof User) {
            return $user;
        }

        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable instanceof User ? $accessToken->tokenable : null;
    }
}
