<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\InvoiceStatus;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\Invoice;
use App\Modules\Billing\Domain\Payment;
use App\Modules\Billing\Domain\Plan;
use App\Modules\Billing\Domain\TenantSubscription;
use App\Modules\Billing\Notifications\AdminPaymentAlertNotification;
use App\Modules\Billing\Notifications\InvoicePaidNotification;
use App\Modules\Billing\Notifications\PaymentFailedNotification;
use App\Modules\Billing\Notifications\PaymentSucceededNotification;
use App\Modules\Billing\Notifications\SubscriptionCancelledNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * Task 8.1 — Billing notifications must derive decimal scale from the
 * serialized model's currency field at render time, not hard-code scale 2.
 *
 * Each queued notification carries the Eloquent model; the scale MUST be
 * resolved inside toMail()/toArray() via CurrencyScale::for($currency).
 */
final class NotificationCurrencyScaleTest extends TestCase
{
    // -----------------------------------------------------------------------
    // InvoicePaidNotification
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function invoice_paid_notification_renders_tnd_amount_at_scale_3(): void
    {
        $invoice = $this->makeTndInvoice('1500.500');

        $notification = new InvoicePaidNotification($invoice);
        $mail = $notification->toMail(new \stdClass);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.500', implode(' ', $introLine),
            'TND invoice (scale 3) must render 3 decimal places in the mail body');
    }

    /**
     * @test
     */
    public function invoice_paid_notification_renders_eur_amount_at_scale_2(): void
    {
        $invoice = $this->makeEurInvoice('1500.50');

        $notification = new InvoicePaidNotification($invoice);
        $mail = $notification->toMail(new \stdClass);

        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.50', implode(' ', $introLine),
            'EUR invoice (scale 2) must render 2 decimal places in the mail body');
        // Must NOT render 3 decimal places for EUR
        $this->assertStringNotContainsString('1500.500', implode(' ', $introLine),
            'EUR invoice must not render 3 decimal places');
    }

    // -----------------------------------------------------------------------
    // PaymentFailedNotification
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function payment_failed_notification_renders_tnd_amount_at_scale_3(): void
    {
        $invoice = $this->makeTndInvoice('1500.500');

        $notification = new PaymentFailedNotification($invoice);
        $mail = $notification->toMail(new \stdClass);

        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.500', implode(' ', $introLine),
            'TND invoice (scale 3) must render 3 decimal places in failed notification');
    }

    /**
     * @test
     */
    public function payment_failed_notification_renders_eur_amount_at_scale_2(): void
    {
        $invoice = $this->makeEurInvoice('1500.50');

        $notification = new PaymentFailedNotification($invoice);
        $mail = $notification->toMail(new \stdClass);

        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.50', implode(' ', $introLine));
        $this->assertStringNotContainsString('1500.500', implode(' ', $introLine));
    }

    // -----------------------------------------------------------------------
    // PaymentSucceededNotification
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function payment_succeeded_notification_renders_tnd_amount_at_scale_3(): void
    {
        $payment = $this->makeTndPayment('1500.500');

        $notification = new PaymentSucceededNotification($payment);
        $mail = $notification->toMail(new \stdClass);

        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.500', implode(' ', $introLine),
            'TND payment (scale 3) must render 3 decimal places');
    }

    /**
     * @test
     */
    public function payment_succeeded_notification_renders_eur_amount_at_scale_2(): void
    {
        $payment = $this->makeEurPayment('1500.50');

        $notification = new PaymentSucceededNotification($payment);
        $mail = $notification->toMail(new \stdClass);

        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.50', implode(' ', $introLine));
        $this->assertStringNotContainsString('1500.500', implode(' ', $introLine));
    }

    // -----------------------------------------------------------------------
    // AdminPaymentAlertNotification
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function admin_payment_alert_notification_renders_tnd_amount_at_scale_3(): void
    {
        $payment = $this->makeTndPayment('1500.500');

        $notification = new AdminPaymentAlertNotification('payment_succeeded', $payment, 'TestCorp');
        $mail = $notification->toMail(new \stdClass);

        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.500', implode(' ', $introLine),
            'TND admin alert (scale 3) must render 3 decimal places');
    }

    /**
     * @test
     */
    public function admin_payment_alert_notification_renders_eur_amount_at_scale_2(): void
    {
        $payment = $this->makeEurPayment('1500.50');

        $notification = new AdminPaymentAlertNotification('payment_succeeded', $payment, 'TestCorp');
        $mail = $notification->toMail(new \stdClass);

        $introLine = $this->extractIntroLines($mail);
        $this->assertStringContainsString('1500.50', implode(' ', $introLine));
        $this->assertStringNotContainsString('1500.500', implode(' ', $introLine));
    }

    // -----------------------------------------------------------------------
    // SubscriptionCancelledNotification — no money formatting, smoke-test only
    // -----------------------------------------------------------------------

    /**
     * @test
     */
    public function subscription_cancelled_notification_has_no_monetary_formatting(): void
    {
        // SubscriptionCancelledNotification does NOT format any monetary amount.
        // This smoke test just ensures it renders without errors.
        //
        // The notification accesses $this->subscription->plan->name, so we must
        // pre-load the plan relation to avoid a DB hit in the in-memory test.
        $plan = new Plan;
        $plan->forceFill(['name' => 'Pro Plan']);

        $subscription = new TenantSubscription;
        $subscription->forceFill([
            'id' => 'aaaaaaaa-0000-0000-0000-000000000001',
            'plan_id' => 'bbbbbbbb-0000-0000-0000-000000000001',
            'ends_at' => null,
        ]);
        // Set the relation directly so no DB query is triggered.
        $subscription->setRelation('plan', $plan);

        $notification = new SubscriptionCancelledNotification($subscription);
        $mail = $notification->toMail(new \stdClass);

        $this->assertInstanceOf(MailMessage::class, $mail);
        // No money amount in body
        $introLine = $this->extractIntroLines($mail);
        $body = implode(' ', $introLine);
        $this->assertSame(0, preg_match('/\d+\.\d{2,3}/', $body),
            'SubscriptionCancelledNotification should not contain any monetary amount');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeTndInvoice(string $total): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill([
            'id' => 'aaaaaaaa-0000-0000-0000-000000000001',
            'number' => 'INV-TND-001',
            'currency' => 'TND',
            'total' => $total,
            'status' => InvoiceStatus::Paid->value,
            'paid_at' => '2026-01-15 10:00:00',
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
        ]);

        return $invoice;
    }

    private function makeEurInvoice(string $total): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill([
            'id' => 'aaaaaaaa-0000-0000-0000-000000000002',
            'number' => 'INV-EUR-001',
            'currency' => 'EUR',
            'total' => $total,
            'status' => InvoiceStatus::Paid->value,
            'paid_at' => '2026-01-15 10:00:00',
            'invoice_date' => '2026-01-01',
            'due_date' => '2026-01-31',
        ]);

        return $invoice;
    }

    private function makeTndPayment(string $amount): Payment
    {
        $payment = new Payment;
        $payment->forceFill([
            'id' => 'cccccccc-0000-0000-0000-000000000001',
            'currency' => 'TND',
            'amount' => $amount,
            'status' => PaymentStatus::Succeeded->value,
            'provider' => 'manual',
            'fee' => '0.000',
            'net_amount' => $amount,
        ]);

        return $payment;
    }

    private function makeEurPayment(string $amount): Payment
    {
        $payment = new Payment;
        $payment->forceFill([
            'id' => 'cccccccc-0000-0000-0000-000000000002',
            'currency' => 'EUR',
            'amount' => $amount,
            'status' => PaymentStatus::Succeeded->value,
            'provider' => 'manual',
            'fee' => '0.000',
            'net_amount' => $amount,
        ]);

        return $payment;
    }

    /**
     * Extract all intro lines from a MailMessage as an array of strings.
     * Also includes the subject line for the admin alert subject-line assertion.
     *
     * @return array<string>
     */
    private function extractIntroLines(MailMessage $mail): array
    {
        // introLines is a public array property on MailMessage (never null).
        $lines = $mail->introLines;
        // Also include the subject (for AdminPaymentAlertNotification subject-line check).
        // subject is a non-nullable string on MailMessage.
        $lines[] = $mail->subject;

        return array_map('strval', $lines);
    }
}
