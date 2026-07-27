<?php

namespace Tests\Feature\Author;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuthors;
use Tests\TestCase;

class AuthorSecurityTest extends TestCase
{
    use InteractsWithAuthors;
    use RefreshDatabase;

    #[DataProvider('publicEndpointsProvider')]
    public function test_all_author_endpoints_are_publicly_readable(string $uriFactory): void
    {
        $author = $this->createAuthor(['username' => 'public-author', 'score' => 1]);
        $this->createPublishedQuestionFor($author);

        $uri = match ($uriFactory) {
            'index' => $this->authorsIndexUrl(),
            'show' => $this->authorShowUrl($author),
            'questions' => $this->authorQuestionsUrl($author),
        };

        $this->getJson($uri)->assertOk();
    }

    public static function publicEndpointsProvider(): array
    {
        return [
            'index' => ['index'],
            'show' => ['show'],
            'questions' => ['questions'],
        ];
    }

    #[DataProvider('mutatingMethodsProvider')]
    public function test_mutating_http_verbs_are_rejected_on_author_routes(
        string $method,
        string $uriFactory
    ): void {
        $author = $this->createAuthor(['username' => 'immutable', 'score' => 1]);

        $uri = match ($uriFactory) {
            'index' => $this->authorsIndexUrl(),
            'show' => $this->authorShowUrl($author),
            'questions' => $this->authorQuestionsUrl($author),
        };

        $this->json($method, $uri)->assertStatus(405);
    }

    public static function mutatingMethodsProvider(): array
    {
        return [
            'index post' => ['POST', 'index'],
            'index put' => ['PUT', 'index'],
            'index patch' => ['PATCH', 'index'],
            'index delete' => ['DELETE', 'index'],
            'show post' => ['POST', 'show'],
            'show put' => ['PUT', 'show'],
            'show delete' => ['DELETE', 'show'],
            'questions post' => ['POST', 'questions'],
            'questions put' => ['PUT', 'questions'],
            'questions delete' => ['DELETE', 'questions'],
        ];
    }

