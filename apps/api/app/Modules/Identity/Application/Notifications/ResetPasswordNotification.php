<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password-reset link notification carrying a tamper-proof tenant qualifier
 * (topology r7 B1). After the Phase 0b flip the password_reset_tokens table
 * moves tenant-side, so a token-only link cannot pick a tenant DB and the same
 * email may exist in several tenants — the signed `tenant` param lets the
 * pre-auth resolver initialize the right tenant before the Password broker
 * consumes the token.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $email,
        private readonly ?string $signedTenant = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $query = [
            'token' => $this->token,
            'email' => $this->email,
        ];
        if ($this->signedTenant !== null) {
            $query['tenant'] = $this->signedTenant;
        }

        $url = config('app.frontend_url', config('app.url')).'/reset-password?'.http_build_query($query);

        return (new MailMessage)
            ->subject('Reset your password')
            ->greeting('Hello!')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line('This password reset link will expire shortly.')
            ->line('If you did not request a password reset, no further action is required.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'email' => $this->email,
        ];
    }
}
