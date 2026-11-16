<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\Tag;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;

class PostService
{
    public function __construct(protected Post $postModel, protected PostCategory $postCategoryModel, protected Tag $tagModel) {}

    public function list($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;

        $posts = $this->postModel->latest()
            ->forOwner($this->adminBlogOwner())
            ->with(['author', 'category', 'tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                        ->orWhere('slug', 'LIKE', "%{$search}%")
                        ->orWhere('excerpt', 'LIKE', "%{$search}%")
                        ->orWhere('content', 'LIKE', "%{$search}%")
                        ->orWhere('published_at', 'LIKE', "%{$search}%")
                        ->orWhere('seo_title', 'LIKE', "%{$search}%");
                });
            })
            ->when($request->status ?? null, function ($query, $status) {
                $query->where('status', 'LIKE', $status);
            })
            ->when($request->category ?? null, function ($query, $category) {
                $query->whereHas('category', function ($q) use ($category) {
                    $q->where('name', 'LIKE', $category);
                });
            })
            ->when($request->author ?? null, function ($query, $author) {
                $query->whereHas('author', function ($q) use ($author) {
                    $q->where('name', 'LIKE', $author);
                });
            })
            ->when($request->type ?? null, function ($query, $type) {
                $query->where('type', $type);
            })
            ->paginate($records_per_page);

        return sendSuccess('Posts fetched successfully', compact('posts'));
    }

    public function tagsList()
    {
        $tags = Tag::latest()->get();
        return sendSuccess('Tags fetched successfully', compact('tags'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|unique:posts,slug',
            'excerpt'          => 'nullable|string|max:500',
            'content'          => 'nullable|string',
            'read_time'        => 'nullable|string|max:50',
            'status'           => 'required|in:draft,published',
            'type'             => 'nullable|in:' . implode(',', Post::TYPES),
            'post_category_id' => 'nullable|exists:post_categories,id',
            'featured_image'   => [
                'nullable',
                File::types([
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/bmp',
                    'image/webp',
                    'image/avif',
                ])->max(5120 * 1024),
            ],
            'image_url'        => 'nullable|url',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'seo_title'        => 'nullable|string|max:255',
            'seo_description'  => 'nullable|string|max:160',
            'featured'         => 'nullable',
        ]);

        try {
            DB::beginTransaction();

            $formData['type'] = $formData['type'] ?? Post::TYPE_MAIN;
            $formData['read_time'] = $formData['read_time'] ?? '5 min read';
            $formData['owner_user_id'] = $this->adminBlogOwner();

            if ($formData['status'] == $this->postModel::PUBLISHED) {
                $formData['published_at'] = now();
            }

            if (! empty($formData['featured'])) {
                $formData['featured'] = true;
                $this->postModel->where('type', $formData['type'])->update(['featured' => false]);
            } else {
                $formData['featured'] = false;
            }

            if (empty($formData['slug'])) {
                $formData['slug'] = Str::slug($formData['title']);
            }

            if ($request->hasFile('featured_image')) {
                $formData['featured_image'] = $request->file('featured_image')->store('posts', 'public');
            } elseif ($request->filled('image_url')) {
                $formData['featured_image'] = $request->input('image_url');
            }
            unset($formData['image_url']);

            $formData['user_id'] = $request->user()->id;

            $tags = null;
            if (! empty($formData['tags'])) {
                $tags = $formData['tags'];
            }
            unset($formData['tags']);

            $post = $this->postModel::create($formData);

            if (! empty($tags)) {
                $tagIds = collect($tags)->map(function ($tag) {
                    return $this->tagModel::firstOrCreate(
                        ['slug' => Str::slug($tag)],
                        ['name' => $tag]
                    )->id;
                });
                $post->tags()->sync($tagIds);
            }

            DB::commit();

            $post->load('tags', 'category', 'author');

            return sendSuccess('Post created successfully', compact('post'), 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Creating Blog Post Error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong.");
        }
    }

    public function getPost($id)
    {
        $post = $this->findOwnedPost($id, ['author', 'category', 'tags']);
        return sendSuccess('Post fetched successfully', compact('post'));
    }

    public function update($request, $id)
    {
        $post = $this->findOwnedPost($id);

        $formData = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|unique:posts,slug,' . $post->id,
            'excerpt'          => 'nullable|string|max:500',
            'content'          => 'nullable|string',
            'read_time'        => 'nullable|string|max:50',
            'status'           => 'required|in:draft,published',
            'type'             => 'nullable|in:' . implode(',', Post::TYPES),
            'post_category_id' => 'nullable|exists:post_categories,id',
            'featured_image'   => [
                'nullable',
                File::types([
                    'image/jpeg',
                    'image/png',
                    'image/gif',
                    'image/bmp',
                    'image/webp',
                    'image/avif',
                ])->max(5120 * 1024),
            ],
            'image_url'        => 'nullable|url',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'seo_title'        => 'nullable|string|max:255',
            'seo_description'  => 'nullable|string|max:160',
            'featured'         => 'nullable',
        ]);

        try {
            DB::beginTransaction();

            $formData['type'] = $formData['type'] ?? $post->type ?? Post::TYPE_MAIN;
            if (empty($formData['read_time'])) {
                $formData['read_time'] = $post->read_time ?: '5 min read';
            }

            if ($formData['status'] == $this->postModel::PUBLISHED && $post->published_at == null) {
                $formData['published_at'] = now();
            }

            if (! empty($formData['featured'])) {
                $formData['featured'] = true;
                $this->postModel->where('type', $formData['type'])->where('id', '!=', $post->id)->update(['featured' => false]);
            } else {
                $formData['featured'] = false;
            }

            if (empty($formData['slug'])) {
                $formData['slug'] = Str::slug($formData['title']);
            }

            if ($request->hasFile('featured_image')) {
                $formData['featured_image'] = $request->file('featured_image')->store('posts', 'public');
            } elseif ($request->filled('image_url')) {
                $formData['featured_image'] = $request->input('image_url');
            } else {
                unset($formData['featured_image']);
            }
            unset($formData['image_url']);

            $tags = array_key_exists('tags', $formData) ? ($formData['tags'] ?? []) : null;
            unset($formData['tags']);

            $post->update($formData);

            if ($tags !== null) {
                $tagIds = collect($tags)->map(function ($tag) {
                    return $this->tagModel::firstOrCreate(
                        ['slug' => Str::slug($tag)],
                        ['name' => $tag]
                    )->id;
                });
                $post->tags()->sync($tagIds);
            }

            $post->refresh();
            $post->load('tags', 'category', 'author');

            DB::commit();

            return sendSuccess('Post updated successfully', compact('post'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Updating Blog Post Error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong.");
        }
    }

    public function delete($id)
    {
        $post = $this->findOwnedPost($id);
        $post->delete();
        return sendSuccess('Post deleted successfully');
    }

    public function changeStatus($request)
    {
        $request->validate([
            'id'     => 'required|numeric|exists:posts',
            'status' => 'required|string|in:draft,published',
        ]);

        $post = $this->findOwnedPost($request->id);

        if ($request->status == $this->postModel::PUBLISHED) {
            $post->status       = $this->postModel::PUBLISHED;
            $post->published_at = now();
            $post->save();
        } elseif ($request->status == $this->postModel::DRAFT) {
            $post->published_at = null;
            $post->status       = $this->postModel::DRAFT;
            $post->save();
        }

        return sendSuccess('Post status updated successfully', compact('post'));
    }

    public function blogArticles($request)
    {
        $search           = $request->search ?? null;
        $category         = $request->category ?? null;
        $records_per_page = $request->records_per_page ?? 10;
        $blog             = $this->resolveBlogOwner($request);
        $type             = $this->resolvePostType($request->type);

        if ($blog['unknown']) {
            return sendSuccess('Blog articles fetched successfully', [
                'blogArticles'    => $this->postModel->whereRaw('1 = 0')->simplePaginate($records_per_page),
                'featuredArticle' => null,
            ]);
        }

        $ownerId = $blog['owner'];

        $blogArticles = $this->postModel->published()
            ->forOwner($ownerId)
            // A provider has one blog; only the platform blogs split by type.
            ->when(! $ownerId, fn ($q) => $q->where('type', $type))
            ->with(['author', 'category', 'tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'LIKE', "%{$search}%")
                        ->orWhere('slug', 'LIKE', "%{$search}%")
                        ->orWhere('excerpt', 'LIKE', "%{$search}%")
                        ->orWhere('content', 'LIKE', "%{$search}%")
                        ->orWhere('seo_title', 'LIKE', "%{$search}%")
                        ->orWhere('published_at', 'LIKE', "%{$search}%");
                });
            })
            ->when($category, function ($query, $category) {
                $query->whereRelation('category', 'name', 'LIKE', $category);
            })
            ->latest('published_at')
            ->simplePaginate($records_per_page);

        $featuredArticle = $this->postModel::with(['author', 'category', 'tags'])
            ->published()
            ->forOwner($ownerId)
            ->when(! $ownerId, fn ($q) => $q->where('type', $type))
            ->featured()
            ->first();

        return sendSuccess('Blog articles fetched successfully', compact('blogArticles', 'featuredArticle'));
    }

    public function getPostBySlug($slug, $request = null)
    {
        $blog = $this->resolveBlogOwner($request);
        if ($blog['unknown']) {
            return sendError('Post not found', [], 404);
        }

        $ownerId = $blog['owner'];
        $type = $this->resolvePostType($request?->type);

        $post = $this->postModel->where('slug', $slug)
            ->published()
            ->forOwner($ownerId)
            ->when(! $ownerId, fn ($q) => $q->where('type', $type))
            ->with(['author', 'category', 'tags'])
            ->first();

        if (! $post) {
            return sendError('Post not found', [], 404);
        }

        return sendSuccess('Post fetched successfully', compact('post'));
    }

    /**
     * The blog the signed-in admin manages: null for Bookboll staff (the platform
     * blogs), otherwise the provider they belong to. Team members inherit their
     * owner's blog.
     */
    protected function adminBlogOwner(): ?int
    {
        $user = auth()->user();
        if (! $user || $user->hasRole(['super_admin', 'country_admin', 'platform_staff', 'admin'])) {
            return null;
        }

        return (int) ($user->owner_user_id ?: $user->id);
    }

    /** Load a post the signed-in admin is allowed to touch. */
    protected function findOwnedPost($id, array $with = []): Post
    {
        return $this->postModel::query()
            ->when($with, fn ($q) => $q->with($with))
            ->forOwner($this->adminBlogOwner())
            ->findOrFail($id);
    }

    /**
     * Whose blog the public request is asking for: a white-label brand's own
     * posts (?brand_slug=), or null for the Bookboll platform blogs.
     */
    protected function resolveBlogOwner($request): array
    {
        $slug = strtolower(trim((string) ($request?->brand_slug ?? '')));
        if ($slug === '') {
            return ['owner' => null, 'unknown' => false];
        }

        $businessId = Business::query()
            ->where(function ($q) use ($slug) {
                $q->whereRaw('LOWER(username) = ?', [$slug])
                    ->orWhereRaw('LOWER(brand_subdomain) = ?', [$slug]);
            })
            ->value('id');

        // Posts are keyed by their author's owner id. An ownerless business has
        // no owner_id column to read, so resolve the member who runs it —
        // otherwise its blog read as an unknown brand and showed nothing.
        $ownerId = \App\Support\BusinessMembershipSync::ownerUserIdForBusiness(
            $businessId ? (int) $businessId : null
        );

        // A brand was asked for but doesn't exist — show nothing rather than
        // leaking the Bookboll blog onto an unknown subdomain.
        return ['owner' => $ownerId, 'unknown' => ! $ownerId];
    }

    protected function resolvePostType(?string $type): string
    {
        return in_array($type, Post::TYPES, true) ? $type : Post::TYPE_MAIN;
    }
}
