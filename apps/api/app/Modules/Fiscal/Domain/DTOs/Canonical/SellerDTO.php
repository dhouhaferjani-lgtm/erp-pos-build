<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;
use InvalidArgumentException;

/**
 * Seller block — REQUIRED on every SALE_RECEIPT (NF525 SIRET +
 * TN matricule fiscal + KSA 15-digit + IT P.IVA + DE USt-ID).
 *
 * Sourced from `fiscal_events.payload.seller`. `tax_number` is the
 * universal-pattern-validated registration number; per-country strict
 * regex deferred to Phase-1.5 roadmap task.
 */
final readonly class SellerDTO
{
    public function __construct(
        public AddressDTO $address,
        public string $name,
        public string $taxJurisdictionCountryCode,
        public string $taxNumber,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $address = FiscalPayloadArrayGuards::requireArray($data, 'address');
        // The parser + constraint validator enforce nested-object shape
        // upstream; the guard already asserted is_array. We re-narrow the
        // key type to silence PHPStan: requireArray returns
        // array<int|string, mixed> (because PHP arrays are key-polymorphic),
        // but AddressDTO::fromArray needs array<string, mixed>. Reject any
        // integer keys defensively.
        foreach ($address as $k => $_) {
            if (! is_string($k)) {
                throw new InvalidArgumentException('seller.address must be an object (string keys only); got int-keyed list');
            }
        }
        /** @var array<string, mixed> $address */

        return new self(
            address: AddressDTO::fromArray($address),
            name: FiscalPayloadArrayGuards::requireString($data, 'name'),
            taxJurisdictionCountryCode: FiscalPayloadArrayGuards::requireString($data, 'tax_jurisdiction_country_code'),
            taxNumber: FiscalPayloadArrayGuards::requireString($data, 'tax_number'),
        );
    }
}
