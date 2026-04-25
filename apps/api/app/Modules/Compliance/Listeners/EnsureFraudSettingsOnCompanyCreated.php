<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Listeners;

use App\Modules\Company\Domain\Events\CompanyCreated;
use App\Modules\Compliance\Application\Services\CompanyFraudSettingsService;
use Illuminate\Support\Facades\Log;

final class EnsureFraudSettingsOnCompanyCreated
{
    public function __construct(
        private readonly CompanyFraudSettingsService $fraudSettingsService,
    ) {}

    public function handle(CompanyCreated $event): void
    {
        try {
            $this->fraudSettingsService->ensureForCompany($event->companyId);
        } catch (\Throwable $e) {
            Log::error('Failed to create fraud settings for new company', [
                'company_id' => $event->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
