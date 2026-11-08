<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
            // List views only need a short preview. Keeping full HTML out of the
            // homepage response avoids serializing large editor contents into the
            // initial Next.js document; detail views retain the full content.
            'content' => $request->boolean('summary')
                ? Str::limit(trim(strip_tags((string) $this->content)), 300)
                : $this->content,
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
                'upvotes' => $this->voteCount('up'),
                'downvotes' => $this->voteCount('down'),
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

    /**
     * Return a precomputed vote count without loading every Vote model.
     */
    private function voteCount(string $type): int
    {
        $attribute = $type === 'up' ? 'upvotes_count' : 'downvotes_count';
        $eloquentAttribute = $type === 'up' ? 'up_votes_count' : 'down_votes_count';

        if (isset($this->resource->{$attribute})) {
            return (int) $this->resource->{$attribute};
        }

        if (isset($this->resource->{$eloquentAttribute})) {
            return (int) $this->resource->{$eloquentAttribute};
        }

        $relation = $type === 'up' ? 'upVotes' : 'downVotes';

        return $this->relationLoaded($relation) ? $this->{$relation}->count() : 0;
    }
}
