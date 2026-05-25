<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountPaymentPaymentDTO
{
    public function __construct(
        public string $amount,
        public string $methodCode,
        public ?string $repositoryId,
        public ?string $instrumentType,
        public ?string $instrumentSerial,
        public ?string $foreignCurrencyCode,
        public ?string $foreignCurrencyAmount,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            amount: FiscalPayloadArrayGuards::requireString($data, 'amount'),
            methodCode: FiscalPayloadArrayGuards::requireString($data, 'method_code'),
            repositoryId: FiscalPayloadArrayGuards::optionalString($data, 'repository_id'),
            instrumentType: FiscalPayloadArrayGuards::optionalString($data, 'instrument_type'),
            instrumentSerial: FiscalPayloadArrayGuards::optionalString($data, 'instrument_serial'),
            foreignCurrencyCode: FiscalPayloadArrayGuards::optionalString($data, 'foreign_currency_code'),
            foreignCurrencyAmount: FiscalPayloadArrayGuards::optionalString($data, 'foreign_currency_amount'),
        );
    }
}
