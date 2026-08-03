<?php

namespace App\Notifications;

use App\Models\Question;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuestionInteractionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $user;

    public $question;

    public $interactionType;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user, Question $question, string $interactionType)
    {
        $this->user = $user;
        $this->question = $question;
        $this->interactionType = $interactionType; // 'answer' or 'comment'
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
        $actionText = $this->interactionType === 'answer' ? 'پاسخ داد' : 'نظر داد';
        $subject = "کاربر '{$this->user->name}' به سوال شما {$actionText}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("سلام! {$notifiable->name}")
            ->line("کاربر '{$this->user->name}' به سوال شما {$actionText}.")
            ->line("عنوان سوال: {$this->question->title}")
            ->action('مشاهده سوال', url("/questions/{$this->question->slug}"))
            ->line('با تشکر از استفاده شما از پلتفرم ما!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
