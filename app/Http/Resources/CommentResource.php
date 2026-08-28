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
        $upvotes = $this->votes->where('type', 'up')->count();
        $downvotes = $this->votes->where('type', 'down')->count();
        $userVote = null;

        if ($request->user()) {
            $userVoteRecord = $this->votes->where('user_id', $request->user()->id)->first();
            $userVote = $userVoteRecord ? ($userVoteRecord->type === 'up' ? 'up' : 'down') : null;
        }

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
}
