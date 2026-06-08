<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Domain\Payment;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class AdminPaymentAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $alertType,
        private readonly Payment $payment,
        private readonly string $tenantName,
        private readonly string $message = '',
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

        $subject = match ($this->alertType) {
            'payment_succeeded' => "[Admin] Payment Received: {$currency} {$amount} from {$this->tenantName}",
            'payment_failed' => "[Admin Alert] Payment Failed for {$this->tenantName}",
            'refund_processed' => "[Admin] Refund Processed: {$currency} {$amount} for {$this->tenantName}",
            default => "[Admin] Billing Event for {$this->tenantName}",
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting('Admin Alert')
            ->line("Tenant: {$this->tenantName}")
            ->line("Amount: {$currency} {$amount}")
            ->line("Provider: {$this->payment->provider}")
            ->line("Payment ID: {$this->payment->id}");

        if ($this->message) {
            $mail->line("Details: {$this->message}");
        }

        return $mail
            ->action('View in Admin Panel', url('/admin/billing/payments'))
            ->salutation('Mecanospex Admin System');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'alert_type' => $this->alertType,
            'payment_id' => $this->payment->id,
            'tenant_name' => $this->tenantName,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
            'message' => $this->message,
        ];
    }
}
