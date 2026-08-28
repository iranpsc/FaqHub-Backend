<?php

namespace Tests\Unit\Notifications;

use App\Models\Question;
use App\Models\User;
use App\Notifications\QuestionInteractionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionInteractionNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_via_returns_mail_channel(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $question = Question::factory()->published()->create(['user_id' => $owner->id]);

        $notification = new QuestionInteractionNotification($actor, $question, 'answer');

        $this->assertSame(['mail'], $notification->via($owner));
    }

    public function test_to_mail_for_answer_type_uses_answer_text(): void
    {
        $actor = User::factory()->create(['name' => 'رضا احمدی']);
        $owner = User::factory()->create(['name' => 'سارا کریمی']);
        $question = Question::factory()->published()->create([
            'user_id' => $owner->id,
            'title' => 'سوال تستی',
            'slug' => 'question-test',
        ]);

        $notification = new QuestionInteractionNotification($actor, $question, 'answer');
        $mail = $notification->toMail($owner);

        // Check the subject contains the actor name and interaction type
        $subject = $mail->subject;
        $this->assertStringContainsString('رضا احمدی', $subject);
        $this->assertStringContainsString('پاسخ داد', $subject);
    }

    public function test_to_mail_for_comment_type_uses_comment_text(): void
    {
        $actor = User::factory()->create(['name' => 'محمد حسینی']);
        $owner = User::factory()->create(['name' => 'فاطمه علوی']);
        $question = Question::factory()->published()->create([
            'user_id' => $owner->id,
            'title' => 'سوال نظر',
            'slug' => 'question-comment',
        ]);

        $notification = new QuestionInteractionNotification($actor, $question, 'comment');
        $mail = $notification->toMail($owner);

        $subject = $mail->subject;
        $this->assertStringContainsString('محمد حسینی', $subject);
        $this->assertStringContainsString('نظر داد', $subject);
    }

    public function test_to_mail_contains_question_title_in_lines(): void
    {
        $actor = User::factory()->create(['name' => 'Ali']);
        $owner = User::factory()->create(['name' => 'Sara']);
        $question = Question::factory()->published()->create([
            'user_id' => $owner->id,
            'title' => 'چگونه از لاراول استفاده کنیم',
            'slug' => 'how-to-use-laravel',
        ]);

        $notification = new QuestionInteractionNotification($actor, $question, 'answer');
        $mail = $notification->toMail($owner);

        // The mail lines contain the question title
        $introLines = $mail->introLines;
        $allLines = implode(' ', $introLines);
        $this->assertStringContainsString('چگونه از لاراول استفاده کنیم', $allLines);
    }

    public function test_to_array_returns_empty_array(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $question = Question::factory()->published()->create(['user_id' => $owner->id]);

        $notification = new QuestionInteractionNotification($actor, $question, 'answer');

        $this->assertSame([], $notification->toArray($owner));
    }

    public function test_notification_stores_correct_properties(): void
    {
        $actor = User::factory()->create();
        $owner = User::factory()->create();
        $question = Question::factory()->published()->create(['user_id' => $owner->id]);

        $notification = new QuestionInteractionNotification($actor, $question, 'comment');

        $this->assertSame($actor->id, $notification->user->id);
        $this->assertSame($question->id, $notification->question->id);
        $this->assertSame('comment', $notification->interactionType);
    }
}
