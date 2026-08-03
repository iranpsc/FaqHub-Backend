<?php

namespace Tests\Feature\Author;

use App\Models\Answer;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuthors;
use Tests\TestCase;

class AuthorShowTest extends TestCase
{
    use InteractsWithAuthors;
    use RefreshDatabase;

    public function test_guest_can_view_author_by_username(): void
    {
        $author = User::factory()->withImage('avatars/show.jpg')->create([
            'name' => 'Show Author',
            'username' => 'show-author',
            'score' => 88,
            'level' => 5,
            'role' => 'user',
        ]);

        $response = $this->getJson($this->authorShowUrl($author))->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $author->id)
            ->assertJsonPath('data.username', 'show-author')
            ->assertJsonPath('data.name', 'Show Author')
            ->assertJsonPath('data.score', 88)
            ->assertJsonPath('data.level', 5)
            ->assertJsonPath('data.role', 'user')
            ->assertJsonPath('data.image_url', asset('storage/avatars/show.jpg'))
            ->assertJsonPath('data.questions_count', 0)
            ->assertJsonPath('data.answers_count', 0)
            ->assertJsonPath('data.comments_count', 0)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'username',
                    'name',
                    'image_url',
                    'score',
                    'level',
                    'role',
                    'questions_count',
                    'answers_count',
                    'comments_count',
                    'created_at',
                ],
            ]);
    }

    public function test_show_returns_404_for_unknown_username(): void
    {
        $this->getJson($this->authorShowUrl('missing-user'))->assertNotFound();
    }

    public function test_show_resolves_by_username_not_numeric_id(): void
    {
        $author = $this->createAuthor(['username' => 'by-username', 'score' => 1]);

        $this->getJson('/api/authors/'.$author->id)->assertNotFound();
        $this->getJson($this->authorShowUrl($author))
            ->assertOk()
            ->assertJsonPath('data.id', $author->id);
    }

    public function test_show_counts_only_published_questions_answers_and_comments(): void
    {
        $author = $this->createAuthor(['username' => 'published-counts', 'score' => 1]);
        $question = $this->createPublishedQuestionFor($author);
        $this->createUnpublishedQuestionFor($author);

        Answer::factory()->published()->create([
            'user_id' => $author->id,
            'question_id' => $question->id,
        ]);
        Answer::factory()->unpublished()->create([
            'user_id' => $author->id,
            'question_id' => $question->id,
        ]);

        Comment::factory()->published()->forQuestion($question)->create([
            'user_id' => $author->id,
        ]);
        Comment::factory()->unpublished()->forQuestion($question)->create([
            'user_id' => $author->id,
        ]);

        $this->getJson($this->authorShowUrl($author))
            ->assertOk()
            ->assertJsonPath('data.questions_count', 1)
            ->assertJsonPath('data.answers_count', 1)
            ->assertJsonPath('data.comments_count', 1);
    }

    public function test_show_defaults_score_and_level_when_zeroish(): void
    {
        $author = $this->createAuthor([
            'username' => 'defaults',
            'score' => 0,
            'level' => 1,
            'image' => null,
        ]);

        $this->getJson($this->authorShowUrl($author))
            ->assertOk()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.level', 1)
            ->assertJsonPath('data.image_url', null);
    }

    public function test_show_exposes_admin_role_when_author_is_admin(): void
    {
        // Documents current behavior: role is included in public author show payload.
        $admin = User::factory()->admin()->create([
            'username' => 'admin-author',
            'score' => 10,
        ]);

        $this->getJson($this->authorShowUrl($admin))
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_authenticated_users_can_view_author_show(): void
    {
        $author = $this->createAuthor(['username' => 'visible', 'score' => 1]);

        $this->actingAsUser();
        $this->getJson($this->authorShowUrl($author))->assertOk();

        $this->actingAsAdmin();
        $this->getJson($this->authorShowUrl($author))->assertOk();
    }

    public function test_show_does_not_expose_email_tokens_or_mobile(): void
    {
        $author = $this->createAuthor([
            'username' => 'private-fields',
            'email' => 'private@example.com',
            'mobile' => '09121112233',
            'access_token' => 'show-access',
            'refresh_token' => 'show-refresh',
            'code' => '111222',
            'score' => 3,
        ]);

        $payload = $this->getJson($this->authorShowUrl($author))->assertOk()->json('data');
        $encoded = json_encode($this->getJson($this->authorShowUrl($author))->json());

        foreach (['email', 'mobile', 'access_token', 'refresh_token', 'code'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }

        $this->assertStringNotContainsString('private@example.com', $encoded);
        $this->assertStringNotContainsString('show-access', $encoded);
        $this->assertStringNotContainsString('show-refresh', $encoded);
        $this->assertStringNotContainsString('09121112233', $encoded);
        $this->assertStringNotContainsString('111222', $encoded);
    }

    public function test_show_does_not_include_recent_questions_or_total_activity(): void
    {
        $author = $this->createAuthor(['username' => 'shape-diff', 'score' => 1]);
        $this->createPublishedQuestionFor($author);

        $payload = $this->getJson($this->authorShowUrl($author))->assertOk()->json('data');

        $this->assertArrayNotHasKey('recent_questions', $payload);
        $this->assertArrayNotHasKey('total_activity', $payload);
    }

    #[DataProvider('usernameEdgeCasesProvider')]
    public function test_show_handles_username_edge_cases_with_404_or_ok(
        string $username,
        bool $create,
        int $expectedStatus
    ): void {
        if ($create) {
            $this->createAuthor(['username' => $username, 'score' => 1]);
        }

        $this->getJson($this->authorShowUrl($username))->assertStatus($expectedStatus);
    }

    public static function usernameEdgeCasesProvider(): array
    {
        return [
            'unicode username exists' => ['نویسنده', true, 200],
            'unicode username missing' => ['ناشناس', false, 404],
            'numeric looking username' => ['12345', true, 200],
            'dotted username' => ['user.name', true, 200],
        ];
    }
}
