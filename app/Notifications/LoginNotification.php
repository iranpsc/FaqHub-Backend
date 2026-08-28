<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $user;

    public $loginData;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user, array $loginData = [])
    {
        $this->user = $user;
        $this->loginData = $loginData;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $loginTime = now()->format('Y-m-d H:i:s');
        $ipAddress = $this->loginData['ip_address'] ?? 'نامشخص';
        $userAgent = $this->loginData['user_agent'] ?? 'نامشخص';

        return (new MailMessage)
            ->subject('ورود جدید به حساب کاربری شما')
            ->view('emails.login-notification', [
                'notifiable' => $this->user,
                'loginTime' => $loginTime,
                'ipAddress' => $ipAddress,
                'userAgent' => $userAgent,
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->user->id,
            'login_time' => now(),
            'ip_address' => $this->loginData['ip_address'] ?? null,
            'user_agent' => $this->loginData['user_agent'] ?? null,
        ];
    }
}
