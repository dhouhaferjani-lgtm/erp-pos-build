<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Modules\Compliance\Domain\CompanyFraudSettings;

final class CompanyFraudSettingsService
{
    /**
     * Ensure a CompanyFraudSettings row exists for the given company.
     *
     * Returns the existing row if one is already present. Otherwise creates
     * one with the shared defaults. Blind cash counting is enabled for every
     * vertical, so company creation does not need a vertical lookup.
     */
    public function ensureForCompany(int|string $companyId): CompanyFraudSettings
    {
        /** @var CompanyFraudSettings $settings */
        $settings = CompanyFraudSettings::firstOrCreate(
            ['company_id' => $companyId],
            CompanyFraudSettings::getDefaults(),
        );

        return $settings;
    }
}
