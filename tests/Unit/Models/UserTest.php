<?php

namespace Tests\Unit\Models;

use App\Models\Answer;
use App\Models\AnswerCorrectnessMark;
use App\Models\Comment;
use App\Models\Question;
use App\Models\User;
use App\Models\UserFeaturedQuestion;
use App\Models\Verification;
use App\Models\Vote;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_level_name_for_all_defined_levels(): void
    {
        $expectedNames = [
            1 => 'شهروند',
            2 => 'خبرنگار',
            3 => 'مشارکت کننده',
            4 => 'توسعه دهنده',
            5 => 'بازرس',
            6 => 'تاجر',
            7 => 'وکیل',
            8 => 'شورای شهر',
            9 => 'شهردار',
            10 => 'فرماندار',
            11 => 'وزیر',
            12 => 'قاضی',
            13 => 'قانون گذار',
        ];

        foreach ($expectedNames as $level => $name) {
            $user = User::factory()->create(['level' => $level]);
            $this->assertSame($name, $user->level_name, "Level {$level} should be '{$name}'");
        }
    }

    public function test_unknown_level_returns_nameshnas(): void
    {
        $user = User::factory()->create(['level' => 99]);
        $this->assertSame('نامشخص', $user->level_name);
    }

    public function test_is_admin_returns_true_for_admin_role(): void
    {
        $admin = User::factory()->admin()->create();
        $this->assertTrue($admin->isAdmin());
    }

    public function test_is_admin_returns_false_for_user_role(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->assertFalse($user->isAdmin());
    }

    public function test_image_url_is_null_when_no_image(): void
    {
        $user = User::factory()->create(['image' => null]);
        $this->assertNull($user->image_url);
    }

    public function test_image_url_returns_asset_url_when_image_set(): void
    {
        $user = User::factory()->create(['image' => 'avatars/test.jpg']);
        $this->assertStringContainsString('avatars/test.jpg', $user->image_url);
        $this->assertStringContainsString('storage', $user->image_url);
    }

    public function test_questions_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->questions());
    }

    public function test_questions_relation_works_with_factory(): void
    {
        $user = User::factory()->create();
        Question::factory()->count(3)->create(['user_id' => $user->id]);

        $this->assertCount(3, $user->questions);
    }

    public function test_published_questions_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->publishedQuestions());
    }

    public function test_published_questions_relation_uses_published_by_key(): void
    {
        $publisher = User::factory()->create();
        Question::factory()->published($publisher)->count(2)->create();

        $this->assertCount(2, $publisher->publishedQuestions);
    }

    public function test_answers_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->answers());
    }

    public function test_answers_relation_works_with_factory(): void
    {
        $user = User::factory()->create();
        Answer::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->answers);
    }

    public function test_published_answers_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->publishedAnswers());
    }

    public function test_comments_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->comments());
    }

    public function test_comments_relation_works_with_factory(): void
    {
        $user = User::factory()->create();
        Comment::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertCount(2, $user->comments);
    }

    public function test_published_comments_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->publishedComments());
    }

    public function test_votes_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->votes());
    }

    public function test_votes_relation_works_with_factory(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();
        Vote::factory()->create([
            'user_id' => $user->id,
            'votable_type' => Question::class,
            'votable_id' => $question->id,
        ]);

        $this->assertCount(1, $user->votes);
    }

    public function test_verifications_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->verifications());
    }

    public function test_verifications_relation_works_with_factory(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();
        Verification::create([
            'user_id' => $user->id,
            'verifiable_type' => Question::class,
            'verifiable_id' => $question->id,
        ]);

        $this->assertCount(1, $user->verifications);
    }

    public function test_correctness_marks_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->correctnessMarks());
    }

    public function test_pinned_questions_relation_returns_belongs_to_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(BelongsToMany::class, $user->pinnedQuestions());
    }

    public function test_featured_questions_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->featuredQuestions());
    }

    public function test_featured_questions_filters_by_featured_type(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();

        UserFeaturedQuestion::factory()->create([
            'user_id' => $user->id,
            'question_id' => $question->id,
            'type' => 'featured',
        ]);
        UserFeaturedQuestion::factory()->create([
            'user_id' => $user->id,
            'question_id' => Question::factory()->published()->create()->id,
            'type' => 'unfeatured',
        ]);

        $this->assertCount(1, $user->featuredQuestions);
    }

    public function test_unfeatured_questions_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->unfeaturedQuestions());
    }

    public function test_unfeatured_questions_filters_by_unfeatured_type(): void
    {
        $user = User::factory()->create();
        $question = Question::factory()->published()->create();

        UserFeaturedQuestion::factory()->create([
            'user_id' => $user->id,
            'question_id' => $question->id,
            'type' => 'unfeatured',
        ]);

        $this->assertCount(1, $user->unfeaturedQuestions);
        $this->assertCount(0, $user->featuredQuestions);
    }

    public function test_marked_as_correct_answers_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->markedAsCorrectAnswers());
    }

    public function test_marked_as_correct_answers_filters_is_correct_true(): void
    {
        $user = User::factory()->create();
        $answer = Answer::factory()->published()->create();

        AnswerCorrectnessMark::factory()->create([
            'marker_user_id' => $user->id,
            'answer_id' => $answer->id,
            'is_correct' => true,
        ]);
        AnswerCorrectnessMark::factory()->create([
            'marker_user_id' => $user->id,
            'answer_id' => Answer::factory()->published()->create()->id,
            'is_correct' => false,
        ]);

        $this->assertCount(1, $user->markedAsCorrectAnswers);
    }

    public function test_marked_as_normal_answers_relation_returns_has_many(): void
    {
        $user = User::factory()->create();
        $this->assertInstanceOf(HasMany::class, $user->markedAsNormalAnswers());
    }

    public function test_marked_as_normal_answers_filters_is_correct_false(): void
    {
        $user = User::factory()->create();
        $answer = Answer::factory()->published()->create();

        AnswerCorrectnessMark::factory()->create([
            'marker_user_id' => $user->id,
            'answer_id' => $answer->id,
            'is_correct' => false,
        ]);

        $this->assertCount(1, $user->markedAsNormalAnswers);
        $this->assertCount(0, $user->markedAsCorrectAnswers);
    }
}
