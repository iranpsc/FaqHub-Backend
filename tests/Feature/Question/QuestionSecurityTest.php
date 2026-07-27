<?php

namespace Tests\Feature\Question;

use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionSecurityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithQuestions;

    public function test_mass_assignment_cannot_override_user_id_or_views_on_store(): void
    {
        $attacker = $this->actingAsLevel(1);
        $victim = User::factory()->create();

        $this->postJson('/api/questions', $this->makeQuestionPayload([
            'user_id' => $victim->id,
            'views' => 100000,
            'published' => true,
            'featured' => true,
            'slug' => 'attacker-chosen-slug',
        ]))->assertCreated();

        $question = Question::first();

        $this->assertEquals($attacker->id, $question->user_id);
        $this->assertEquals(0, $question->views);
        $this->assertFalse($question->published);
        $this->assertFalse($question->featured);
        $this->assertNotEquals('attacker-chosen-slug', $question->slug);
    }

    public function test_nested_user_resource_hides_email_and_mobile_from_other_users(): void
    {
        $author = User::factory()->create([
            'email' => 'author@example.com',
            'mobile' => '09120000000',
        ]);
        $viewer = User::factory()->create();
        $this->createPublishedQuestion([
            'user_id' => $author->id,
            'slug' => 'privacy-check',
        ]);

        Sanctum::actingAs($viewer);

        $userPayload = $this->getJson('/api/questions/privacy-check')
            ->assertOk()
            ->json('data.user');

        $this->assertArrayNotHasKey('email', $userPayload);
        $this->assertArrayNotHasKey('mobile', $userPayload);
        $this->assertArrayNotHasKey('access_token', $userPayload);
        $this->assertArrayNotHasKey('refresh_token', $userPayload);
    }

    public function test_author_seeing_own_question_receives_email_in_user_resource(): void
    {
        $author = User::factory()->create(['email' => 'me@example.com']);
        $this->createPublishedQuestion([
            'user_id' => $author->id,
            'slug' => 'own-privacy',
        ]);

        Sanctum::actingAs($author);

        $this->getJson('/api/questions/own-privacy')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'me@example.com');
    }

    public function test_sql_injection_in_category_filter_does_not_break_index(): void
    {
        $this->createPublishedQuestion();

        $this->getJson('/api/questions?category_id=1+OR+1%3D1')
            ->assertOk();
    }

    public function test_xss_payload_in_title_is_stripped_before_persistence(): void
    {
        $this->actingAsLevel(1);

        $this->postJson('/api/questions', $this->makeQuestionPayload([
            'title' => '<img src=x onerror=alert(1)>Hello',
        ]))->assertCreated();

        $title = Question::first()->title;
        $this->assertStringNotContainsString('onerror', $title);
        $this->assertStringContainsString('Hello', $title);
    }

    public function test_content_script_tags_are_persisted_as_sent_because_controller_skips_validated(): void
    {
        // Documents current behavior: StoreQuestionRequest::validated() sanitizes content,
        // but QuestionController reads $request->content directly.
        $this->actingAsLevel(1);
        $payload = $this->makeQuestionPayload([
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ]);

        $this->postJson('/api/questions', $payload)->assertCreated();

        $this->assertStringContainsString('<script>', Question::first()->content);
    }

    public function test_can_permissions_in_resource_reflect_policy_for_authenticated_user(): void
    {
        $owner = User::factory()->create(['level' => 1]);
        $this->createUnpublishedQuestion([
            'user_id' => $owner->id,
            'slug' => 'can-check',
        ]);

        Sanctum::actingAs($owner);

        $this->getJson('/api/questions/can-check')
            ->assertOk()
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', true)
            ->assertJsonPath('data.can.publish', false);
    }
}
