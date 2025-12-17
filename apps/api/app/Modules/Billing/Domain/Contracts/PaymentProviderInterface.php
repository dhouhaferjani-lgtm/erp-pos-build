<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Contracts;

use App\Modules\Billing\Domain\Enums\PaymentProviderCode;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Billing\Domain\ValueObjects\PaymentResult;

/**
 * Interface for payment providers.
 *
 * Each payment provider (Stripe, PayPal, Flouci, Manual, etc.)
 * must implement this interface.
 */
interface PaymentProviderInterface
{
    /**
     * Get the provider code.
     */
    public function getCode(): PaymentProviderCode;

    /**
     * Get human-readable provider name.
     */
    public function getName(): string;

    /**
     * Check if this provider is currently available.
     */
    public function isAvailable(): bool;

    /**
     * Check if this provider supports the given currency.
     */
    public function supportsCurrency(string $currency): bool;

    /**
     * Get supported currencies.
     *
     * @return array<string>
     */
    public function getSupportedCurrencies(): array;

    /**
     * Create a payment intent/session.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createPayment(
        Money $amount,
        string $description,
        array $metadata = [],
    ): PaymentResult;

    /**
     * Capture a previously authorized payment.
     */
    public function capturePayment(string $paymentId): PaymentResult;

    /**
     * Cancel/void a payment.
     */
    public function cancelPayment(string $paymentId): PaymentResult;

    /**
     * Process a refund.
     */
    public function refund(string $paymentId, ?Money $amount = null): PaymentResult;

    /**
     * Get payment status from provider.
     */
    public function getPaymentStatus(string $paymentId): PaymentResult;

    /**
     * Verify webhook signature (for providers that support webhooks).
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(string $payload, array $headers): bool;

    /**
     * Parse webhook payload into standardized format.
     *
     * @return array<string, mixed>
     */
    public function parseWebhook(string $payload): array;
}
