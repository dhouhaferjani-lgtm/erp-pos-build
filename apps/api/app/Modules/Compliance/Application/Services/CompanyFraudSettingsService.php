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
     * one with vertical-aware defaults: Otospex companies (automotive) get
     * blind cash counting enabled; IziPOS companies (retail / all others)
     * get it disabled.
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
