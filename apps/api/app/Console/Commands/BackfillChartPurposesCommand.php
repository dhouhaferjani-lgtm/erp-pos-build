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
 * Backfill the country-chart system purposes that the per-country COA seeders
 * gained in the document-per-action seeder-gap lane (register E-1 / H-5 / G-4).
 *
 * The chart of accounts is written ONCE, at company provisioning, by
 * the frozen country chart seeders — and
 * those seeders never rewrite an existing row. A chart created before a purpose
 * was added therefore stays broken forever unless a backfill promotes it, which
 * is exactly what {@see BackfillTolerancePurposesCommand} exists for; this
 * command is its sibling and copies its contract verbatim:
 *
 *   - PURPOSE FIRST, CODE SECOND. `accounts_company_purpose_unique` is
 *     UNIQUE(company_id, system_purpose) and every consumer resolves BY PURPOSE
 *     ({@see Account::findByPurpose}), so a chart
 *     that already carries the purpose on a different code is already CORRECT
 *     and is reported, never rewritten — creating a second holder would abort
 *     the whole tenant run with a QueryException.
 *   - Shape validation on both branches (type + is_active), refusing to
 *     repurpose an account that already carries a DIFFERENT purpose.
 *   - CREATE never invents a parent: a chart missing the parent class header is
 *     reported as a failure, not silently rooted.
 *
 * WHAT IT COVERS. Only purposes with live consumers that a brownfield chart
 * cannot otherwise resolve:
 *   FR — CostOfGoodsSold (PostCOGSOnInvoice booked nothing at all),
 *        GeneralExpense (expense-document lane), CustomerAdvance /
 *        SupplierAdvance (both in requiredPurposes(), so the chart failed
 *        validateCompanyAccounts outright), UninvoicedRevenue, SalesDiscount.
 *   TN — UninvoicedRevenue, SalesDiscount (TN already mapped the rest).
 * The generic chart already mapped every one of them, so it has no definitions.
 * The four unconsumed expense purposes (office/travel/meals/utilities) are
 * deliberately NOT backfilled: nothing resolves them, so a brownfield chart
 * without them cannot misbook — they are seeded for new charts only.
 * TN's FX pair and rounding pair are owned by the H-1 (PCN class-6 re-numbering)
 * and H-2 (rounding dust out of 4375) lanes; they must not be created here.
 *
 * INVOCATION — tenant-DB-scoped; run via `tenants:run`, exactly like the
 * tolerance backfill (no `--tenant` flag by design; boolean flags are passed as
 * `--option='dry-run=1'`):
 *
 *   php artisan tenants:run accounting:backfill-chart-purposes --option='dry-run=1'
 *   php artisan tenants:run accounting:backfill-chart-purposes
 *
 * THE EXIT CODE IS NOT A GATE under `tenants:run` (the runner swallows it), so
 * the last line is a stable machine-readable token — `CHART-PURPOSE BACKFILL
 * FAILURES: <n>`. Gate on BOTH halves:
 *
 *   ! grep -qE 'CHART-PURPOSE BACKFILL FAILURES: [1-9]' /tmp/chart-backfill.log
 *   test "$(grep -c 'CHART-PURPOSE BACKFILL FAILURES:' /tmp/chart-backfill.log)" -eq "$TENANT_COUNT"
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: `companies` and `accounts` are TENANT tables, so under
 *   database-per-tenant this reads ONLY the tenant database `tenants:run` binds around it. A bare run on the
 *   CENTRAL connection is stopped before any query by the fail-closed Schema guard that opens handle(), and the
 *   ABSENCE of the summary token is itself the deploy-gate failure signal.
 */
final class BackfillChartPurposesCommand extends Command
{
    protected $signature = 'accounting:backfill-chart-purposes
                            {--dry-run : Report changes without writing accounts}';

    protected $description = 'Backfill the country-chart system purposes (COGS, general expense, advances, uninvoiced revenue, sales discount) for every company.';

    /**
     * Machine-readable result prefix, emitted as `<prefix> <n>` on the last line.
     *
     * Deploy checklists gate on this token because `tenants:run` swallows the
     * exit code. Pinned by BackfillChartPurposesCommandTest — changing it
     * silently breaks every checklist that greps for it.
     */
    public const SUMMARY_TOKEN_PREFIX = 'CHART-PURPOSE BACKFILL FAILURES:';

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

        // Company uses SoftDeletes and this is a raw query builder with no model
        // scope: a trashed company would otherwise inflate the deploy-gate token
        // or, worse, have system accounts written into it.
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
                            'Company %s account %s carries system_purpose %s but is inactive; activate it before posting.',
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
                            'Company %s account %s is inactive; activate it before posting. Account was skipped.',
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
                        'Company %s is missing parent account %s; account %s (%s) was skipped.',
                        $companyId,
                        $definition['parent_code'],
                        $definition['code'],
                        $definition['purpose'],
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
            '%sChart purpose backfill: %d account(s) %s; %d promoted; %d already satisfied; %d invalid.',
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
        $country = strtoupper($countryCode);

        // Purposes every chart but France already mapped. The codes and names
        // mirror FranceChartOfAccountsSeeder exactly (PCG 603 / 628); see that
        // file for why 603 and not 607, and 628 and not 65.
        $frenchOnly = $country === 'FR' ? [
            [
                'code' => '603',
                'name' => 'Variation des stocks (approvisionnements et marchandises)',
                'type' => 'expense',
                'parent_code' => '60',
                'purpose' => SystemAccountPurpose::CostOfGoodsSold->value,
            ],
            [
                'code' => '628',
                'name' => 'Divers',
                'type' => 'expense',
                'parent_code' => '62',
                'purpose' => SystemAccountPurpose::GeneralExpense->value,
            ],
            [
                'code' => '409',
                'name' => 'Fournisseurs débiteurs',
                'type' => 'asset',
                'parent_code' => '40',
                'purpose' => SystemAccountPurpose::SupplierAdvance->value,
            ],
            [
                'code' => '419',
                'name' => 'Clients créditeurs',
                'type' => 'liability',
                'parent_code' => '41',
                'purpose' => SystemAccountPurpose::CustomerAdvance->value,
            ],
        ] : [];

        // Shared by the two French-plan charts (FR + TN); the generic chart
        // already maps both on 4180 / 7091.
        $frenchPlanShared = in_array($country, ['FR', 'TN'], true) ? [
            [
                'code' => '418',
                'name' => 'Clients - Produits non encore facturés',
                'type' => 'asset',
                'parent_code' => '41',
                'purpose' => SystemAccountPurpose::UninvoicedRevenue->value,
            ],
            [
                'code' => '7097',
                'name' => 'Rabais, remises et ristournes accordés sur ventes de marchandises',
                'type' => 'expense',
                'parent_code' => '70',
                'purpose' => SystemAccountPurpose::SalesDiscount->value,
            ],
        ] : [];

        return array_merge($frenchOnly, $frenchPlanShared);
    }
}
