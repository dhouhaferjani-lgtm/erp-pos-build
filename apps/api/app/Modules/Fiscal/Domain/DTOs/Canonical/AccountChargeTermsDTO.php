<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountChargeTermsDTO
{
    public function __construct(
        public string $dueDate,
        public int $paymentTermsDays,
        public string $termsLabel,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dueDate: FiscalPayloadArrayGuards::requireString($data, 'due_date'),
            paymentTermsDays: FiscalPayloadArrayGuards::requireInt($data, 'payment_terms_days'),
            termsLabel: FiscalPayloadArrayGuards::requireString($data, 'terms_label'),
        );
    }
}
