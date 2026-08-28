<?php

namespace Tests\Unit\Policies;

use App\Models\Comment;
use App\Models\User;
use App\Policies\CommentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommentPolicyTest extends TestCase
{
    use RefreshDatabase;

    private CommentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new CommentPolicy;
    }

    public function test_view_any_view_and_create_are_always_allowed(): void
    {
        $user = User::factory()->create();
        $comment = Comment::factory()->create();

        $this->assertTrue($this->policy->viewAny($user));
        $this->assertTrue($this->policy->view($user, $comment));
        $this->assertTrue($this->policy->create($user));
    }

    public function test_update_and_delete_only_for_owner_of_unpublished_comment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['level' => 5]);
        $draft = Comment::factory()->unpublished()->create(['user_id' => $owner->id]);
        $published = Comment::factory()->published()->create(['user_id' => $owner->id]);

        $this->assertTrue($this->policy->update($owner, $draft));
        $this->assertTrue($this->policy->delete($owner, $draft));
        $this->assertFalse($this->policy->update($other, $draft));
        $this->assertFalse($this->policy->delete($other, $draft));
        $this->assertFalse($this->policy->update($owner, $published));
        $this->assertFalse($this->policy->delete($owner, $published));
    }

    public function test_admin_cannot_update_or_delete_others_draft(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create(['level' => 5]);
        $draft = Comment::factory()->unpublished()->create(['user_id' => $owner->id]);

        $this->assertFalse($this->policy->update($admin, $draft));
        $this->assertFalse($this->policy->delete($admin, $draft));
    }

    #[DataProvider('publishScenarios')]
    public function test_publish_rules(
        int $actorLevel,
        int $ownerLevel,
        bool $sameUser,
        bool $alreadyPublished,
        bool $expected
    ): void {
        $owner = User::factory()->create(['level' => $ownerLevel]);
        $actor = $sameUser ? $owner : User::factory()->create(['level' => $actorLevel]);
        $comment = $alreadyPublished
            ? Comment::factory()->published()->create(['user_id' => $owner->id])
            : Comment::factory()->unpublished()->create(['user_id' => $owner->id]);

        $this->assertSame($expected, (bool) $this->policy->publish($actor, $comment));
    }

    public static function publishScenarios(): array
    {
        // CommentPolicy returns true for any level >= 2 before ownership/level checks.
        return [
            'owner level 5 can publish own' => [5, 5, true, false, true],
            'level 2 can publish any unpublished' => [2, 1, false, false, true],
            'level 2 can publish higher author' => [2, 10, false, false, true],
            'level 1 cannot publish' => [1, 1, false, false, false],
            'level 1 owner cannot publish own' => [1, 1, true, false, false],
            'same level non-owner level 3 can' => [3, 3, false, false, true],
            'already published blocked' => [5, 1, false, true, false],
        ];
    }
}
