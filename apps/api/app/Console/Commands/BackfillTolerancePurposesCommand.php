<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ensure every company can post the POS rounding / tolerance entries
 * (spec §4.2). Brownfield charts created before the 6580/7580 seeds exist
 * without them, and `GeneralLedgerService::hasAccountForPurpose` is
 * non-throwing — so without this backfill the bridge would silently SKIP
 * the entries and only emit an alert.
 *
 * Follows BackfillPayableInstrumentAccountsCommand:
 *   - validate the shape of an existing code-matched account (type/active),
 *   - PROMOTE it when it merely lacks `system_purpose`,
 *   - HARD-FAIL when the parent account is absent (never invent a parent).
 *
 * PURPOSE FIRST, CODE SECOND. `accounts_company_purpose_unique` is
 * UNIQUE(company_id, system_purpose), and every consumer resolves the account
 * BY PURPOSE ({@see Account::findByPurpose} —
 * the code is never matched). A brownfield chart that already carries the
 * tolerance purpose on a LEGACY code (the enum's own comments say 658/758) is
 * therefore already correct; creating or promoting 6580/7580 alongside it
 * would violate that unique index and abort the entire tenant run with a
 * QueryException. Such charts are reported and left untouched. The same
 * precedence is what `2026_07_04_120000_backfill_purchase_price_variance_accounts`
 * uses (`Account::findByPurpose(...) !== null` short-circuit).
 *
 * Parent resolution is a LOOKUP per definition, deliberately lazy: the parent
 * is only required on the create path. `7580` is declared BEFORE `75` in the
 * chart seeders, so nothing here may assume declaration order, and a chart
 * that already has the account must not fail merely because a parent code is
 * shaped differently.
 *
 * INVOCATION — tenant-DB-scoped; run via `tenants:run`. There is deliberately
 * NO `--tenant` flag: the tenancy runner switches the default connection per
 * tenant, and a flag would invite half-applied state. stancl/tenancy's runner
 * takes the command NAME as its single argument and forwards flags ONLY
 * through repeatable `--option='k=v'` pairs (`vendor/stancl/tenancy/src/Commands/Run.php`
 * signature + the `--option` reduce). There is no `--` passthrough; Symfony
 * rejects it. Boolean flags are passed as `=1`:
 *
 *   php artisan tenants:run accounting:backfill-tolerance-purposes --option='dry-run=1'
 *   php artisan tenants:run accounting:backfill-tolerance-purposes
 *
 * THE EXIT CODE IS NOT A GATE under `tenants:run`: `Run::handle()` returns null
 * after `$this->call(...)`, so the child's status is swallowed and the runner
 * always exits 0. This command therefore emits ONE stable summary token per
 * tenant as its last line — `TOLERANCE-PURPOSE BACKFILL FAILURES: <n>` — which
 * deploy checklists gate on. The gate needs BOTH halves:
 *
 *   php artisan tenants:run accounting:backfill-tolerance-purposes \
 *     | tee /tmp/tolerance-backfill.log
 *
 *   # (a) no tenant reported a failure — NEVER `grep -q '… : 0'`, which passes
 *   #     as soon as ANY ONE tenant is clean and lets a "FAILURES: 3" through:
 *   ! grep -qE 'TOLERANCE-PURPOSE BACKFILL FAILURES: [1-9]' /tmp/tolerance-backfill.log
 *
 *   # (b) every tenant actually reported — the token count must equal the
 *   #     tenant count, since ABSENCE of the token means the command aborted
 *   #     before finishing (e.g. the Schema guard tripped) and is a FAILURE:
 *   test "$(grep -c 'TOLERANCE-PURPOSE BACKFILL FAILURES:' /tmp/tolerance-backfill.log)" -eq "$TENANT_COUNT"
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: every table it touches is a TENANT table, so post-2026-05-28
 *   (database-per-tenant) it reads ONLY the tenant database `tenants:run` binds around it. A bare run on the CENTRAL
 *   connection is stopped BEFORE any query by the fail-closed
 *   `Schema::hasTable('companies') || Schema::hasTable('accounts')` guard that opens `handle()` (:99-105), which
 *   returns FAILURE with an operator message — and, per the gate recipe above, ABSENCE of the summary token is itself
 *   the failure signal. (Corrected 2026-08-05, wave-2 tenancy review R4: the annotation previously claimed a bare run
 *   "raises 42P01", which the guard makes impossible.) Invoke exclusively via `php artisan tenants:run …`.
 */
final class BackfillTolerancePurposesCommand extends Command
{
    protected $signature = 'accounting:backfill-tolerance-purposes
                            {--dry-run : Report changes without writing accounts}';

    protected $description = 'Backfill the payment-tolerance expense (6580) and income (7580) accounts for every company.';

    /**
     * Machine-readable result prefix, emitted as `<prefix> <n>` on the last line.
     *
     * Deploy checklists gate on this token because `tenants:run` swallows the
     * exit code. Pinned by BackfillTolerancePurposesCommandTest — changing it
     * silently breaks every checklist that greps for it.
     */
    public const SUMMARY_TOKEN_PREFIX = 'TOLERANCE-PURPOSE BACKFILL FAILURES:';

    public function __construct(private readonly DatabaseManager $database)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('companies') || ! Schema::hasTable('accounts')) {
            $this->error(
                'Tenant accounting tables are unavailable. Run this command inside each tenant context (for example via tenants:run).',
            );

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $promoted = 0;
        $satisfied = 0;
        $invalid = 0;

        // Company soft-deletes ({@see \App\Modules\Company\Domain\Company} uses
        // SoftDeletes), and this is a raw query builder with no model scope. A
        // trashed company would otherwise be (a) reported as a chart failure —
        // inflating the deploy-gate token for an otherwise healthy tenant — or,
        // worse, (b) have system accounts WRITTEN into it.
        $companies = $this->database->table('companies')
            ->whereNull('deleted_at')
            ->select(['id', 'tenant_id', 'country_code'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $companyId = (string) $company->id;

            foreach ($this->definitions((string) $company->country_code) as $definition) {
                // 1. PURPOSE FIRST — a chart that already resolves is done,
                //    whatever code carries the purpose (see the class docblock).
                $holder = $this->database->table('accounts')
                    ->where('company_id', $companyId)
                    ->where('system_purpose', $definition['purpose'])
                    ->first();

                if ($holder !== null) {
                    // The holder's TYPE must be validated exactly as the
                    // code-matched branch validates it. A revenue account
                    // holding payment_tolerance_expense — or a chart with the
                    // two purposes swapped — otherwise reports a clean gate
                    // while createRepositoryAdjustmentJournalEntry debits cash
                    // losses to a revenue account.
                    if ((string) $holder->type !== $definition['type']) {
                        $this->error(sprintf(
                            'Company %s account %s carries system_purpose %s but has wrong type %s; expected %s. Account was skipped.',
                            $companyId,
                            (string) $holder->code,
                            $definition['purpose'],
                            (string) $holder->type,
                            $definition['type'],
                        ));
                        $invalid++;

                        continue;
                    }

                    if (! (bool) $holder->is_active) {
                        $this->error(sprintf(
                            'Company %s account %s carries system_purpose %s but is inactive; activate it before enabling POS rounding.',
                            $companyId,
                            (string) $holder->code,
                            $definition['purpose'],
                        ));
                        $invalid++;

                        continue;
                    }

                    if ((string) $holder->code !== $definition['code']) {
                        $this->line(sprintf(
                            'Company %s: system_purpose %s already held by account %s (expected code %s); left untouched.',
                            $companyId,
                            $definition['purpose'],
                            (string) $holder->code,
                            $definition['code'],
                        ));
                    }

                    $satisfied++;

                    continue;
                }

                // 2. CODE SECOND — an account at the canonical code that merely
                //    lacks the purpose is promoted; anything else is reported.
                $existing = $this->database->table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $definition['code'])
                    ->first();

                if ($existing !== null) {
                    if ((string) $existing->type !== $definition['type']) {
                        $this->error(sprintf(
                            'Company %s account %s has wrong type %s; expected %s. Account was skipped.',
                            $companyId,
                            $definition['code'],
                            (string) $existing->type,
                            $definition['type'],
                        ));
                        $invalid++;

                        continue;
                    }

                    if (! (bool) $existing->is_active) {
                        $this->error(sprintf(
                            'Company %s account %s is inactive; activate it before enabling POS rounding. Account was skipped.',
                            $companyId,
                            $definition['code'],
                        ));
                        $invalid++;

                        continue;
                    }

                    // The purpose lookup above already excluded a match, so a
                    // non-null purpose here is necessarily a DIFFERENT one.
                    if ($existing->system_purpose !== null) {
                        $this->error(sprintf(
                            'Company %s account %s already carries system_purpose %s; refusing to repurpose it.',
                            $companyId,
                            $definition['code'],
                            (string) $existing->system_purpose,
                        ));
                        $invalid++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line(sprintf(
                            '[DRY-RUN] Company %s: would promote account %s to system_purpose %s.',
                            $companyId,
                            $definition['code'],
                            $definition['purpose'],
                        ));
                    } else {
                        $this->database->table('accounts')
                            ->where('id', $existing->id)
                            ->update([
                                'system_purpose' => $definition['purpose'],
                                'is_system' => true,
                                'updated_at' => now(),
                            ]);
                    }
                    $promoted++;

                    continue;
                }

                // 3. CREATE — the only path that needs a parent. Never invent one.
                $parentId = $this->database->table('accounts')
                    ->where('company_id', $companyId)
                    ->where('code', $definition['parent_code'])
                    ->value('id');

                if (! is_string($parentId)) {
                    $this->error(sprintf(
                        'Company %s is missing parent account %s; tolerance account %s was skipped.',
                        $companyId,
                        $definition['parent_code'],
                        $definition['code'],
                    ));
                    $invalid++;

                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[DRY-RUN] Company %s: would create %s account %s (%s) under parent %s.',
                        $companyId,
                        $definition['type'],
                        $definition['code'],
                        $definition['name'],
                        $definition['parent_code'],
                    ));
                    $created++;

                    continue;
                }

                $now = now();
                $this->database->table('accounts')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => (string) $company->tenant_id,
                    'company_id' => $companyId,
                    'parent_id' => $parentId,
                    'code' => $definition['code'],
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'system_purpose' => $definition['purpose'],
                    'is_active' => true,
                    'is_system' => true,
                    'balance' => '0.000',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info(sprintf(
            '%sTolerance purpose backfill: %d account(s) %s; %d promoted; %d already satisfied; %d invalid.',
            $prefix,
            $created,
            $dryRun ? 'would be created' : 'created',
            $promoted,
            $satisfied,
            $invalid,
        ));

        // STABLE GATE TOKEN — the exit code is swallowed by tenants:run (see the
        // class docblock), so this line is the machine-readable result. Its exact
        // shape is pinned by a test; do not reword it.
        $this->line(sprintf('%s %d', self::SUMMARY_TOKEN_PREFIX, $invalid));

        return $invalid === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{code: string, name: string, type: string, parent_code: string, purpose: string}>
     */
    private function definitions(string $countryCode): array
    {
        $isFrenchPlan = in_array(strtoupper($countryCode), ['TN', 'FR'], true);

        return [
            [
                'code' => '6580',
                'name' => $isFrenchPlan ? 'Écart de règlement (charges)' : 'Payment Tolerance Expense',
                'type' => 'expense',
                'parent_code' => $isFrenchPlan ? '65' : '6000',
                'purpose' => SystemAccountPurpose::PaymentToleranceExpense->value,
            ],
            [
                'code' => '7580',
                'name' => $isFrenchPlan ? 'Écart de règlement (produits)' : 'Payment Tolerance Income',
                'type' => 'revenue',
                'parent_code' => $isFrenchPlan ? '75' : '7000',
                'purpose' => SystemAccountPurpose::PaymentToleranceIncome->value,
            ],
        ];
    }
}
