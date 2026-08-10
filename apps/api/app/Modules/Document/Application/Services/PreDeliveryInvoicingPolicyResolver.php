<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\CountryDocumentSettings;
use App\Modules\Document\Domain\DTOs\ResolvedPreDeliveryInvoicingPolicy;
use App\Modules\Document\Domain\Enums\PreDeliveryInvoicingPolicy;
use App\Modules\Document\Domain\Exceptions\PreDeliveryInvoicingNotSupportedException;
use Illuminate\Support\Facades\Schema;

/**
 * THE authority on *"may a definitive goods invoice precede delivery here?"*
 * (Wave 3 T25a, D-18′ / D-27).
 *
 * ── THE LADDER (the shipped `PaymentToleranceService::getToleranceSettings()`
 * shape, verbatim — company override → country row → system default, carrying
 * its source) ──
 *
 *   1. `companies.pre_delivery_invoicing_policy` — non-NULL wins.
 *   2. `country_document_settings` for the company's country.
 *   3. `PreDeliveryInvoicingPolicy::systemDefault()` = `require_delivery_first`.
 *
 * Rung 3 is the fail-closed floor and it is the ONLY default in the system: an
 * unknown country resolves to *require delivery*, never to *permit*. Nothing
 * here reads config or env — the policy is seeded data (owner rider, binding).
 *
 * ── THE REFUSAL ── If any rung resolves `allow`, this resolver THROWS. The
 * value exists in the enum and in the CHECK so it can be switched on later
 * without DDL on a live tenant database, but honouring it today would post a
 * pre-delivery goods invoice as revenue and seal it into the fiscal chain, with
 * no 472/419 machinery to state it correctly. See
 * {@see PreDeliveryInvoicingNotSupportedException}.
 */
final class PreDeliveryInvoicingPolicyResolver
{
    /**
     * The ENFORCEMENT accessor: resolve the policy in order to ACT on it.
     *
     * @throws PreDeliveryInvoicingNotSupportedException when a rung resolves `allow`
     */
    public function resolveForCompany(Company $company): ResolvedPreDeliveryInvoicingPolicy
    {
        $resolved = $this->walkLadder($company);

        if (! $resolved->requiresDeliveryFirst()) {
            throw PreDeliveryInvoicingNotSupportedException::forSource($resolved->source);
        }

        return $resolved;
    }

    /**
     * The OBSERVATION accessor: resolve the policy in order to RECORD it. Never
     * throws (fix round 1, fiscal F-3).
     *
     * ── WHY THE STAMP MUST NOT SHARE THE ENFORCEMENT ACCESSOR ──
     * `DeliveryComplianceGate::stampDeliveryPolicyDecision()` runs on EVERY
     * invoice post, including the compliant ones the gate itself returned early
     * on without ever consulting this resolver. Reaching the throwing accessor
     * from there turns the first operator who sets `allow` — a value the schema
     * deliberately permits so the switch needs no DDL later — into a TOTAL
     * invoice-posting outage, surfaced as an opaque `POSTING_FAILED`.
     *
     * And it would be wrong on its own terms: the stamp is an audit record of
     * what was TRUE at post time. `allow` being in force is a true fact, and the
     * one an auditor most needs to see. Refusing to write it down is the
     * opposite of what the stamp is for.
     *
     * The refusal stays exactly where it belongs — on the path that would
     * otherwise let a pre-delivery goods invoice post under `allow` with no
     * 472/419 machinery behind it ({@see resolveForCompany()}).
     */
    public function resolveForAudit(Company $company): ResolvedPreDeliveryInvoicingPolicy
    {
        return $this->walkLadder($company);
    }

    private function walkLadder(Company $company): ResolvedPreDeliveryInvoicingPolicy
    {
        $companyOverride = $this->toPolicy($company->getAttribute('pre_delivery_invoicing_policy'));

        if ($companyOverride !== null) {
            return ResolvedPreDeliveryInvoicingPolicy::fromCompany($companyOverride);
        }

        $countryPolicy = $this->countryPolicy($company->country_code);

        if ($countryPolicy !== null) {
            return ResolvedPreDeliveryInvoicingPolicy::fromCountry($countryPolicy);
        }

        return ResolvedPreDeliveryInvoicingPolicy::fromSystemDefault();
    }

    private function countryPolicy(?string $countryCode): ?PreDeliveryInvoicingPolicy
    {
        if ($countryCode === null || trim($countryCode) === '') {
            return null;
        }

        // The gate runs on the fiscal posting chokepoint, which must keep working
        // on a database where M5 has not yet been applied (staging auto-deploys
        // code and migrations separately). No table ⇒ rung 3.
        if (! Schema::hasTable('country_document_settings')) {
            return null;
        }

        $row = CountryDocumentSettings::query()
            ->where('country_code', strtoupper(trim($countryCode)))
            ->first();

        return $row?->pre_delivery_invoicing_policy;
    }

    private function toPolicy(mixed $raw): ?PreDeliveryInvoicingPolicy
    {
        if ($raw instanceof PreDeliveryInvoicingPolicy) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // An unrecognised string is NOT silently treated as "no override" — that
        // would fail OPEN in the one direction that matters if the enum ever
        // gains a value the column already carries.
        return PreDeliveryInvoicingPolicy::from($raw);
    }
}
