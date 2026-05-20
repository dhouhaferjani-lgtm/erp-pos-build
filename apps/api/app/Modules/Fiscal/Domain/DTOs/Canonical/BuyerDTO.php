<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;
use InvalidArgumentException;

/**
 * Buyer block — sale-time snapshot. NULL when no buyer attached.
 *
 * Sourced from `fiscal_events.payload.buyer`. D16-safe per synthesis v5
 * §5: NO projection-time enrichment, no customer/contact/B2B lookups.
 *
 * `customer_id` / `contact_id` are POS-local mirror references; if the
 * underlying rows are later deleted, the SEALED buyer snapshot is
 * authoritative. The projector / NF525 export read the snapshot only.
 *
 * `codice_fiscale` is IT-specific (future). All fields nullable except
 * the buyer object itself (which is nullable at the parent payload level).
 */
final readonly class BuyerDTO
{
    public function __construct(
        public ?AddressDTO $address,
        public ?string $codiceFiscale,
        public ?string $contactId,
        public ?string $customerId,
        public ?string $name,
        public ?string $taxNumber,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $address = null;
        if (array_key_exists('address', $data) && $data['address'] !== null) {
            $raw = $data['address'];
            if (! is_array($raw)) {
                throw new InvalidArgumentException('buyer.address must be an object or null');
            }
            foreach ($raw as $k => $_) {
                if (! is_string($k)) {
                    throw new InvalidArgumentException('buyer.address must be an object (string keys only); got int-keyed list');
                }
            }
            /** @var array<string, mixed> $raw */
            $address = AddressDTO::fromArray($raw);
        }

        return new self(
            address: $address,
            codiceFiscale: FiscalPayloadArrayGuards::optionalString($data, 'codice_fiscale'),
            contactId: FiscalPayloadArrayGuards::optionalString($data, 'contact_id'),
            customerId: FiscalPayloadArrayGuards::optionalString($data, 'customer_id'),
            name: FiscalPayloadArrayGuards::optionalString($data, 'name'),
            taxNumber: FiscalPayloadArrayGuards::optionalString($data, 'tax_number'),
        );
    }
}
