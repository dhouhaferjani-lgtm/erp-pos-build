<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Backfill the collected sales stamp-duty (timbre) liability account for existing
 * Tunisian companies.
 *
 * Before this, sales invoice GL lumped the timbre into the AR debit with no
 * matching credit (unbalanced JE). The fix credits collected stamp duty to a
 * dedicated liability (4375 — État, droit de timbre à reverser) mapped to
 * SystemAccountPurpose::SalesStampDutyPayable. New companies get it from
 * TunisiaChartOfAccountsSeeder; existing ones need this one-time backfill.
 *
 * Idempotent: skips companies that already have the purpose mapped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $tnCompanies = Company::where('country_code', 'TN')->get();

        foreach ($tnCompanies as $company) {
            // Skip if this company has no chart at all (nothing to attach to) or
            // already has the stamp-duty purpose mapped.
            $accountCount = DB::table('accounts')->where('company_id', $company->id)->count();
            if ($accountCount === 0) {
                continue;
            }

            $alreadyMapped = DB::table('accounts')
                ->where('company_id', $company->id)
                ->where('system_purpose', SystemAccountPurpose::SalesStampDutyPayable->value)
                ->exists();
            if ($alreadyMapped) {
                continue;
            }

            // Parent: the company's "État et collectivités publiques" (44) group,
            // alongside TVA collectée (4457). Fall back to no parent if absent.
            $parentId = DB::table('accounts')
                ->where('company_id', $company->id)
                ->where('code', '44')
                ->value('id');

            DB::table('accounts')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'parent_id' => $parentId,
                'code' => '4375',
                'name' => 'État - Droit de timbre à reverser',
                'type' => 'liability',
                'system_purpose' => SystemAccountPurpose::SalesStampDutyPayable->value,
                'is_active' => true,
                'is_system' => true,
                'balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            Log::info("Migration: created sales stamp-duty account 4375 for company {$company->id} ({$company->name}).");
        }
    }

    public function down(): void
    {
        // Data correction (adds a missing system account) — not reversed to avoid
        // deleting an account that may already carry posted journal lines.
    }
};
