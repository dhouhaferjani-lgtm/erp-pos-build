<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Domain\Invoice;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoicePaidNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Invoice $invoice,
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
        $currency = strtoupper($this->invoice->currency);
        $amount = CurrencyScale::bcformat($this->invoice->total, CurrencyScale::for($currency));

        return (new MailMessage)
            ->subject("Invoice {$this->invoice->number} Paid - Thank You!")
            ->greeting('Hello!')
            ->line("Your invoice {$this->invoice->number} for {$currency} {$amount} has been paid.")
            ->line("Payment Date: {$this->invoice->paid_at?->format('F j, Y')}")
            ->action('View Invoice', url("/invoices/{$this->invoice->id}"))
            ->line('Thank you for your business!')
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
            'paid_at' => $this->invoice->paid_at?->toIso8601String(),
        ];
    }
}
