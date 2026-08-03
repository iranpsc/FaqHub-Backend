<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Use preloaded user_vote if available, otherwise fall back to query
        $userVote = $this->getUserVote($request);

        // Use preloaded is_solved if available, otherwise fall back to method
        $isSolved = $this->getIsSolved();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'content' => $this->content,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'published' => $this->published,
            'published_at' => $this->published_at,
            'published_by' => $this->published_by,
            'user' => new UserResource($this->whenLoaded('user')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
            'answers_count' => $this->whenCounted('answers'),
            'unpublished_answers_count' => isset($this->resource->unpublished_answers_count)
                ? (int) $this->resource->unpublished_answers_count
                : 0,
            'comments_count' => $this->whenCounted('comments', 0),
            'unpublished_comments_count' => isset($this->resource->unpublished_comments_count)
                ? (int) $this->resource->unpublished_comments_count
                : 0,
            'votes_count' => $this->whenCounted('votes'),
            'votes' => [
                'upvotes' => $this->whenLoaded('upVotes'),
                'downvotes' => $this->whenLoaded('downVotes'),
                'user_vote' => $userVote,
            ],
            'views' => $this->views,
            'is_solved' => $isSolved,
            'is_pinned_by_user' => (bool) ($this->is_pinned_by_user ?? false),
            'pinned_at' => $this->pinned_at ? $this->pinned_at : null,
            'is_featured_by_user' => (bool) ($this->is_featured_by_user ?? false),
            'featured_at' => $this->featured_at ? $this->featured_at : null,
            'answers' => AnswerResource::collection($this->whenLoaded('answers')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'can' => [
                'view' => $request->user()?->can('view', $this->resource) ?? false,
                'publish' => $request->user()?->can('publish', $this->resource) ?? false,
                'feature' => $request->user()?->can('feature', $this->resource) ?? false,
                'unfeature' => $request->user()?->can('unfeature', $this->resource) ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * Get user vote - use preloaded value if available, otherwise query
     */
    private function getUserVote(Request $request): ?string
    {
        // Check if user_vote was preloaded via subquery
        if (isset($this->resource->user_vote)) {
            return $this->resource->user_vote;
        }

        // Fall back to query for non-optimized calls (e.g., show method)
        if ($request->user()) {
            $userVoteRecord = $this->votes()->where('user_id', $request->user()->id)->first();

            return $userVoteRecord ? $userVoteRecord->type : null;
        }

        return null;
    }

    /**
     * Get is_solved - use preloaded value if available, otherwise call method
     */
    private function getIsSolved(): bool
    {
        // Check if is_solved was preloaded via subquery
        if (isset($this->resource->is_solved)) {
            return (bool) $this->resource->is_solved;
        }

        // Fall back to method for non-optimized calls (e.g., show method)
        return $this->isSolved();
    }
}
