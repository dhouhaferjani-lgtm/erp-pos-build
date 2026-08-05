<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * v3-refund-chain-integration spec §5.3 (T1 errata, renamed from
 * `BackfillRefundWriteOffAccountCommand`) — idempotently provisions
 * whichever of `SystemAccountPurpose::RefundWriteOff` /
 * `SystemAccountPurpose::SalesReturn` is missing for an already-existing
 * tenant chart.
 *
 * **`SalesReturn` — patch the missing `system_purpose` key on the
 * EXISTING conventional-code account ONLY (Errata 4.3, binding).** Mirrors
 * `TunisiaChartOfAccountsSeeder.php:47-52`'s own never-rewrite precedent:
 * "the seeder never rewrites existing rows... Promote ONLY the
 * `is_system` flag (never name/type/purpose — user edits stay
 * untouched)." An account's `type` directly drives
 * `ProfitLossService::queryAccountBalances()`'s balance formula, so
 * retroactively flipping a live account's `type` on an already-
 * provisioned tenant would re-sign every historical journal line ever
 * posted to it. This command writes ONLY the `system_purpose` column and
 * NEVER touches `type` — an already-provisioned FR/TN tenant's 709
 * account keeps whatever `type` it was created with (§5.3's accepted,
 * deliberate divergence between the installed base and fresh charts).
 *
 * **`RefundWriteOff` — a genuinely NEW account for any pre-existing
 * chart.** No pre-existing row can carry a stale `type` for a purpose
 * that didn't exist before this feature, so this half creates the row
 * fresh (matching the seeder's own code/name/type) when it's missing
 * entirely, and idempotently patches-only-the-purpose in the (unlikely)
 * case a same-coded row already exists without one.
 */
final class BackfillRefundCompensationAccountsCommand extends TenantScopedCommand
{
    private const FR_TN_SALES_RETURN_CODE = '709';

    private const FR_TN_REFUND_WRITE_OFF = ['code' => '6590', 'name' => 'Perte sur remboursement (write-off)', 'parent_code' => '65'];

    private const GENERIC_SALES_RETURN_CODE = '7090';

    private const GENERIC_REFUND_WRITE_OFF = ['code' => '6590', 'name' => 'Refund Write-Off', 'parent_code' => '6000'];

    /** @var string */
    protected $signature = 'accounting:backfill-refund-compensation-accounts
        {--tenant= : restrict tenant iteration to one tenant id}
        {--dry-run : report matching changes without writing anything}';

    /** @var string */
    protected $description = 'Idempotently provision the RefundWriteOff and SalesReturn system-purpose accounts for every already-existing company chart.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly DatabaseManager $database,
        private readonly GeneralLedgerService $generalLedgerService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $tenantFilter = $this->stringOption('tenant');
        $dryRun = $this->option('dry-run') === true;

        $purposePatched = 0;
        $created = 0;
        $skipped = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (
            $tenantFilter,
            $dryRun,
            &$purposePatched,
            &$created,
            &$skipped,
        ): int {
            if ($tenantFilter !== null && $tenant->id !== $tenantFilter) {
                return self::SUCCESS;
            }

            $companies = Company::query()->where('tenant_id', $tenant->id)->get();

            foreach ($companies as $company) {
                $accountCount = $this->database->table('accounts')->where('company_id', $company->id)->count();
                if ($accountCount === 0) {
                    // No chart at all for this company — nothing to attach to;
                    // out of scope for a purpose-provisioning backfill.
                    continue;
                }

                $isFrTn = in_array(strtoupper((string) $company->country_code), ['FR', 'TN'], true);
                $salesReturnCode = $isFrTn ? self::FR_TN_SALES_RETURN_CODE : self::GENERIC_SALES_RETURN_CODE;
                $writeOffDefinition = $isFrTn ? self::FR_TN_REFUND_WRITE_OFF : self::GENERIC_REFUND_WRITE_OFF;

                [$patched, $skip] = $this->backfillSalesReturnPurpose($company, $salesReturnCode, $dryRun);
                $purposePatched += $patched;
                $skipped += $skip;

                [$createdCount, $patchedCount, $skip] = $this->backfillRefundWriteOffAccount($company, $writeOffDefinition, $dryRun);
                $created += $createdCount;
                $purposePatched += $patchedCount;
                $skipped += $skip;
            }

            return self::SUCCESS;
        });

        // An operator-targeted --tenant that was never reached (absent from the
        // directory, or skipped by forEachTenant()'s database probe) must not
        // exit SUCCESS having backfilled nothing — a one-time backfill that
        // silently misses a tenant is permanently missed.
        if (($unvisited = $this->failIfTenantFilterUnvisited($tenantFilter)) !== null) {
            return $unvisited;
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info(sprintf(
            '%sRefund compensation account backfill: %d purpose(s) %s; %d account(s) %s; %d skipped.',
            $prefix,
            $purposePatched,
            $dryRun ? 'would be patched' : 'patched',
            $created,
            $dryRun ? 'would be created' : 'created',
            $skipped,
        ));

        return $exit;
    }

    /**
     * @return array{0: int, 1: int} [purposePatchedCount, skippedCount]
     */
    private function backfillSalesReturnPurpose(Company $company, string $code, bool $dryRun): array
    {
        if ($this->generalLedgerService->hasAccountForPurpose((string) $company->id, SystemAccountPurpose::SalesReturn)) {
            return [0, 0];
        }

        $account = $this->database->table('accounts')
            ->where('company_id', $company->id)
            ->where('code', $code)
            ->first();

        if ($account === null) {
            $this->warn(sprintf(
                'Company %s has no account %s to attach SalesReturn purpose to; skipped.',
                $company->id,
                $code,
            ));

            return [0, 1];
        }

        if ($account->system_purpose !== null) {
            // Already carries SOME other purpose — never overwrite.
            $this->warn(sprintf(
                'Company %s account %s already has system_purpose=%s; SalesReturn was NOT applied.',
                $company->id,
                $code,
                (string) $account->system_purpose,
            ));

            return [0, 1];
        }

        if ($dryRun) {
            $this->line(sprintf('[DRY-RUN] Company %s: would set account %s system_purpose=sales_return (type untouched).', $company->id, $code));

            return [1, 0];
        }

        // Errata 4.3 — ONLY system_purpose is written. `type` is never
        // touched, regardless of what it currently is.
        $this->database->table('accounts')
            ->where('id', $account->id)
            ->update(['system_purpose' => SystemAccountPurpose::SalesReturn->value, 'updated_at' => now()]);

        return [1, 0];
    }

    /**
     * @param  array{code: string, name: string, parent_code: string}  $definition
     * @return array{0: int, 1: int, 2: int} [createdCount, purposePatchedCount, skippedCount]
     */
    private function backfillRefundWriteOffAccount(Company $company, array $definition, bool $dryRun): array
    {
        if ($this->generalLedgerService->hasAccountForPurpose((string) $company->id, SystemAccountPurpose::RefundWriteOff)) {
            return [0, 0, 0];
        }

        $existing = $this->database->table('accounts')
            ->where('company_id', $company->id)
            ->where('code', $definition['code'])
            ->first();

        if ($existing !== null) {
            if ($existing->system_purpose !== null) {
                $this->warn(sprintf(
                    'Company %s account %s already has system_purpose=%s; RefundWriteOff was NOT applied.',
                    $company->id,
                    $definition['code'],
                    (string) $existing->system_purpose,
                ));

                return [0, 0, 1];
            }

            if ($dryRun) {
                $this->line(sprintf('[DRY-RUN] Company %s: would set account %s system_purpose=refund_write_off (type untouched).', $company->id, $definition['code']));

                return [0, 1, 0];
            }

            $this->database->table('accounts')
                ->where('id', $existing->id)
                ->update(['system_purpose' => SystemAccountPurpose::RefundWriteOff->value, 'updated_at' => now()]);

            return [0, 1, 0];
        }

        if ($dryRun) {
            $this->line(sprintf('[DRY-RUN] Company %s: would create expense account %s (%s).', $company->id, $definition['code'], $definition['name']));

            return [1, 0, 0];
        }

        $parentId = $this->database->table('accounts')
            ->where('company_id', $company->id)
            ->where('code', $definition['parent_code'])
            ->value('id');

        $now = now();
        $this->database->table('accounts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => (string) $company->tenant_id,
            'company_id' => (string) $company->id,
            'parent_id' => $parentId,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::RefundWriteOff->value,
            'is_active' => true,
            'is_system' => true,
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [1, 0, 0];
    }
}
