<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class DepositReceiptCustomerDTO
{
    public function __construct(
        public string $customerId,
        public string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $customerCategory,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerId: FiscalPayloadArrayGuards::requireString($data, 'customer_id'),
            name: FiscalPayloadArrayGuards::requireString($data, 'name'),
            phone: FiscalPayloadArrayGuards::optionalString($data, 'phone'),
            email: FiscalPayloadArrayGuards::optionalString($data, 'email'),
            customerCategory: FiscalPayloadArrayGuards::optionalString($data, 'customer_category'),
        );
    }
}
