<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Notifications;

use App\Modules\Identity\Domain\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

class UserInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    private string $inviterName;

    private string $tenantName;

    public function __construct(string $inviterName, string $tenantName)
    {
        $this->inviterName = $inviterName;
        $this->tenantName = $tenantName;
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
     *
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $user */
        $user = $notifiable;

        // Generate password reset token for the user
        $token = Password::createToken($user);
        $email = $user->email;

        // Build the set password URL
        $url = config('app.frontend_url', config('app.url')).'/set-password?'.http_build_query([
            'token' => $token,
            'email' => $email,
        ]);

        return (new MailMessage)
            ->subject("You've been invited to join {$this->tenantName}")
            ->greeting("Hello {$user->name}!")
            ->line("{$this->inviterName} has invited you to join {$this->tenantName}.")
            ->line('Click the button below to set your password and activate your account.')
            ->action('Set Your Password', $url)
            ->line('This invitation link will expire in 24 hours.')
            ->line('If you did not expect this invitation, you can safely ignore this email.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'inviter_name' => $this->inviterName,
            'tenant_name' => $this->tenantName,
        ];
    }
}
