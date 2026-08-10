<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Application\DTOs\EffectiveValuationMode;
use App\Modules\Inventory\Domain\Enums\InventoryValuationMode;
use App\Modules\Inventory\Domain\Exceptions\UnsupportedValuationModeException;
use Illuminate\Support\Facades\DB;

/**
 * THE authority on which inventory valuation system a company runs under
 * (DPA Wave 3, D-14 / T9).
 *
 * ## The chain
 *
 *   `companies.inventory_valuation_mode` (explicit override)
 *     -> `country_inventory_settings.inventory_valuation_mode` (jurisdiction)
 *       -> the SYSTEM default, `Perpetual`
 *
 * NULL on the company column means "inherit", which is why the column is
 * nullable and undefaulted. The country row is read by raw query builder rather
 * than through an Eloquent relation so the resolver has no CompanyContext or
 * relation-loading dependency and is safe in a queue worker (house rule 20).
 *
 * ## The refusal
 *
 * `requirePerpetual()` throws `UnsupportedValuationModeException` for anything
 * else. This is the THIRD of D-14's three layers — the enum names `Periodic`,
 * the CHECK admits it, and this refuses it — so periodic can be enabled later
 * without DDL on a live tenant database, but cannot be enabled by accident
 * today and silently produce wrong journal entries.
 *
 * ## What this class deliberately does NOT do
 *
 * It does not install a `saving` model guard on `Company`. `CompanyFactory:72`
 * writes the sibling costing column unconditionally, and a throwing model hook
 * would break every seeder and factory in the suite (plan T9 risk note). The
 * refusal belongs at the two real boundaries: the settings request (422) and
 * this resolver (exception).
 */
final class InventoryValuationModeResolver
{
    /**
     * The mode assumed when neither the company nor its country says anything.
     * Perpetual, because it is the only implemented mode — a system default of
     * `Periodic` would fail closed everywhere for no reason.
     */
    public const SYSTEM_DEFAULT = InventoryValuationMode::Perpetual;

    public function resolve(string $companyId): EffectiveValuationMode
    {
        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);

        if ($company->inventory_valuation_mode instanceof InventoryValuationMode) {
            return new EffectiveValuationMode(
                $company->inventory_valuation_mode,
                EffectiveValuationMode::SOURCE_COMPANY,
            );
        }

        $countryMode = $this->countryMode((string) $company->country_code);

        if ($countryMode !== null) {
            return new EffectiveValuationMode($countryMode, EffectiveValuationMode::SOURCE_COUNTRY);
        }

        return new EffectiveValuationMode(self::SYSTEM_DEFAULT, EffectiveValuationMode::SOURCE_SYSTEM);
    }

    /**
     * Refuse to proceed unless this company runs perpetual valuation.
     *
     * @throws UnsupportedValuationModeException
     */
    public function requirePerpetual(string $companyId): void
    {
        $effective = $this->resolve($companyId);

        if ($effective->isSupported()) {
            return;
        }

        throw UnsupportedValuationModeException::forCompany($companyId, $effective->mode);
    }

    private function countryMode(string $countryCode): ?InventoryValuationMode
    {
        if ($countryCode === '') {
            return null;
        }

        $row = DB::table('country_inventory_settings')
            ->where('country_code', strtoupper($countryCode))
            ->value('inventory_valuation_mode');

        if (! is_string($row)) {
            return null;
        }

        // A row carrying an unknown string is a schema/CHECK failure, not a
        // silent fallback: tryFrom returning null lets the chain continue to the
        // system default, which is the safe read for a value the enum has never
        // heard of.
        return InventoryValuationMode::tryFrom($row);
    }
}
