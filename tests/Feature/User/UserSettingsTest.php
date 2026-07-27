<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_enable_login_notification_setting(): void
    {
        $user = User::factory()->create([
            'login_notification_enabled' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/settings', [
            'login_notification_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'تنظیمات با موفقیت بروزرسانی شد',
                'login_notification_enabled' => true,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'login_notification_enabled' => 1,
        ]);
    }

    public function test_user_can_disable_login_notification_setting(): void
    {
        $user = User::factory()->withLoginNotification()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/settings', [
            'login_notification_enabled' => false,
        ]);

        $response->assertOk()
            ->assertJson([
                'login_notification_enabled' => false,
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'login_notification_enabled' => 0,
        ]);
    }

    #[DataProvider('validBooleanProvider')]
    public function test_settings_accept_valid_boolean_representationsations(mixed $value, bool $expected): void
    {
        $user = User::factory()->create(['login_notification_enabled' => ! $expected]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/settings', [
            'login_notification_enabled' => $value,
        ])
            ->assertOk()
            ->assertJson(['login_notification_enabled' => $expected]);

        $this->assertSame($expected, $user->fresh()->login_notification_enabled);
    }

    public static function validBooleanProvider(): array
    {
        return [
            'true boolean' => [true, true],
            'false boolean' => [false, false],
            'integer one' => [1, true],
            'integer zero' => [0, false],
            'string one' => ['1', true],
            'string zero' => ['0', false],
        ];
    }

    #[DataProvider('invalidBooleanProvider')]
    public function test_settings_reject_invalid_boolean_values(mixed $value): void
    {
        $user = User::factory()->create(['login_notification_enabled' => false]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/settings', [
            'login_notification_enabled' => $value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login_notification_enabled'])
            ->assertJson([
                'message' => 'خطا در اعتبارسنجی',
            ]);

        $this->assertFalse($user->fresh()->login_notification_enabled);
    }

    public static function invalidBooleanProvider(): array
    {
        return [
            'string maybe' => ['maybe'],
            'string true word' => ['true'],
            'string false word' => ['false'],
            'integer two' => [2],
            'negative one' => [-1],
            'array' => [[true]],
            'object-like array' => [['enabled' => true]],
            'null explicitly invalid under boolean when present as JSON null with strict?' => ['null-string'],
        ];
    }

    public function test_missing_login_notification_flag_defaults_to_false(): void
    {
        $user = User::factory()->withLoginNotification()->create();

        Sanctum::actingAs($user);

        // Field is optional for validation, but boolean() defaults missing to false
        $this->postJson('/api/user/settings', [])
            ->assertOk()
            ->assertJson(['login_notification_enabled' => false]);

        $this->assertFalse($user->fresh()->login_notification_enabled);
    }

    public function test_guest_cannot_update_settings(): void
    {
        $this->postJson('/api/user/settings', [
            'login_notification_enabled' => true,
        ])->assertUnauthorized();
    }

    public function test_mass_assignment_cannot_escalate_role_score_or_level_via_settings(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'score' => 10,
            'level' => 2,
            'email' => 'safe@example.com',
            'name' => 'Safe User',
            'login_notification_enabled' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/user/settings', [
            'login_notification_enabled' => true,
            'role' => 'admin',
            'score' => 99999,
            'level' => 13,
            'email' => 'attacker@example.com',
            'name' => 'Hacker',
            'username' => 'root',
        ])->assertOk();

        $user->refresh();

        $this->assertTrue($user->login_notification_enabled);
        $this->assertSame('user', $user->role);
        $this->assertSame(10, $user->score);
        $this->assertSame(2, $user->level);
        $this->assertSame('safe@example.com', $user->email);
        $this->assertSame('Safe User', $user->name);
    }

    public function test_settings_update_only_affects_authenticated_user_idor(): void
    {
        $attacker = User::factory()->create(['login_notification_enabled' => false]);
        $victim = User::factory()->create(['login_notification_enabled' => false]);

        Sanctum::actingAs($attacker);

        $this->postJson('/api/user/settings', [
            'login_notification_enabled' => true,
            'user_id' => $victim->id,
            'id' => $victim->id,
        ])->assertOk();

        $this->assertTrue($attacker->fresh()->login_notification_enabled);
        $this->assertFalse($victim->fresh()->login_notification_enabled);
    }

    public function test_sql_injection_payload_in_settings_is_rejected_by_boolean_rule(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/user/settings', [
            'login_notification_enabled' => "1; DROP TABLE users;--",
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login_notification_enabled']);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
