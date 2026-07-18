<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\InstrumentKind;

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
        public string $mode = 'cash',
        public ?InstrumentKind $instrumentKind = null,
        public ?string $instrumentReference = null,
        public ?string $instrumentBankId = null,
        public ?string $instrumentMaturityDate = null,
        public ?string $instrumentDrawerName = null,
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
            mode: isset($data['mode']) ? (string) $data['mode'] : 'cash',
            instrumentKind: isset($data['instrument']['kind'])
                ? InstrumentKind::from((string) $data['instrument']['kind'])
                : null,
            instrumentReference: isset($data['instrument']['reference'])
                ? (string) $data['instrument']['reference']
                : null,
            instrumentBankId: isset($data['instrument']['bank_id'])
                ? (string) $data['instrument']['bank_id']
                : null,
            instrumentMaturityDate: isset($data['instrument']['maturity_date'])
                ? (string) $data['instrument']['maturity_date']
                : null,
            instrumentDrawerName: isset($data['instrument']['drawer_name'])
                ? (string) $data['instrument']['drawer_name']
                : null,
        );
    }

    public function isInstrument(): bool
    {
        return $this->mode === 'instrument';
    }
}
