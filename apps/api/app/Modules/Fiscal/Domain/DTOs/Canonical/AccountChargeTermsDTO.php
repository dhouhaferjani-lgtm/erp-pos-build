<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;
use InvalidArgumentException;

final readonly class AccountChargeTermsDTO
{
    public function __construct(
        public ?string $dueDate,
        public ?int $paymentTermsDays,
        public ?string $termsLabel,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $paymentTermsDays = $data['payment_terms_days'] ?? null;
        if ($paymentTermsDays !== null && ! is_int($paymentTermsDays)) {
            throw new InvalidArgumentException(sprintf(
                'Canonical payload key "payment_terms_days" must be an int or null; got %s.',
                get_debug_type($paymentTermsDays),
            ));
        }

        return new self(
            dueDate: FiscalPayloadArrayGuards::optionalString($data, 'due_date'),
            paymentTermsDays: $paymentTermsDays,
            termsLabel: FiscalPayloadArrayGuards::optionalString($data, 'terms_label'),
        );
    }
}
