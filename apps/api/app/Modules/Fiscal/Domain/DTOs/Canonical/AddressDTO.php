<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

/**
 * Postal address block — shared by seller + buyer.
 *
 * Sourced from `fiscal_events.payload.{seller,buyer}.address` (Candidate
 * C-v3 §3 — synthesis v5). 4 required keys; `country_code` is ISO 3166-1
 * alpha-2. Parser ensures the parent object's shape; this DTO type-tags
 * the parsed sub-object for downstream readers (Nf525 + ZATCA export
 * adapters, Pass 2A.PHP.2 + Phase 2).
 */
final readonly class AddressDTO
{
    public function __construct(
        public string $city,
        public string $countryCode,
        public string $postalCode,
        public string $street,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            city: FiscalPayloadArrayGuards::requireString($data, 'city'),
            countryCode: FiscalPayloadArrayGuards::requireString($data, 'country_code'),
            postalCode: FiscalPayloadArrayGuards::requireString($data, 'postal_code'),
            street: FiscalPayloadArrayGuards::requireString($data, 'street'),
        );
    }
}
