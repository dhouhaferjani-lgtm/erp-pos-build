<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Domain\TenantSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SubscriptionCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly TenantSubscription $subscription,
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
        $planName = $this->subscription->plan->name ?? 'your plan';
        $endsAt = $this->subscription->ends_at?->format('F j, Y') ?? 'soon';

        return (new MailMessage)
            ->subject('Subscription Cancelled')
            ->greeting('Hello!')
            ->line("Your subscription to {$planName} has been cancelled.")
            ->line("Your access will continue until {$endsAt}.")
            ->line("We're sorry to see you go! If you change your mind, you can resubscribe at any time.")
            ->action('Resubscribe', url('/settings/billing'))
            ->line('If you have any feedback on how we can improve, please let us know.')
            ->salutation('Best regards, The Mecanospex Team');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'plan_id' => $this->subscription->plan_id,
            'ends_at' => $this->subscription->ends_at?->toIso8601String(),
        ];
    }
}
