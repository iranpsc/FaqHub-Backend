<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchUserLevel implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private User $user
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $url = sprintf('https://api.metarang.com/api/users/%s/level', $this->user->email);

        $response = Http::get($url);

        if (! $response->successful()) {
            Log::error('Failed to fetch user level', [
                'email' => $this->user->email,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return;
        }

        $data = $response->json();
        $level = isset($data['level']['slug']) ? (int) $data['level']['slug'] : null;
        $score = isset($data['score']) ? (int) $data['score'] : 0;

        if ($level === null) {
            Log::warning('Level data missing in API response', [
                'email' => $this->user->email,
                'response' => $data,
            ]);

            return;
        }

        $previousLevel = $this->user->level;
        $this->user->forceFill(['level' => $level])->save();

        if ($level > $previousLevel) {
            $this->user->increment('score', $score);
        }
    }
}
