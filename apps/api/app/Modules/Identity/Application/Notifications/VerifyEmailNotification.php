<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Notifications;

use App\Modules\Identity\Domain\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @param  string|null  $signedTenant  Tamper-proof tenant qualifier (topology
     *                                     r7 B1) so the pre-auth resolver can
     *                                     initialize the correct tenant DB before
     *                                     the token lookup post-flip. Produced by
     *                                     TenantLinkSigner in EmailVerificationService.
     */
    public function __construct(
        private readonly string $token,
        private readonly ?string $signedTenant = null,
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
        $query = ['token' => $this->token];
        if ($this->signedTenant !== null) {
            $query['tenant'] = $this->signedTenant;
        }
        $verificationUrl = "{$frontendUrl}/verify-email?".http_build_query($query);

        /** @var User $notifiable */
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
