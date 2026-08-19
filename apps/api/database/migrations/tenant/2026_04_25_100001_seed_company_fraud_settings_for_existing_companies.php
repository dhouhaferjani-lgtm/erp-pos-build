<?php

declare(strict_types=1);

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfill a company_fraud_settings row for every company that does not
     * already have one. Blind cash counting is enabled for every vertical.
     *
     * Uses chunk(200) with eager-loaded tenant to avoid N+1 queries.
     * Calls CompanyFraudSettings::defaultsForVertical() directly — the service
     * layer cannot be injected into a migration.
     */
    public function up(): void
    {
        // Collect IDs that already have settings to skip them in one query.
        $existing = CompanyFraudSettings::query()
            ->pluck('company_id')
            ->flip()
            ->all();

        Company::with('tenant')
            ->chunk(200, function ($companies) use ($existing): void {
                foreach ($companies as $company) {
                    if (isset($existing[$company->id])) {
                        continue;
                    }

                    $isAutomotive = $company->tenant?->vertical instanceof Vertical
                        && $company->tenant->vertical->isAutomotive();

                    $defaults = CompanyFraudSettings::defaultsForVertical($isAutomotive);
                    $defaults['company_id'] = $company->id;

                    CompanyFraudSettings::create($defaults);
                }
            });
    }

    public function down(): void
    {
        // Forward-only backfill — no rollback needed.
    }
};
