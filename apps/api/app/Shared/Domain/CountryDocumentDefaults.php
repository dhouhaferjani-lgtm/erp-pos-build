<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Modules\Document\Domain\Enums\PreDeliveryInvoicingPolicy;

/**
 * The PINNED per-country DOCUMENT-lane defaults (Wave 3 D-27).
 *
 * Sibling of {@see CountryPaymentDefaults}, same contract: one declaration of
 * what each country's seeded row must say, read by
 * `CountryDocumentSettingsSeeder` (the provisioning path) and by nothing else
 * that writes. Duplicating the literals is how a provisioning path and an ops
 * path drift apart.
 *
 * A country ABSENT from this map has **no** pinned default. That is not an
 * oversight and no caller may invent one: the resolver falls through to
 * `PreDeliveryInvoicingPolicy::systemDefault()`, which is
 * `require_delivery_first`. Fail closed.
 */
final class CountryDocumentDefaults
{
    /**
     * @var array<string, array{pre_delivery_invoicing_policy: PreDeliveryInvoicingPolicy}>
     */
    private const DEFAULTS = [
        // Tunisia — the 2026-08-10 expert rulings, directly:
        // NCT 03 (revenue at transfer of risks and rewards) + Code de la TVA
        // Art. 18 (VAT owed by the mere fact of issuance).
        'TN' => ['pre_delivery_invoicing_policy' => PreDeliveryInvoicingPolicy::RequireDeliveryFirst],

        // France — ⚠ PROVISIONAL. Seeded EXPLICITLY rather than left to the
        // unknown-country fallback, because an implicit default is exactly how a
        // country silently acquires a policy nobody chose. The PCG 487 /
        // "délivrance vs débits" question was NOT put to the expert
        // (`docs/superpowers/tickets/2026-08-10-expert-rulings-deferred-revenue-vat-issuance.md:47-48`),
        // so this value is the conservative reading and is expected to be
        // revisited when that answer lands.
        'FR' => ['pre_delivery_invoicing_policy' => PreDeliveryInvoicingPolicy::RequireDeliveryFirst],
    ];

    /**
     * @return array<string, array{pre_delivery_invoicing_policy: PreDeliveryInvoicingPolicy}>
     */
    public static function all(): array
    {
        return self::DEFAULTS;
    }

    /**
     * The pinned defaults for a country, or null when it has none — in which case
     * the resolver's system default applies and NO caller may substitute its own.
     *
     * @return array{pre_delivery_invoicing_policy: PreDeliveryInvoicingPolicy}|null
     */
    public static function forCountry(string $countryCode): ?array
    {
        return self::DEFAULTS[strtoupper(trim($countryCode))] ?? null;
    }
}
