<?php

namespace Tests\Feature\Question;

use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithQuestions;
use Tests\TestCase;

class QuestionSearchTest extends TestCase
{
    use InteractsWithQuestions;
    use RefreshDatabase;

    public function test_guest_can_search_published_questions_by_title(): void
    {
        $match = $this->createPublishedQuestion([
            'title' => 'Laravel Routing Deep Dive',
            'views' => 10,
        ]);
        $this->createPublishedQuestion(['title' => 'Vue Composition API', 'views' => 5]);
        $this->createUnpublishedQuestion(['title' => 'Laravel Draft Hidden']);

        $response = $this->getJson('/api/questions/search?q=Laravel');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'جستجو با موفقیت انجام شد')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonStructure([
                'success',
                'data' => [['id', 'title', 'user', 'category']],
                'message',
            ]);
    }

    public function test_search_returns_empty_data_when_no_matches(): void
    {
        $this->createPublishedQuestion(['title' => 'Something Else']);

        $this->getJson('/api/questions/search?q=zzzz-no-match')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [],
            ]);
    }

    public function test_search_without_query_returns_published_questions_limited_to_default(): void
    {
        Question::factory()->published()->count(12)->create();

        $this->getJson('/api/questions/search')
            ->assertOk()
            ->assertJsonCount(10, 'data');
    }

    public function test_search_respects_limit_parameter(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->createPublishedQuestion([
                'title' => "Framework Topic {$i}",
                'slug' => "framework-topic-{$i}",
                'views' => $i,
            ]);
        }

        $this->getJson('/api/questions/search?q=Framework&limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_search_orders_by_views_desc_then_created_at_desc(): void
    {
        $low = $this->createPublishedQuestion([
            'title' => 'Framework A',
            'views' => 10,
            'created_at' => now()->subDays(2),
        ]);
        $high = $this->createPublishedQuestion([
            'title' => 'Framework B',
            'views' => 20,
            'created_at' => now()->subDay(),
        ]);
        $mid = $this->createPublishedQuestion([
            'title' => 'Framework C',
            'views' => 15,
            'created_at' => now(),
        ]);

        $ids = collect($this->getJson('/api/questions/search?q=Framework')->json('data'))->pluck('id')->all();

        $this->assertEquals([$high->id, $mid->id, $low->id], $ids);
    }

    public function test_search_ignores_published_true_without_published_at(): void
    {
        Question::factory()->create([
            'title' => 'Incomplete Publish State',
            'published' => true,
            'published_at' => null,
        ]);

        $this->getJson('/api/questions/search?q=Incomplete')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[DataProvider('invalidSearchProvider')]
    public function test_search_validation(array $query, array $errors): void
    {
        $this->getJson('/api/questions/search?'.http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    public static function invalidSearchProvider(): array
    {
        return [
            'q too long' => [['q' => str_repeat('a', 151)], ['q']],
            'limit below min' => [['limit' => 0], ['limit']],
            'limit above max' => [['limit' => 51], ['limit']],
            'limit not integer' => [['limit' => 'abc'], ['limit']],
        ];
    }

    public function test_search_sql_injection_payload_does_not_error_or_leak_extra_rows(): void
    {
        $safe = $this->createPublishedQuestion(['title' => 'Safe Laravel Question']);
        $this->createPublishedQuestion(['title' => 'Other Topic']);

        $payload = "%' OR 1=1 --";

        $response = $this->getJson('/api/questions/search?q='.urlencode($payload));

        $response->assertOk();
        $this->assertNotContains($safe->id, collect($response->json('data'))->pluck('id'));
    }
}
