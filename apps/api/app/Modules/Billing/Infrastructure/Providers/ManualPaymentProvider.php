<?php

declare(strict_types=1);

namespace App\Modules\Billing\Infrastructure\Providers;

use App\Modules\Billing\Domain\Contracts\PaymentProviderInterface;
use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Domain\Enums\PaymentStatus;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Billing\Domain\ValueObjects\PaymentResult;

/**
 * Manual payment provider for offline payments.
 *
 * Used for:
 * - Bank transfers
 * - Cash payments
 * - Check payments
 * - Any manual/offline payment method
 *
 * These payments are recorded by admins after verification.
 */
final class ManualPaymentProvider implements PaymentProviderInterface
{
    /**
     * The specific manual payment type.
     */
    private PaymentProviderCode $type;

    public function __construct(PaymentProviderCode $type = PaymentProviderCode::Manual)
    {
        $this->type = $type;
    }

    public function getCode(): PaymentProviderCode
    {
        return $this->type;
    }

    public function getName(): string
    {
        return match ($this->type) {
            PaymentProviderCode::BankTransfer => 'Bank Transfer',
            PaymentProviderCode::Cash => 'Cash',
            PaymentProviderCode::Check => 'Check',
            default => 'Manual Payment',
        };
    }

    public function isAvailable(): bool
    {
        // Manual payments are always available
        return true;
    }

    public function supportsCurrency(string $currency): bool
    {
        // Manual payments support all currencies
        return true;
    }

    /**
     * @return array<string>
     */
    public function getSupportedCurrencies(): array
    {
        // Support common currencies for manual payments
        return ['EUR', 'USD', 'GBP', 'TND', 'MAD', 'DZD'];
    }

    /**
     * Create a manual payment record.
     *
     * For manual payments, this creates a pending payment that
     * will be marked as succeeded when admin verifies receipt.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createPayment(
        Money $amount,
        string $description,
        array $metadata = [],
    ): PaymentResult {
        // Generate a unique payment ID for tracking
        $paymentId = 'manual_'.uniqid('', true);

        // Manual payments start as pending until admin confirms
        return PaymentResult::pending(
            paymentId: $paymentId,
            message: $this->getPendingMessage(),
        );
    }

    public function capturePayment(string $paymentId): PaymentResult
    {
        // For manual payments, capture means admin has verified the payment
        return PaymentResult::success(
            paymentId: $paymentId,
            message: 'Payment verified and captured',
        );
    }

    public function cancelPayment(string $paymentId): PaymentResult
    {
        // Manual payments can be cancelled by admin
        return new PaymentResult(
            success: true,
            status: PaymentStatus::Cancelled,
            paymentId: $paymentId,
            message: 'Payment cancelled',
        );
    }

    public function refund(string $paymentId, ?Money $amount = null): PaymentResult
    {
        // Manual refunds are just recorded, not processed
        $refundId = 'refund_'.uniqid('', true);

        return PaymentResult::success(
            paymentId: $refundId,
            message: 'Refund recorded',
        );
    }

    public function getPaymentStatus(string $paymentId): PaymentResult
    {
        // For manual payments, we return the current status from DB
        // This is a no-op as status is managed locally
        return new PaymentResult(
            success: true,
            status: PaymentStatus::Pending,
            paymentId: $paymentId,
            message: 'Status check not applicable for manual payments',
        );
    }

    /**
     * Manual payments don't have webhooks.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $payload, array $headers): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function parseWebhook(string $payload): array
    {
        return [];
    }

    /**
     * Get pending message based on payment type.
     */
    private function getPendingMessage(): string
    {
        return match ($this->type) {
            PaymentProviderCode::BankTransfer => 'Awaiting bank transfer confirmation',
            PaymentProviderCode::Cash => 'Awaiting cash payment verification',
            PaymentProviderCode::Check => 'Awaiting check clearance',
            default => 'Awaiting payment confirmation',
        };
    }

    /**
     * Get payment instructions for tenant.
     */
    public function getPaymentInstructions(): string
    {
        return match ($this->type) {
            PaymentProviderCode::BankTransfer => $this->getBankTransferInstructions(),
            PaymentProviderCode::Cash => 'Please make your payment in person at our office.',
            PaymentProviderCode::Check => 'Please send your check to our billing address.',
            default => 'Please complete your payment using the agreed method.',
        };
    }

    /**
     * Get bank transfer instructions.
     */
    private function getBankTransferInstructions(): string
    {
        $bankName = config('billing.bank_transfer.bank_name', 'Our Bank');
        $iban = config('billing.bank_transfer.iban', 'XXXX XXXX XXXX XXXX');
        $bic = config('billing.bank_transfer.bic', 'XXXXXXXX');

        return <<<INSTRUCTIONS
Please transfer the amount to:
Bank: {$bankName}
IBAN: {$iban}
BIC: {$bic}

Include your invoice number in the payment reference.
INSTRUCTIONS;
    }
}
