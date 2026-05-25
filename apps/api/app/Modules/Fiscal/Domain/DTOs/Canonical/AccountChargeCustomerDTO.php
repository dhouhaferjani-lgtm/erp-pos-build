<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountChargeCustomerDTO
{
    /**
     * @param  array<string, mixed>|null  $address
     */
    public function __construct(
        public string $customerId,
        public string $customerSyncStatus,
        public string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $taxNumber,
        public ?array $address,
        public ?string $customerCategory,
        public ?string $accountIdentifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerId: FiscalPayloadArrayGuards::requireString($data, 'customer_id'),
            customerSyncStatus: FiscalPayloadArrayGuards::requireString($data, 'customer_sync_status'),
            name: FiscalPayloadArrayGuards::requireString($data, 'name'),
            phone: FiscalPayloadArrayGuards::optionalString($data, 'phone'),
            email: FiscalPayloadArrayGuards::optionalString($data, 'email'),
            taxNumber: FiscalPayloadArrayGuards::optionalString($data, 'tax_number'),
            // @phpstan-ignore-next-line argument.type
            address: FiscalPayloadArrayGuards::optionalArray($data, 'address'),
            customerCategory: FiscalPayloadArrayGuards::optionalString($data, 'customer_category'),
            accountIdentifier: FiscalPayloadArrayGuards::optionalString($data, 'account_identifier'),
        );
    }
}
