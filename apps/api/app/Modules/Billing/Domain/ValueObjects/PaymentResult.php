<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\ValueObjects;

use App\Modules\Billing\Domain\Enums\PaymentStatus;

/**
 * Result of a payment operation.
 */
final readonly class PaymentResult
{
    public function __construct(
        public bool $success,
        public PaymentStatus $status,
        public ?string $paymentId = null,
        public ?string $message = null,
        public ?string $clientSecret = null,
        public bool $requiresAction = false,
        public ?string $actionType = null,
        public ?string $actionUrl = null,
        public ?string $errorCode = null,
    ) {}

    /**
     * Create a successful result.
     */
    public static function success(string $paymentId, ?string $message = null): self
    {
        return new self(
            success: true,
            status: PaymentStatus::Succeeded,
            paymentId: $paymentId,
            message: $message ?? 'Payment completed successfully',
        );
    }

    /**
     * Create a pending result (awaiting confirmation).
     */
    public static function pending(string $paymentId, string $message): self
    {
        return new self(
            success: false,
            status: PaymentStatus::Pending,
            paymentId: $paymentId,
            message: $message,
            requiresAction: true,
            actionType: 'awaiting_confirmation',
        );
    }

    /**
     * Create a result requiring user action (3D Secure, etc.).
     */
    public static function requiresAction(
        string $paymentId,
        string $clientSecret,
        string $actionUrl,
    ): self {
        return new self(
            success: false,
            status: PaymentStatus::RequiresAction,
            paymentId: $paymentId,
            clientSecret: $clientSecret,
            requiresAction: true,
            actionType: 'redirect',
            actionUrl: $actionUrl,
        );
    }

    /**
     * Create a failed result.
     */
    public static function failed(string $message, ?string $errorCode = null): self
    {
        return new self(
            success: false,
            status: PaymentStatus::Failed,
            message: $message,
            errorCode: $errorCode,
        );
    }
}
