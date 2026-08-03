<?php

namespace Tests\Unit\Policies;

use App\Models\Question;
use App\Models\User;
use App\Policies\QuestionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private QuestionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new QuestionPolicy;
    }

    public function test_view_any_allows_guests_and_users(): void
    {
        $this->assertTrue($this->policy->viewAny(null));
        $this->assertTrue($this->policy->viewAny(User::factory()->create()));
    }

    public function test_view_published_allows_guests(): void
    {
        $question = Question::factory()->published()->create();

        $this->assertTrue($this->policy->view(null, $question));
    }

    public function test_view_unpublished_allows_owner_and_higher_level_only(): void
    {
        $owner = User::factory()->create(['level' => 2]);
        $peer = User::factory()->create(['level' => 2]);
        $higher = User::factory()->create(['level' => 3]);
        $question = Question::factory()->unpublished()->create(['user_id' => $owner->id]);

        $this->assertTrue($this->policy->view($owner, $question));
        $this->assertFalse($this->policy->view($peer, $question));
        $this->assertTrue($this->policy->view($higher, $question));
        $this->assertFalse($this->policy->view(null, $question));
    }

    public function test_create_always_allows_authenticated_users(): void
    {
        $this->assertTrue($this->policy->create(User::factory()->create()));
    }

    public function test_update_and_delete_only_for_owner_of_unpublished_question(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create(['level' => 5]);
        $draft = Question::factory()->unpublished()->create(['user_id' => $owner->id]);
        $published = Question::factory()->published()->create(['user_id' => $owner->id]);

        $this->assertTrue($this->policy->update($owner, $draft));
        $this->assertTrue($this->policy->delete($owner, $draft));
        $this->assertFalse($this->policy->update($other, $draft));
        $this->assertFalse($this->policy->delete($other, $draft));
        $this->assertFalse($this->policy->update($owner, $published));
        $this->assertFalse($this->policy->delete($owner, $published));
    }

    #[DataProvider('publishScenarios')]
    public function test_publish_rules(int $actorLevel, int $ownerLevel, bool $sameUser, bool $alreadyPublished, bool $expected): void
    {
        $owner = User::factory()->create(['level' => $ownerLevel]);
        $actor = $sameUser ? $owner : User::factory()->create(['level' => $actorLevel]);
        $question = $alreadyPublished
            ? Question::factory()->published()->create(['user_id' => $owner->id])
            : Question::factory()->unpublished()->create(['user_id' => $owner->id]);

        $this->assertSame($expected, $this->policy->publish($actor, $question));
    }

    public static function publishScenarios(): array
    {
        return [
            'owner level 2 can publish own' => [2, 2, true, false, true],
            'owner level 1 cannot publish own' => [1, 1, true, false, false],
            'higher level can publish lower' => [3, 1, false, false, true],
            'same level non-owner cannot' => [2, 2, false, false, false],
            'lower level cannot publish higher' => [2, 3, false, false, false],
            'already published blocked' => [5, 1, false, true, false],
        ];
    }

    public function test_feature_requires_level_four_published_not_own_under_limit(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 4]);
        $question = Question::factory()->published()->create(['user_id' => $author->id]);

        $this->assertTrue($this->policy->feature($actor, $question));
        $this->assertFalse($this->policy->feature($author, $question));
        $this->assertFalse($this->policy->feature(
            User::factory()->create(['level' => 3]),
            $question
        ));
    }

    public function test_unfeature_requires_featured_published_not_own(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 4]);
        $featured = Question::factory()->published()->featured()->create(['user_id' => $author->id]);
        $notFeatured = Question::factory()->published()->create(['user_id' => $author->id, 'featured' => false]);

        $this->assertTrue($this->policy->unfeature($actor, $featured));
        $this->assertFalse($this->policy->unfeature($actor, $notFeatured));
        $this->assertFalse($this->policy->unfeature($author, $featured));
    }

    public function test_delete_feature_unfeature_deny_when_question_user_is_null(): void
    {
        $actor = User::factory()->create(['level' => 5]);
        $question = Question::factory()->unpublished()->create();
        $question->setRelation('user', null);

        $this->assertFalse($this->policy->delete($actor, $question));
        $this->assertFalse($this->policy->feature($actor, $question));
        $this->assertFalse($this->policy->unfeature($actor, $question));
    }

    public function test_feature_denied_when_already_featured_or_limit_reached(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $question = Question::factory()->published()->create(['user_id' => $author->id]);

        $actor->featuredQuestions()->create([
            'question_id' => $question->id,
            'type' => 'featured',
            'featured_at' => now(),
        ]);
        $this->assertFalse($this->policy->feature($actor, $question));

        $actor2 = User::factory()->create(['level' => 5]);
        $q1 = Question::factory()->published()->create(['user_id' => $author->id]);
        $q2 = Question::factory()->published()->create(['user_id' => $author->id]);
        $q3 = Question::factory()->published()->create(['user_id' => $author->id]);
        $actor2->featuredQuestions()->create(['question_id' => $q1->id, 'type' => 'featured', 'featured_at' => now()]);
        $actor2->featuredQuestions()->create(['question_id' => $q2->id, 'type' => 'featured', 'featured_at' => now()]);
        $this->assertFalse($this->policy->feature($actor2, $q3));
    }

    public function test_unfeature_denied_when_already_unfeatured_or_limit_reached(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $featured = Question::factory()->published()->featured()->create(['user_id' => $author->id]);

        $actor->unfeaturedQuestions()->create([
            'question_id' => $featured->id,
            'type' => 'unfeatured',
            'featured_at' => now(),
        ]);
        $this->assertFalse($this->policy->unfeature($actor, $featured));

        $actor2 = User::factory()->create(['level' => 5]);
        $f1 = Question::factory()->published()->featured()->create(['user_id' => $author->id]);
        $f2 = Question::factory()->published()->featured()->create(['user_id' => $author->id]);
        $f3 = Question::factory()->published()->featured()->create(['user_id' => $author->id]);
        $actor2->unfeaturedQuestions()->create(['question_id' => $f1->id, 'type' => 'unfeatured', 'featured_at' => now()]);
        $actor2->unfeaturedQuestions()->create(['question_id' => $f2->id, 'type' => 'unfeatured', 'featured_at' => now()]);
        $this->assertFalse($this->policy->unfeature($actor2, $f3));
    }

    public function test_unfeature_denied_for_unpublished_featured_question(): void
    {
        $author = User::factory()->create(['level' => 1]);
        $actor = User::factory()->create(['level' => 5]);
        $question = Question::factory()->unpublished()->create([
            'user_id' => $author->id,
            'featured' => true,
        ]);

        $this->assertFalse($this->policy->unfeature($actor, $question));
    }
}