    public function test_sql_injection_in_search_does_not_leak_or_mutate_data(): void
    {
        $safe = $this->createAuthor([
            'name' => 'Safe Author',
            'username' => 'safe-author',
            'email' => 'safe@example.com',
            'score' => 10,
        ]);

        $payload = "' OR 1=1 --";

        $response = $this->getJson($this->authorsIndexUrl(['search' => $payload]))->assertOk();

        $response->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('users', [
            'id' => $safe->id,
            'name' => 'Safe Author',
            'email' => 'safe@example.com',
        ]);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_sql_injection_in_sort_parameters_does_not_mutate_database(): void
    {
        $author = $this->createAuthor(['username' => 'sort-safe', 'score' => 5]);

        $response = $this->getJson($this->authorsIndexUrl([
            'sort_by' => 'score; DROP TABLE users;--',
            'sort_order' => 'desc; DROP TABLE users;--',
        ]));

        // Unknown sort_by falls back to score; invalid sort_order may 500 via try/catch.
        $this->assertContains($response->status(), [200, 500]);
        $this->assertDatabaseHas('users', ['id' => $author->id, 'username' => 'sort-safe']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_xss_payloads_in_stored_author_fields_are_json_encoded_not_executed(): void
    {
        $xss = '<script>alert(1)</script>';
        $author = $this->createAuthor([
            'name' => $xss,
            'username' => 'xss-author',
            'score' => 7,
        ]);
        $this->createPublishedQuestionFor($author, ['title' => $xss]);

        foreach ([
            $this->authorsIndexUrl(),
            $this->authorShowUrl($author),
            $this->authorQuestionsUrl($author),
        ] as $uri) {
            $response = $this->getJson($uri)->assertOk();
            $payload = $response->json();
            $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
            // JSON encodes angle brackets; assert decoded payload still carries the stored XSS string.
            $this->assertTrue(
                str_contains(json_encode($payload), json_encode($xss))
                || data_get($payload, 'data.0.name') === $xss
                || data_get($payload, 'data.name') === $xss
                || data_get($payload, 'data.0.title') === $xss
            );
            $this->assertStringNotContainsString('<html', strtolower($response->getContent()));
        }
    }

    public function test_idor_style_user_id_query_params_cannot_swap_author_identity(): void
    {
        $victim = $this->createAuthor([
            'username' => 'victim-author',
            'email' => 'victim@example.com',
            'access_token' => 'victim-token',
            'score' => 100,
        ]);
        $attacker = $this->createAuthor([
            'username' => 'attacker-author',
            'score' => 1,
        ]);

        Sanctum::actingAs($attacker);

        $show = $this->getJson($this->authorShowUrl($attacker).'?user_id='.$victim->id.'&id='.$victim->id)
            ->assertOk();

        $show->assertJsonPath('data.id', $attacker->id)
            ->assertJsonPath('data.username', 'attacker-author');

        $encoded = json_encode($show->json());
        $this->assertStringNotContainsString('victim@example.com', $encoded);
        $this->assertStringNotContainsString('victim-token', $encoded);

        $questions = $this->getJson(
            $this->authorQuestionsUrl($attacker, ['user_id' => $victim->id])
        )->assertOk();

        $this->assertSame(0, $questions->json('meta.total'));
    }

    public function test_mass_assignment_style_query_params_cannot_alter_author_payload(): void
    {
        $author = $this->createAuthor([
            'username' => 'mass-safe',
            'score' => 15,
            'name' => 'Real Name',
        ]);

        $response = $this->getJson($this->authorShowUrl($author).'?'.http_build_query([
            'score' => 999999,
            'name' => 'Hacked',
            'role' => 'admin',
            'success' => false,
        ]))->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.score', 15)
            ->assertJsonPath('data.name', 'Real Name')
            ->assertJsonPath('data.role', 'user');

        $author->refresh();
        $this->assertSame(15, $author->score);
        $this->assertSame('Real Name', $author->name);
        $this->assertSame('user', $author->role);
    }

    public function test_search_by_email_does_not_expose_email_in_response(): void
    {
        // Searching by email can confirm account existence, but email must not leak in JSON.
        $author = $this->createAuthor([
            'username' => 'email-search',
            'email' => 'findme@example.com',
            'score' => 2,
        ]);

        $response = $this->getJson($this->authorsIndexUrl(['search' => 'findme@example.com']))
            ->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $author->id);

        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('findme@example.com', $encoded);
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
    }

    public function test_index_does_not_expose_role_while_show_does(): void
    {
        $admin = User::factory()->admin()->create([
            'username' => 'role-leak',
            'score' => 50,
        ]);

        $indexPayload = $this->getJson($this->authorsIndexUrl())->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('role', $indexPayload);

        $this->getJson($this->authorShowUrl($admin))
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_authenticated_user_cannot_gain_extra_sensitive_fields_via_auth(): void
    {
        $author = $this->createAuthor([
            'username' => 'auth-fields',
            'email' => 'auth-fields@example.com',
            'access_token' => 'hidden-token',
            'score' => 3,
        ]);

        Sanctum::actingAs($author);

        foreach ([
            $this->authorsIndexUrl(),
            $this->authorShowUrl($author),
        ] as $uri) {
            $encoded = json_encode($this->getJson($uri)->assertOk()->json());
            $this->assertStringNotContainsString('auth-fields@example.com', $encoded);
            $this->assertStringNotContainsString('hidden-token', $encoded);
        }
    }

    public function test_invalid_bearer_token_still_allows_public_author_access(): void
    {
        $author = $this->createAuthor(['username' => 'optional-auth', 'score' => 1]);

        $this->withHeader('Authorization', 'Bearer invalid-token')
            ->getJson($this->authorShowUrl($author))
            ->assertOk()
            ->assertJsonPath('data.id', $author->id);
    }

    public function test_concurrent_reads_are_idempotent_and_do_not_mutate_database(): void
    {
        $author = $this->createAuthor(['username' => 'concurrent', 'score' => 8]);
        $this->createPublishedQuestionFor($author);

        $beforeUsers = DB::table('users')->count();
        $beforeQuestions = DB::table('questions')->count();

        foreach (range(1, 5) as $_) {
            $this->getJson($this->authorsIndexUrl())->assertOk();
            $this->getJson($this->authorShowUrl($author))->assertOk();
            $this->getJson($this->authorQuestionsUrl($author))->assertOk();
        }

        $this->assertSame($beforeUsers, DB::table('users')->count());
        $this->assertSame($beforeQuestions, DB::table('questions')->count());
    }

    public function test_user_model_does_not_use_soft_deletes_so_deleted_authors_are_hard_missing(): void
    {
        $author = $this->createAuthor(['username' => 'hard-delete', 'score' => 1]);
        $author->delete();

        $this->assertDatabaseMissing('users', ['id' => $author->id]);
        $this->getJson($this->authorShowUrl('hard-delete'))->assertNotFound();
        $this->getJson($this->authorQuestionsUrl('hard-delete'))->assertNotFound();
    }

    #[DataProvider('indexQueryEdgeCasesProvider')]
    public function test_index_handles_search_edge_cases_without_500(string $search): void
    {
        $this->createAuthor(['name' => 'Safe Author', 'username' => 'safe-edge', 'score' => 1]);

        $this->getJson($this->authorsIndexUrl(['search' => $search]))
            ->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    public static function indexQueryEdgeCasesProvider(): array
    {
        return [
            'sql injection attempt' => ["' OR 1=1 --"],
            'wildcard percent' => ['%'],
            'underscore wildcard' => ['_'],
            'unicode' => ['تست'],
            'very long query' => [str_repeat('a', 500)],
            'xss attempt' => ['<img src=x onerror=alert(1)>'],
            'null byte-ish' => ["alice\0bob"],
        ];
    }

    public function test_security_content_type_remains_json_on_author_responses(): void
    {
        $author = $this->createAuthor(['username' => 'json-only', 'score' => 1]);

        $response = $this->getJson($this->authorsIndexUrl())->assertOk();

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type')
        );
    }

    public function test_store_update_destroy_author_routes_are_not_registered(): void
    {
        $author = $this->createAuthor(['username' => 'no-write', 'score' => 1]);

        $this->postJson('/api/authors', ['name' => 'Hacked'])->assertStatus(405);
        $this->putJson($this->authorShowUrl($author), ['name' => 'Hacked'])->assertStatus(405);
        $this->patchJson($this->authorShowUrl($author), ['name' => 'Hacked'])->assertStatus(405);
        $this->deleteJson($this->authorShowUrl($author))->assertStatus(405);

        $author->refresh();
        $this->assertSame('no-write', $author->username);
        $this->assertDatabaseHas('users', ['id' => $author->id]);
    }
}
