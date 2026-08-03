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
                'upvotes' => $this->votes->where('type', 'up')->count(),
                'downvotes' => $this->votes->where('type', 'down')->count(),
                'user_vote' => $this->votes->where('user_id', $request->user()?->id)->first()?->type,
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
}
