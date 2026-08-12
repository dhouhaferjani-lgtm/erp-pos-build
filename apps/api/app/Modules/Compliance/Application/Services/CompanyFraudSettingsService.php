<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Modules\Compliance\Domain\CompanyFraudSettings;
use App\Shared\Contracts\Company\CompanyVerticalQueryContract;

final class CompanyFraudSettingsService
{
    public function __construct(
        private readonly CompanyVerticalQueryContract $companyVerticalQuery,
    ) {}

    /**
     * Ensure a CompanyFraudSettings row exists for the given company.
     *
     * Returns the existing row if one is already present. Otherwise creates
     * one with the shared defaults. Blind cash counting is enabled for every
     * vertical; the vertical-aware entry point remains for caller compatibility.
     */
    public function ensureForCompany(int|string $companyId): CompanyFraudSettings
    {
        $isAutomotive = $this->companyVerticalQuery->isAutomotive($companyId);

        /** @var CompanyFraudSettings $settings */
        $settings = CompanyFraudSettings::firstOrCreate(
            ['company_id' => $companyId],
            CompanyFraudSettings::defaultsForVertical($isAutomotive),
        );

        return $settings;
    }
}
