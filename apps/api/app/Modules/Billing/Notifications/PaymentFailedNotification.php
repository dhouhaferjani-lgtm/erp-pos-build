<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Domain\Invoice;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class PaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Invoice $invoice,
        private readonly string $errorMessage = 'Payment declined',
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
        $amount = CurrencyScale::bcformat($this->invoice->total, 2);
        $currency = strtoupper($this->invoice->currency);

        return (new MailMessage)
            ->subject('Payment Failed - Action Required')
            ->greeting('Hello!')
            ->line("We were unable to process your payment of {$currency} {$amount} for invoice {$this->invoice->number}.")
            ->line("Reason: {$this->errorMessage}")
            ->line('Please update your payment method or contact our support team for assistance.')
            ->action('Update Payment Method', url('/settings/billing'))
            ->line('If you need help, please contact our support team.')
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
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->number,
            'amount' => $this->invoice->total,
            'currency' => $this->invoice->currency,
            'error_message' => $this->errorMessage,
        ];
    }
}
