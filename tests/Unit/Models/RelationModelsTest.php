<?php

namespace Tests\Unit\Models;

use App\Models\Answer;
use App\Models\AnswerCorrectnessMark;
use App\Models\Category;
use App\Models\Question;
use App\Models\User;
use App\Models\UserFeaturedQuestion;
use App\Models\Verification;
use App\Models\Vote;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationModelsTest extends TestCase
{
    use RefreshDatabase;

    // ── Verification ─────────────────────────────────────────────────────────

    public function test_verification_user_relation_returns_belongs_to(): void
    {
        $verification = new Verification;
        $this->assertInstanceOf(BelongsTo::class, $verification->user());
    }

    public function test_verification_verifiable_relation_returns_morph_to(): void
    {
        $verification = new Verification;
        $this->assertInstanceOf(MorphTo::class, $verification->verifiable());
    }

    public function test_verification_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();

        $verification = Verification::create([
            'user_id' => $user->id,
            'verifiable_type' => Question::class,
            'verifiable_id' => $question->id,
        ]);

        $this->assertSame($user->id, $verification->user->id);
    }

    public function test_verification_verifiable_resolves_to_question(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();

        $verification = Verification::create([
            'user_id' => $user->id,
            'verifiable_type' => Question::class,
            'verifiable_id' => $question->id,
        ]);

        $this->assertInstanceOf(Question::class, $verification->verifiable);
        $this->assertSame($question->id, $verification->verifiable->id);
    }

    public function test_verification_can_be_created_via_factory(): void
    {
        $verification = Verification::factory()->create([
            'verifiable_type' => Question::class,
            'verifiable_id' => Question::factory()->published()->create()->id,
        ]);

        $this->assertNotNull($verification->id);
        $this->assertNotNull($verification->user);
    }

    // ── Vote ─────────────────────────────────────────────────────────────────

    public function test_vote_user_relation_returns_belongs_to(): void
    {
        $vote = new Vote;
        $this->assertInstanceOf(BelongsTo::class, $vote->user());
    }

    public function test_vote_votable_relation_returns_morph_to(): void
    {
        $vote = new Vote;
        $this->assertInstanceOf(MorphTo::class, $vote->votable());
    }

    public function test_vote_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();

        $vote = Vote::create([
            'user_id' => $user->id,
            'votable_type' => Question::class,
            'votable_id' => $question->id,
            'type' => 'up',
        ]);

        $this->assertSame($user->id, $vote->user->id);
    }

    public function test_vote_votable_resolves_to_question(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();

        $vote = Vote::create([
            'user_id' => $user->id,
            'votable_type' => Question::class,
            'votable_id' => $question->id,
            'type' => 'down',
        ]);

        $this->assertInstanceOf(Question::class, $vote->votable);
        $this->assertSame($question->id, $vote->votable->id);
    }

    public function test_vote_can_be_created_via_factory(): void
    {
        $vote = Vote::factory()->create([
            'votable_type' => Question::class,
            'votable_id' => Question::factory()->published()->create()->id,
        ]);

        $this->assertNotNull($vote->id);
        $this->assertNotNull($vote->user);
    }

    // ── AnswerCorrectnessMark ─────────────────────────────────────────────────

    public function test_answer_correctness_mark_answer_relation_returns_belongs_to(): void
    {
        $mark = new AnswerCorrectnessMark;
        $this->assertInstanceOf(BelongsTo::class, $mark->answer());
    }

    public function test_answer_correctness_mark_marker_relation_returns_belongs_to(): void
    {
        $mark = new AnswerCorrectnessMark;
        $this->assertInstanceOf(BelongsTo::class, $mark->marker());
    }

    public function test_answer_correctness_mark_belongs_to_answer(): void
    {
        $answer = Answer::factory()->published()->create();
        $user = User::factory()->create();

        $mark = AnswerCorrectnessMark::factory()->create([
            'answer_id' => $answer->id,
            'marker_user_id' => $user->id,
            'is_correct' => true,
        ]);

        $this->assertSame($answer->id, $mark->answer->id);
    }

    public function test_answer_correctness_mark_belongs_to_marker_user(): void
    {
        $answer = Answer::factory()->published()->create();
        $user = User::factory()->create();

        $mark = AnswerCorrectnessMark::factory()->create([
            'answer_id' => $answer->id,
            'marker_user_id' => $user->id,
            'is_correct' => false,
        ]);

        $this->assertSame($user->id, $mark->marker->id);
    }

    public function test_is_correct_cast_to_boolean(): void
    {
        $mark = AnswerCorrectnessMark::factory()->correct()->create();
        $this->assertTrue($mark->is_correct);

        $mark2 = AnswerCorrectnessMark::factory()->incorrect()->create();
        $this->assertFalse($mark2->is_correct);
    }

    // ── UserFeaturedQuestion ──────────────────────────────────────────────────

    public function test_user_featured_question_user_relation_returns_belongs_to(): void
    {
        $ufq = new UserFeaturedQuestion;
        $this->assertInstanceOf(BelongsTo::class, $ufq->user());
    }

    public function test_user_featured_question_question_relation_returns_belongs_to(): void
    {
        $ufq = new UserFeaturedQuestion;
        $this->assertInstanceOf(BelongsTo::class, $ufq->question());
    }

    public function test_user_featured_question_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();

        $ufq = UserFeaturedQuestion::factory()->create([
            'user_id' => $user->id,
            'question_id' => $question->id,
            'type' => 'featured',
        ]);

        $this->assertSame($user->id, $ufq->user->id);
    }

    public function test_user_featured_question_belongs_to_question(): void
    {
        $question = Question::factory()->published()->create();

        $ufq = UserFeaturedQuestion::factory()->create([
            'question_id' => $question->id,
            'type' => 'unfeatured',
        ]);

        $this->assertSame($question->id, $ufq->question->id);
    }

    // ── Category ──────────────────────────────────────────────────────────────

    public function test_category_parent_relation_returns_belongs_to(): void
    {
        $category = new Category;
        $this->assertInstanceOf(BelongsTo::class, $category->parent());
    }

    public function test_category_children_relation_returns_has_many(): void
    {
        $category = new Category;
        $this->assertInstanceOf(HasMany::class, $category->children());
    }

    public function test_category_parent_child_relationship(): void
    {
        $parent = Category::factory()->create(['name' => 'Parent', 'slug' => 'parent']);
        $child = Category::factory()->create([
            'name' => 'Child',
            'slug' => 'child',
            'parent_id' => $parent->id,
        ]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertCount(1, $parent->children);
        $this->assertSame($child->id, $parent->children->first()->id);
    }

    public function test_category_without_parent_has_null_parent(): void
    {
        $category = Category::factory()->create(['parent_id' => null]);
        $this->assertNull($category->parent);
    }

    public function test_category_questions_relation_returns_has_many(): void
    {
        $category = new Category;
        $this->assertInstanceOf(HasMany::class, $category->questions());
    }

    public function test_category_questions_are_associated_correctly(): void
    {
        $category = Category::factory()->create();
        Question::factory()->published()->count(3)->create(['category_id' => $category->id]);

        $this->assertCount(3, $category->questions);
    }
}
