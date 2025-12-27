<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        private readonly string $token,
    ) {}

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
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $verificationUrl = "{$frontendUrl}/verify-email?token={$this->token}";

        /** @var \App\Modules\Identity\Domain\User $notifiable */
        $locale = $notifiable->preferences['locale'] ?? 'en';

        return $this->buildMailMessage($verificationUrl, $notifiable->name, $locale);
    }

    /**
     * Build the mail message based on locale.
     */
    private function buildMailMessage(string $url, string $name, string $locale): MailMessage
    {
        if ($locale === 'fr') {
            return (new MailMessage)
                ->subject(__('auth.verify_email_subject', [], 'fr'))
                ->greeting(__('auth.verify_email_greeting', ['name' => $name], 'fr'))
                ->line(__('auth.verify_email_body', [], 'fr'))
                ->action(__('auth.verify_email_button', [], 'fr'), $url)
                ->line(__('auth.verify_email_expiry', ['hours' => 24], 'fr'))
                ->salutation(__('auth.verify_email_salutation', [], 'fr'));
        }

        return (new MailMessage)
            ->subject(__('auth.verify_email_subject', [], 'en'))
            ->greeting(__('auth.verify_email_greeting', ['name' => $name], 'en'))
            ->line(__('auth.verify_email_body', [], 'en'))
            ->action(__('auth.verify_email_button', [], 'en'), $url)
            ->line(__('auth.verify_email_expiry', ['hours' => 24], 'en'))
            ->salutation(__('auth.verify_email_salutation', [], 'en'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'token' => $this->token,
        ];
    }
}
