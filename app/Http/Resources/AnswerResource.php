<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnswerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'user_id' => $this->user_id,
            'content' => $this->content,
            'published' => $this->published,
            'published_at' => $this->published_at,
            'is_correct' => $this->is_correct,
            'created_at' => $this->created_at,
            'user' => new UserResource($this->whenLoaded('user')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'votes' => [
                'upvotes' => $this->voteCount('up'),
                'downvotes' => $this->voteCount('down'),
                'user_vote' => $this->resource->user_vote ?? $this->loadedUserVote($request),
            ],
            'can' => [
                'toggle_correctness' => $request->user()?->can('toggleCorrectness', [$this->resource, $this->getToggleAction()])
                    ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'publish' => $request->user()?->can('publish', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * Get the action for toggling correctness.
     *
     * @return string
     */
    private function getToggleAction()
    {
        return $this->is_correct ? 'markAsNormal' : 'markAsCorrect';
    }

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

    private function loadedUserVote(Request $request): ?string
    {
        if (! $this->relationLoaded('votes') || ! $request->user()) {
            return null;
        }

        return $this->votes->firstWhere('user_id', $request->user()->id)?->type;
    }
}
