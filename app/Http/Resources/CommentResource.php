<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array|Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $upvotes = $this->voteCount('up');
        $downvotes = $this->voteCount('down');
        $userVote = $this->resource->user_vote ?? $this->loadedUserVote($request);

        return [
            'id' => $this->id,
            'content' => $this->content,
            'published' => $this->published,
            'published_at' => $this->published_at,
            'published_by' => $this->published_by,
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at->diffForHumans(),
            'updated_at' => $this->updated_at->diffForHumans(),
            'votes' => [
                'upvotes' => $upvotes,
                'downvotes' => $downvotes,
                'score' => $upvotes - $downvotes,
                'user_vote' => $userVote,
            ],
            'can' => [
                'update' => $request->user() ? $request->user()->can('update', $this->resource) : false,
                'delete' => $request->user() ? $request->user()->can('delete', $this->resource) : false,
                'publish' => $request->user() ? $request->user()->can('publish', $this->resource) : false,
            ],
        ];
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

    private function loadedUserVote($request): ?string
    {
        if (! $this->relationLoaded('votes') || ! $request->user()) {
            return null;
        }

        return $this->votes->firstWhere('user_id', $request->user()->id)?->type;
    }
}
