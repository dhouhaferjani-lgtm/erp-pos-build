<?php

declare(strict_types=1);

namespace App\Modules\Company\Infrastructure\Services;

use App\Modules\Company\Domain\Company;
use App\Shared\Contracts\Company\CompanyVerticalQueryContract;

final class CompanyVerticalQueryService implements CompanyVerticalQueryContract
{
    public function isAutomotive(int|string $companyId): bool
    {
        /** @var Company|null $company */
        $company = Company::with('tenant')->find($companyId);

        return $company?->tenant?->vertical?->isAutomotive() ?? false;
    }

    public function isRetail(int|string $companyId): bool
    {
        /** @var Company|null $company */
        $company = Company::with('tenant')->find($companyId);

        return ! ($company?->tenant?->vertical?->isAutomotive() ?? true);
    }
}
