<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->when($this->handleVisibility($request, $this->email), $this->email),
            'mobile' => $this->when($this->handleVisibility($request, $this->mobile), $this->mobile),
            'code' => $this->code,
            'level' => $this->level,
            'score' => $this->score,
            'image' => $this->image_url,
        ];
    }

    private function handleVisibility(Request $request, $value)
    {
        if (! $request->user()) {
            return false;
        }

        return $request->user()->id === $this->id;
    }
}
