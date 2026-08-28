<?php

namespace Tests\Feature\Dashboard;

use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardRecommendedQuestionsTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    public function test_guest_can_list_recommended_questions_with_expected_shape(): void
    {
        $question = $this->createQuestionWithRelations();

        $response = $this->getJson('/api/questions/recommended');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'سوالات پیشنهادی با موفقیت دریافت شد')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $question->id)
            ->assertJsonPath('data.0.title', $question->title)
            ->assertJsonPath('data.0.slug', $question->slug)
            ->assertJsonPath('data.0.views_count', 42)
            ->assertJsonPath('data.0.user.name', 'Dashboard Author')
            ->assertJsonPath('data.0.category.name', 'Laravel')
            ->assertJsonPath('data.0.tags.0.name', 'Eloquent')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'slug',
                        'created_at',
                        'answers_count',
                        'votes_count',
                        'views_count',
                        'user' => ['id', 'name'],
                        'category',
                        'tags' => [
                            '*' => ['id', 'name'],
                        ],
                    ],
                ],
            ]);
    }

    public function test_recommended_defaults_to_fifteen_items(): void
    {
        Question::factory()->published()->count(20)->create();

        $this->getJson('/api/questions/recommended')
            ->assertOk()
            ->assertJsonCount(15, 'data');
    }

    public function test_recommended_respects_custom_limit(): void
    {
        Question::factory()->published()->count(10)->create();

        $this->getJson('/api/questions/recommended?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_recommended_excludes_unpublished_questions(): void
    {
        $published = Question::factory()->published()->create(['title' => 'Live Question']);
        Question::factory()->unpublished()->create(['title' => 'Draft Question']);

        $response = $this->getJson('/api/questions/recommended')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($published->id, $response->json('data.0.id'));
        $this->assertSame('Live Question', $response->json('data.0.title'));
    }

    public function test_recommended_returns_empty_data_when_no_published_questions_exist(): void
    {
        Question::factory()->unpublished()->count(3)->create();

        $this->getJson('/api/questions/recommended')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(0, 'data');
    }

    public function test_recommended_includes_answers_and_votes_counts(): void
    {
        $question = Question::factory()->published()->create();
        $this->createPublishedAnswer(['question_id' => $question->id]);
        $this->createPublishedAnswer(['question_id' => $question->id]);
        $this->attachVotes($question, 3);

        $this->getJson('/api/questions/recommended')
            ->assertOk()
            ->assertJsonPath('data.0.answers_count', 2)
            ->assertJsonPath('data.0.votes_count', 3);
    }

    public function test_recommended_handles_question_without_category_or_tags(): void
    {
        // category_id is required by schema via factory; detach tags and verify empty tags array
        $question = Question::factory()->published()->create();
        $question->tags()->detach();

        $this->getJson('/api/questions/recommended')
            ->assertOk()
            ->assertJsonPath('data.0.id', $question->id)
            ->assertJsonPath('data.0.tags', [])
            ->assertJsonPath('data.0.category.id', $question->category_id);
    }

    public function test_recommended_limit_larger_than_available_returns_all_published(): void
    {
        Question::factory()->published()->count(4)->create();

        $this->getJson('/api/questions/recommended?limit=50')
            ->assertOk()
            ->assertJsonCount(4, 'data');
    }

    public function test_recommended_route_is_not_captured_by_question_slug_show(): void
    {
        Question::factory()->published()->create(['slug' => 'something-else']);

        $this->getJson('/api/questions/recommended')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    public function test_authenticated_users_can_access_recommended(): void
    {
        Question::factory()->published()->create();

        $this->actingAsUser();
        $this->getJson('/api/questions/recommended')->assertOk();

        $this->actingAsAdmin();
        $this->getJson('/api/questions/recommended')->assertOk();
    }

    #[DataProvider('validLimitProvider')]
    public function test_recommended_accepts_valid_limits(int $limit, int $seedCount, int $expectedCount): void
    {
        Question::factory()->published()->count($seedCount)->create();

        $this->getJson('/api/questions/recommended?limit='.$limit)
            ->assertOk()
            ->assertJsonCount($expectedCount, 'data');
    }

    public static function validLimitProvider(): array
    {
        return [
            'min boundary' => [1, 5, 1],
            'max boundary' => [50, 60, 50],
            'mid value' => [7, 10, 7],
        ];
    }

    #[DataProvider('invalidLimitProvider')]
    public function test_recommended_rejects_invalid_limits(mixed $limit, string $errorFragment): void
    {
        Question::factory()->published()->create();

        $response = $this->getJson('/api/questions/recommended?limit='.urlencode((string) $limit));

        // Broad try/catch turns ValidationException into HTTP 500 (current behavior).
        $this->assertDashboardValidationFailure($response, $errorFragment);
    }

    public static function invalidLimitProvider(): array
    {
        return [
            'zero' => [0, 'at least 1'],
            'negative' => [-1, 'at least 1'],
            'above max' => [51, 'must not be greater than 50'],
            'non integer string' => ['abc', 'must be an integer'],
            'float' => ['1.5', 'must be an integer'],
        ];
    }

    public function test_recommended_empty_limit_is_treated_as_null_and_uses_default(): void
    {
        // ConvertEmptyStringsToNull turns limit= into null (nullable passes).
        Question::factory()->published()->count(16)->create();

        $this->getJson('/api/questions/recommended?limit=')
            ->assertOk()
            ->assertJsonCount(15, 'data');
    }

    public function test_recommended_null_limit_uses_default(): void
    {
        // Omitted limit uses default 15; explicit null query param may coerce differently.
        Question::factory()->published()->count(16)->create();

        $this->getJson('/api/questions/recommended')
            ->assertOk()
            ->assertJsonCount(15, 'data');
    }

    public function test_recommended_does_not_expose_question_content_or_user_email(): void
    {
        $author = User::factory()->create([
            'email' => 'secret@example.com',
            'access_token' => 'tok-secret',
        ]);
        $question = Question::factory()->published()->create([
            'user_id' => $author->id,
            'content' => '<script>alert("xss")</script>secret body',
        ]);

        $payload = $this->getJson('/api/questions/recommended')->assertOk()->json('data.0');
        $encoded = json_encode($this->getJson('/api/questions/recommended')->json());

        $this->assertArrayNotHasKey('content', $payload);
        $this->assertArrayNotHasKey('email', $payload['user']);
        $this->assertArrayNotHasKey('access_token', $payload['user']);
        $this->assertStringNotContainsString('secret@example.com', $encoded);
        $this->assertStringNotContainsString('tok-secret', $encoded);
        $this->assertSame($question->id, $payload['id']);
    }

    public function test_recommended_xss_title_is_returned_as_plain_json_text(): void
    {
        $xss = '<script>alert("xss")</script>';
        Question::factory()->published()->create(['title' => $xss]);

        $response = $this->getJson('/api/questions/recommended')->assertOk();

        $this->assertSame($xss, $response->json('data.0.title'));
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
    }
}
