<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

/**
 * Validated payload for POST /expenses/{id}/pay (Wave D, Task 15).
 *
 * Carries the settlement instruction: which treasury repository the cash
 * left from, the optional payment method label, and the settlement date.
 */
final readonly class PayExpenseRequestData
{
    public function __construct(
        public string $paymentRepositoryId,
        public ?string $paymentMethodId,
        public string $paymentDate,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Already-validated request payload.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentRepositoryId: (string) $data['payment_repository_id'],
            paymentMethodId: isset($data['payment_method_id']) ? (string) $data['payment_method_id'] : null,
            paymentDate: (string) $data['payment_date'],
        );
    }
}
