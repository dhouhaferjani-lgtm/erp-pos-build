<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Repositories;

use App\Modules\Compliance\Domain\CompanyFraudSettings;

final class CompanyFraudSettingsRepository
{
    public function findByCompany(string $companyId): ?CompanyFraudSettings
    {
        return CompanyFraudSettings::where('company_id', $companyId)->first();
    }
}
