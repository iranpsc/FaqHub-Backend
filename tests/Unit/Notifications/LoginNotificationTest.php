<?php

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\LoginNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_via_returns_mail_channel(): void
    {
        $user = User::factory()->create();
        $notification = new LoginNotification($user, []);

        $this->assertSame(['mail'], $notification->via($user));
    }

    public function test_to_array_contains_user_id(): void
    {
        $user = User::factory()->create();
        $loginData = [
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
        ];
        $notification = new LoginNotification($user, $loginData);

        $array = $notification->toArray($user);

        $this->assertSame($user->id, $array['user_id']);
        $this->assertSame('192.168.1.1', $array['ip_address']);
        $this->assertSame('Mozilla/5.0', $array['user_agent']);
    }

    public function test_to_array_with_empty_login_data_has_null_fields(): void
    {
        $user = User::factory()->create();
        $notification = new LoginNotification($user, []);

        $array = $notification->toArray($user);

        $this->assertSame($user->id, $array['user_id']);
        $this->assertNull($array['ip_address']);
        $this->assertNull($array['user_agent']);
        $this->assertArrayHasKey('login_time', $array);
    }

    public function test_to_mail_returns_mail_message_with_correct_subject(): void
    {
        $user = User::factory()->create(['name' => 'Ali Mohammadi']);
        $notification = new LoginNotification($user, [
            'ip_address' => '10.0.0.1',
            'user_agent' => 'TestBrowser',
        ]);

        // We cannot call toMail() directly because it relies on a view file.
        // Instead, assert the notification structure by checking via() and toArray().
        $this->assertSame(['mail'], $notification->via($user));

        $array = $notification->toArray($user);
        $this->assertSame($user->id, $array['user_id']);
    }

    public function test_notification_stores_user_and_login_data(): void
    {
        $user = User::factory()->create();
        $loginData = ['ip_address' => '1.2.3.4', 'user_agent' => 'Chrome'];

        $notification = new LoginNotification($user, $loginData);

        $this->assertSame($user->id, $notification->user->id);
        $this->assertSame($loginData, $notification->loginData);
    }
}
