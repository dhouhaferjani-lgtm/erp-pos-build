<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Domain\Payment;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentSucceededNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Payment $payment,
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
        $currency = strtoupper($this->payment->currency);
        $amount = CurrencyScale::bcformat($this->payment->amount, CurrencyScale::for($currency));

        return (new MailMessage)
            ->subject('Payment Received - Thank You!')
            ->greeting('Hello!')
            ->line("We've successfully received your payment of {$currency} {$amount}.")
            ->line("Payment ID: {$this->payment->id}")
            ->line('Thank you for your continued business!')
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
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
        ];
    }
}
