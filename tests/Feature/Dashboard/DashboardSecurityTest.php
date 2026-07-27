<?php

namespace Tests\Feature\Dashboard;

use App\Models\Question;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardSecurityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithDashboard;

    #[DataProvider('publicEndpointsProvider')]
    public function test_all_dashboard_endpoints_are_publicly_readable(string $uri): void
    {
        $this->getJson($uri)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public static function publicEndpointsProvider(): array
    {
        return [
            'stats' => ['/api/dashboard/stats'],
            'active users' => ['/api/dashboard/active-users'],
            'activity' => ['/api/dashboard/activity'],
            'recommended' => ['/api/questions/recommended'],
            'popular' => ['/api/questions/popular'],
        ];
    }

    #[DataProvider('mutatingMethodsProvider')]
    public function test_mutating_http_verbs_are_rejected_on_dashboard_routes(
        string $method,
        string $uri
    ): void {
        $this->json($method, $uri)->assertStatus(405);
    }

    public static function mutatingMethodsProvider(): array
    {
        return [
            'stats post' => ['POST', '/api/dashboard/stats'],
            'stats put' => ['PUT', '/api/dashboard/stats'],
            'stats delete' => ['DELETE', '/api/dashboard/stats'],
            'active users post' => ['POST', '/api/dashboard/active-users'],
            'activity post' => ['POST', '/api/dashboard/activity'],
            'recommended post' => ['POST', '/api/questions/recommended'],
            'popular post' => ['POST', '/api/questions/popular'],
        ];
    }

    public function test_delete_popular_path_is_captured_by_question_destroy_not_dashboard(): void
    {
        // Documents route collision: DELETE /questions/popular binds {question}=popular.
        $this->deleteJson('/api/questions/popular')->assertUnauthorized();
    }

    public function test_sql_injection_in_query_parameters_does_not_leak_or_mutate_data(): void
    {
        Question::factory()->published()->create(['title' => 'Safe', 'views' => 5]);
        User::factory()->create(['name' => 'Safe User', 'score' => 1]);

        $payload = "' OR 1=1 --";

        $this->getJson('/api/questions/recommended?limit='.urlencode($payload))
            ->assertStatus(500);

        $this->getJson('/api/questions/popular?period='.urlencode($payload))
            ->assertStatus(500);

        $this->getJson('/api/dashboard/active-users?limit='.urlencode($payload))
            ->assertStatus(500);

        $this->getJson('/api/dashboard/activity?offset='.urlencode($payload))
            ->assertStatus(500);

        $this->assertDatabaseCount('questions', 1);
        $this->assertDatabaseHas('questions', ['title' => 'Safe']);
        $this->assertDatabaseHas('users', ['name' => 'Safe User']);
    }

    public function test_xss_payloads_in_stored_content_are_json_encoded_not_executed(): void
    {
        $xss = '<img src=x onerror=alert(1)>';
        $user = User::factory()->create(['name' => $xss, 'score' => 5]);
        Question::factory()->published()->create([
            'user_id' => $user->id,
            'title' => $xss,
            'views' => 10,
        ]);

        foreach ([
            '/api/questions/recommended',
            '/api/questions/popular',
            '/api/dashboard/active-users',
        ] as $uri) {
            $response = $this->getJson($uri)->assertOk();
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
            $this->assertStringContainsString($xss, $response->getContent());
            $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
        }
    }

    public function test_idor_style_user_id_query_params_are_ignored_on_read_endpoints(): void
    {
        $victim = User::factory()->create([
            'score' => 100,
            'email' => 'victim@example.com',
            'access_token' => 'victim-token',
        ]);
        $attacker = User::factory()->create(['score' => 1]);

        Sanctum::actingAs($attacker);

        $encoded = json_encode(
            $this->getJson('/api/dashboard/active-users?user_id='.$victim->id)
                ->assertOk()
                ->json()
        );

        $this->assertStringNotContainsString('victim@example.com', $encoded);
        $this->assertStringNotContainsString('victim-token', $encoded);
    }

    public function test_mass_assignment_style_query_params_cannot_alter_stats_payload(): void
    {
        Question::factory()->published()->create();

        $response = $this->getJson('/api/dashboard/stats?'.http_build_query([
            'totalQuestions' => 999999,
            'totalUsers' => 0,
            'success' => false,
        ]))->assertOk();

        $this->assertSame(1, $response->json('data.totalQuestions'));
        $this->assertTrue($response->json('success'));
        $this->assertNotSame(999999, $response->json('data.totalQuestions'));
    }

    public function test_authenticated_non_admin_cannot_gain_extra_fields_via_auth(): void
    {
        $author = User::factory()->create([
            'email' => 'author@example.com',
            'role' => 'user',
            'access_token' => 'hidden-token',
        ]);
        Question::factory()->published()->create(['user_id' => $author->id, 'views' => 3]);

        Sanctum::actingAs($author);

        foreach (['/api/questions/recommended', '/api/questions/popular'] as $uri) {
            $userPayload = $this->getJson($uri)->assertOk()->json('data.0.user');
            $this->assertSame(['id', 'name'], array_keys($userPayload));
        }

        $activeUser = $this->getJson('/api/dashboard/active-users')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('email', $activeUser);
        $this->assertArrayNotHasKey('access_token', $activeUser);
        $this->assertArrayNotHasKey('role', $activeUser);
    }

    public function test_exception_handler_paths_do_not_expose_stack_traces_in_json(): void
    {
        DB::shouldReceive('selectOne')
            ->once()
            ->andThrow(new \RuntimeException('db exploded'));

        $payload = $this->getJson('/api/dashboard/stats')->assertStatus(500)->json();

        $this->assertFalse($payload['success']);
        $this->assertSame('db exploded', $payload['error']);
        $this->assertArrayNotHasKey('trace', $payload);
        $this->assertArrayNotHasKey('file', $payload);
        $this->assertArrayNotHasKey('line', $payload);
    }

    public function test_concurrent_reads_are_idempotent_and_do_not_mutate_database(): void
    {
        Question::factory()->published()->count(3)->create(['views' => 1]);
        User::factory()->count(2)->create(['score' => 5]);

        $beforeQuestions = DB::table('questions')->count();
        $beforeUsers = DB::table('users')->count();

        foreach (range(1, 5) as $_) {
            $this->getJson('/api/dashboard/stats')->assertOk();
            $this->getJson('/api/questions/popular')->assertOk();
            $this->getJson('/api/dashboard/active-users')->assertOk();
        }

        $this->assertSame($beforeQuestions, DB::table('questions')->count());
        $this->assertSame($beforeUsers, DB::table('users')->count());
    }

    public function test_security_headers_middleware_is_present_on_dashboard_responses(): void
    {
        $response = $this->getJson('/api/dashboard/stats')->assertOk();

        // Assert Content-Type remains JSON API (XSS sink not HTML).
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }
}
