<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Application\DTOs\EffectiveCountCorrectionGlPosting;
use Illuminate\Support\Facades\DB;

/**
 * THE authority on whether a count correction posts a shrinkage/gain journal
 * entry for a given company (lane P-1, owner ruling 2026-08-25).
 *
 * ## The chain
 *
 *   `companies.count_correction_gl_posting_enabled` (explicit override)
 *     -> `country_inventory_settings.count_correction_gl_posting_enabled` (jurisdiction)
 *       -> the SYSTEM default, `config('inventory.count_correction_gl_posting_enabled')`
 *
 * NULL on the company column means "inherit", which is why the column is
 * nullable and undefaulted — the same representation `inventory_valuation_mode`
 * uses (T9), and the reason the P-1 backfill migration can tell an untouched
 * tenant from one that decided.
 *
 * Structurally this is {@see InventoryValuationModeResolver} with a boolean
 * instead of an enum, deliberately: one resolver idiom for the inventory
 * country-defaults family, not two. Both read the country row by raw query
 * builder so they carry no CompanyContext or relation-loading dependency and are
 * safe in a queue worker (house rule 20) — which matters here, because the only
 * production caller is the QUEUED counting listener.
 *
 * ## What replaced what
 *
 * Before P-1 the gate was a bare `config()` read, defaulted FALSE by the
 * OQ-12/H-5 deploy blocker. The owner superseded that blocker on 2026-08-25:
 * perpetual inventory means count corrections must reach the ledger, and the
 * expert-comptable reviews the Option A account choice (6586 / 7586) later at
 * onboarding. The config key survives as the SYSTEM link of the chain — now
 * defaulted TRUE, still `env()`-overridable, so an operator retains a
 * deployment-wide kill switch without editing tenant data.
 */
final class CountCorrectionGlPostingResolver
{
    public function resolve(string $companyId): EffectiveCountCorrectionGlPosting
    {
        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);

        if (is_bool($company->count_correction_gl_posting_enabled)) {
            return new EffectiveCountCorrectionGlPosting(
                $company->count_correction_gl_posting_enabled,
                EffectiveCountCorrectionGlPosting::SOURCE_COMPANY,
            );
        }

        $countryDefault = $this->countryDefault((string) $company->country_code);

        if ($countryDefault !== null) {
            return new EffectiveCountCorrectionGlPosting(
                $countryDefault,
                EffectiveCountCorrectionGlPosting::SOURCE_COUNTRY,
            );
        }

        return new EffectiveCountCorrectionGlPosting(
            $this->systemDefault(),
            EffectiveCountCorrectionGlPosting::SOURCE_SYSTEM,
        );
    }

    /**
     * Shorthand for the only question the counting listener asks.
     */
    public function isEnabledFor(string $companyId): bool
    {
        return $this->resolve($companyId)->enabled;
    }

    /**
     * The deployment-wide default, `true` since the P-1 ruling.
     */
    public function systemDefault(): bool
    {
        return (bool) config('inventory.count_correction_gl_posting_enabled', true);
    }

    /**
     * The jurisdiction default, or null when this country says nothing.
     *
     * Deliberately NOT wrapped in a `Schema::hasTable()` guard, for the reason
     * {@see InventoryValuationModeResolver::countryMode()} states at length: a
     * seeder must be a no-op before its own migration has run, a resolver
     * reached on a tenant database missing the table should raise rather than
     * silently answer "no jurisdiction rule".
     */
    private function countryDefault(string $countryCode): ?bool
    {
        if ($countryCode === '') {
            return null;
        }

        $row = DB::table('country_inventory_settings')
            ->where('country_code', strtoupper($countryCode))
            ->value('count_correction_gl_posting_enabled');

        if ($row === null) {
            return null;
        }

        // SQLite hands booleans back as 0/1 integers, PostgreSQL as real bools.
        return (bool) $row;
    }
}
