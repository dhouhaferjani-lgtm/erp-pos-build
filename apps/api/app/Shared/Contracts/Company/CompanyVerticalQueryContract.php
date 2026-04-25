<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Company;

interface CompanyVerticalQueryContract
{
    public function isAutomotive(int|string $companyId): bool;

    public function isRetail(int|string $companyId): bool;
}
