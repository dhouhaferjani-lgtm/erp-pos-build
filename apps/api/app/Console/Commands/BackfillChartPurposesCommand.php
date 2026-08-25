<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
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
 * The generic chart already mapped every one of them, so it has no definitions
 * in that (original) arm.
 * The four unconsumed expense purposes (office/travel/meals/utilities) are
 * deliberately NOT backfilled: nothing resolves them, so a brownfield chart
 * without them cannot misbook — they are seeded for new charts only.
 * TN's FX pair and rounding pair are owned by the H-1 (PCN class-6 re-numbering)
 * and H-2 (rounding dust out of 4375) lanes; they must not be created here.
 *
 * O-27 EXTENSION (owner ruling 2026-08-21, LEDGER row O-27; evidence
 * `docs/handoff/reviews/enforcement-p3/M2-reconciliation.md` finding D-2).
 * `SystemAccountPurpose::requiredPurposes()` used to check a strict 14-of-28
 * SUBSET of what {@see ProvisioningRequiredPurposesV1} classifies REQUIRED, so a
 * brownfield tenant missing one of the other fourteen reported `valid: true` and
 * then hard-failed at runtime. The ruling is: backfill the fourteen FIRST, then
 * widen the validation set. This command now also carries those fourteen —
 *
 *   goods_received_not_invoiced, inventory, marketing_goodwill_expense,
 *   payment_tolerance_expense, payment_tolerance_income, pos_tender_clearing,
 *   purchase_expenses, purchase_price_variance_expense,
 *   purchase_price_variance_income, purchase_stamp_duty, rounding_loss_expense,
 *   sales_discount, sales_returns_clearing, voucher_liability
 *
 * — for ALL THREE seeded charts (TN / FR / generic), not just the French plan:
 * the "generic already mapped them" argument holds for the chart as it is
 * seeded TODAY, and a brownfield generic chart provisioned before those rows
 * were added to `GenericChartOfAccountsSeeder` is missing them exactly as a
 * French one is. `sales_discount` is already carried for FR/TN by the original
 * `$frenchPlanShared` arm, so the O-27 arm adds it for the generic chart only —
 * a purpose must appear at most once per country or the run would probe the
 * same row twice.
 *
 * NO CODE IS INVENTED HERE. Every tuple below (code / name / type / parent) is
 * copied verbatim from the frozen country seeder that owns that chart —
 * `TunisiaChartOfAccountsSeeder`, `FranceChartOfAccountsSeeder`,
 * `GenericChartOfAccountsSeeder` — which are the country-defaults authority for
 * the legacy provisioning arm that built every brownfield chart. That copy is
 * not trusted to stay correct by inspection: `ChartPurposeBackfillSeederParityTest`
 * re-derives every tuple from the seeders' own definition tables and fails on
 * any divergence, so the two can never drift.
 *
 * WHAT IT STILL CANNOT FILL — the residual, reported and never guessed. A
 * manifest-REQUIRED purpose with no definition for the company's country is
 * SKIPPED and named, per company, followed by the second stable token
 * `CHART-PURPOSE BACKFILL UNMAPPED REQUIRED: <n>`. Guessing an account code is
 * an owner/expert decision, not a backfill's. That token is deliberately NOT
 * folded into the FAILURES token or the exit code: FAILURES means "a chart this
 * command was asked to repair could not be placed", while UNMAPPED means "this
 * command was never given a mapping for that purpose". The fourteen purposes
 * `requiredPurposes()` always checked are in the second class — a chart missing
 * one of those was already failing `validateCompanyAccounts()` loudly before
 * this lane, so it is a pre-existing operator follow-up rather than a
 * regression this run introduced.
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
 * The O-27 residual token is REVIEWED, not gated — a non-zero count names the
 * tenants whose live-tenant validation will now legitimately report unhealthy:
 *
 *   grep -E 'CHART-PURPOSE BACKFILL UNMAPPED REQUIRED: [1-9]' /tmp/chart-backfill.log
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

    /**
     * Second machine-readable result prefix (O-27), emitted as `<prefix> <n>`
     * on the line before the FAILURES token.
     *
     * Counts (company, purpose) pairs that are manifest-REQUIRED, absent from
     * the chart, and have NO definition this command could fill them from. It
     * is a REVIEW signal, not a gate: see the class docblock for why it is kept
     * out of the FAILURES token and the exit code.
     */
    public const UNMAPPED_TOKEN_PREFIX = 'CHART-PURPOSE BACKFILL UNMAPPED REQUIRED:';

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
        $unmapped = 0;

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
            $definitions = $this->definitions((string) $company->country_code);

            foreach ($definitions as $definition) {
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
                //
                // A NULL `parent_code` is not "parent unknown": it is the
                // seeder's own declaration that the account is a ROOT of the
                // chart. The generic chart declares `5810` (POS tender
                // clearing) that way — see GenericChartOfAccountsSeeder — so a
                // null here must insert a root row, never be reported as a
                // missing parent.
                $parentId = null;

                if ($definition['parent_code'] !== null) {
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
                }

                if ($dryRun) {
                    $this->line(sprintf(
                        '[DRY-RUN] Company %s: would create %s account %s (%s) under parent %s.',
                        $companyId,
                        $definition['type'],
                        $definition['code'],
                        $definition['name'],
                        $definition['parent_code'] ?? '(root)',
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

            $unmapped += $this->reportUnmappableRequiredPurposes($companyId, $definitions);
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

        // O-27 RESIDUAL TOKEN — reviewed, not gated (class docblock). Emitted
        // BEFORE the failures token so the failures token stays the last line,
        // which every existing deploy checklist assumes.
        $this->line(sprintf('%s %d', self::UNMAPPED_TOKEN_PREFIX, $unmapped));

        // STABLE GATE TOKEN — the exit code is swallowed by tenants:run (see the
        // class docblock), so this line is the machine-readable result. Its exact
        // shape is pinned by a test; do not reword it.
        $this->line(sprintf('%s %d', self::SUMMARY_TOKEN_PREFIX, $invalid));

        return $invalid === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Name every manifest-REQUIRED purpose this command has no mapping for and
     * the chart does not already resolve — the O-27 skip-and-report path.
     *
     * Guessing an account code for a purpose whose country mapping was never
     * established is an owner/expert decision, so the command refuses and says
     * so per (company, purpose). The report is the deliberate residual of the
     * `requiredPurposes()` widening: these are the tenants whose live-tenant
     * validation now legitimately reports unhealthy, and an operator assigns
     * the purpose in Settings -> Chart of Accounts.
     *
     * The REQUIRED set comes from the authority's own `requiredPurposes()`
     * accessor, never from filtering `entries()` on the string `'REQUIRED'`:
     * that string is a PRIVATE const of the manifest, so a local filter would
     * compare against a value it cannot see and would FAIL OPEN if the value
     * changed — matching nothing, reporting no residual, and certifying every
     * chart complete.
     *
     * `ProvisioningRequiredPurposesV1::assertConforms()` is NOT called here.
     * This is an unattended repair path; a drifted manifest must not abort a
     * tenant's migration run. The manifest's own conformance is gated in CI
     * (SeededChartManifestRequiredPurposeCompletenessTest).
     *
     * @param  list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}>  $definitions
     */
    private function reportUnmappableRequiredPurposes(string $companyId, array $definitions): int
    {
        $covered = [];
        foreach ($definitions as $definition) {
            $covered[$definition['purpose']] = true;
        }

        $unmapped = 0;

        foreach (ProvisioningRequiredPurposesV1::requiredPurposes() as $requiredPurpose) {
            $purpose = $requiredPurpose->value;
            if (isset($covered[$purpose])) {
                continue;
            }

            $resolves = $this->database->table('accounts')
                ->where('company_id', $companyId)
                ->where('system_purpose', $purpose)
                ->exists();

            if ($resolves) {
                continue;
            }

            $this->warn(sprintf(
                'Company %s does not map REQUIRED purpose %s and this backfill has no account definition for it; '
                .'skipped without guessing a code. Assign it in Settings -> Chart of Accounts.',
                $companyId,
                $purpose,
            ));
            $unmapped++;
        }

        return $unmapped;
    }

    /**
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}>
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
                'type' => 'revenue',
                'parent_code' => '70',
                'purpose' => SystemAccountPurpose::SalesDiscount->value,
            ],
        ] : [];

        return array_merge($frenchOnly, $frenchPlanShared, $this->o27Definitions($country));
    }

    /**
     * O-27: the manifest-REQUIRED purposes `requiredPurposes()` did not check
     * before this lane, per country.
     *
     * Every tuple is a verbatim copy of the corresponding row in the frozen
     * seeder that owns the chart — the country-defaults authority for the
     * legacy provisioning arm — and `ChartPurposeBackfillSeederParityTest`
     * re-derives all of them from those seeders and fails on any divergence.
     * Nothing here is invented; a purpose with no seeder row for a country
     * would be reported by {@see reportUnmappableRequiredPurposes()} instead.
     *
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}>
     */
    private function o27Definitions(string $country): array
    {
        if ($country === 'TN' || $country === 'FR') {
            // The two French-plan charts are identical on every one of these
            // rows except where `5810` hangs: TN has no `58` (Virements
            // internes) header and roots it directly on class `5`.
            return $this->frenchPlanO27Definitions($country === 'TN' ? '5' : '58');
        }

        return $this->genericO27Definitions();
    }

    /**
     * Mirrors TunisiaChartOfAccountsSeeder / FranceChartOfAccountsSeeder.
     *
     * `sales_discount` is deliberately absent: the original `$frenchPlanShared`
     * arm already carries it for FR and TN (`7097`), and a purpose listed twice
     * for one country would probe the same row twice.
     *
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}>
     */
    private function frenchPlanO27Definitions(string $posTenderClearingParent): array
    {
        return [
            [
                'code' => '408',
                'name' => 'Fournisseurs - Factures non parvenues',
                'type' => 'liability',
                'parent_code' => '40',
                'purpose' => SystemAccountPurpose::GoodsReceivedNotInvoiced->value,
            ],
            [
                'code' => '37',
                'name' => 'Stocks de marchandises',
                'type' => 'asset',
                'parent_code' => '3',
                'purpose' => SystemAccountPurpose::Inventory->value,
            ],
            [
                'code' => '6238',
                'name' => 'Dépenses de bonne volonté commerciale',
                'type' => 'expense',
                'parent_code' => '62',
                'purpose' => SystemAccountPurpose::MarketingGoodwillExpense->value,
            ],
            [
                'code' => '6580',
                'name' => 'Écart de règlement (charges)',
                'type' => 'expense',
                'parent_code' => '65',
                'purpose' => SystemAccountPurpose::PaymentToleranceExpense->value,
            ],
            [
                'code' => '7580',
                'name' => 'Écart de règlement (produits)',
                'type' => 'revenue',
                'parent_code' => '75',
                'purpose' => SystemAccountPurpose::PaymentToleranceIncome->value,
            ],
            [
                'code' => '5810',
                'name' => 'Compte d\'attente règlements TPV (bons d\'achat)',
                'type' => 'asset',
                'parent_code' => $posTenderClearingParent,
                'purpose' => SystemAccountPurpose::PosTenderClearing->value,
            ],
            [
                'code' => '607',
                'name' => 'Achats de marchandises',
                'type' => 'expense',
                'parent_code' => '60',
                'purpose' => SystemAccountPurpose::PurchaseExpenses->value,
            ],
            [
                'code' => '6585',
                'name' => 'Écart sur prix d\'achat',
                'type' => 'expense',
                'parent_code' => '65',
                'purpose' => SystemAccountPurpose::PurchasePriceVarianceExpense->value,
            ],
            [
                'code' => '7585',
                'name' => 'Écart sur prix d\'achat',
                'type' => 'revenue',
                'parent_code' => '75',
                'purpose' => SystemAccountPurpose::PurchasePriceVarianceIncome->value,
            ],
            [
                'code' => '6354',
                'name' => 'Droits d\'enregistrement et de timbre',
                'type' => 'expense',
                'parent_code' => '63',
                'purpose' => SystemAccountPurpose::PurchaseStampDuty->value,
            ],
            [
                'code' => '6588',
                'name' => 'Pertes d\'arrondis sur bons d\'achat',
                'type' => 'expense',
                'parent_code' => '65',
                'purpose' => SystemAccountPurpose::RoundingLossExpense->value,
            ],
            [
                'code' => '7091',
                'name' => 'Remboursements clients - Virements bons d\'achat',
                'type' => 'expense',
                'parent_code' => '70',
                'purpose' => SystemAccountPurpose::SalesReturnsClearing->value,
            ],
            [
                'code' => '4197',
                'name' => 'Clients - Bons d\'achat émis (passif courant)',
                'type' => 'liability',
                'parent_code' => '41',
                'purpose' => SystemAccountPurpose::VoucherLiability->value,
            ],
        ];
    }

    /**
     * Mirrors GenericChartOfAccountsSeeder — the chart every non-TN/FR country
     * receives through `ChartOfAccountsService::getSeederForCountry()`'s
     * `default` arm.
     *
     * Unlike the French-plan arm this one DOES carry `sales_discount` (`7091`
     * on this chart), because the original `$frenchPlanShared` arm never
     * covered the generic chart. `5810` is declared with a NULL parent by the
     * seeder — it is a root account on this chart, not a missing parent.
     *
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}>
     */
    private function genericO27Definitions(): array
    {
        return [
            [
                'code' => '4080',
                'name' => 'Goods Received Not Invoiced',
                'type' => 'liability',
                'parent_code' => '4000',
                'purpose' => SystemAccountPurpose::GoodsReceivedNotInvoiced->value,
            ],
            [
                'code' => '3700',
                'name' => 'Goods for Resale',
                'type' => 'asset',
                'parent_code' => '3000',
                'purpose' => SystemAccountPurpose::Inventory->value,
            ],
            [
                'code' => '6238',
                'name' => 'Marketing Goodwill Expense',
                'type' => 'expense',
                'parent_code' => '6000',
                'purpose' => SystemAccountPurpose::MarketingGoodwillExpense->value,
            ],
            [
                'code' => '6580',
                'name' => 'Payment Tolerance Expense',
                'type' => 'expense',
                'parent_code' => '6000',
                'purpose' => SystemAccountPurpose::PaymentToleranceExpense->value,
            ],
            [
                'code' => '7580',
                'name' => 'Payment Tolerance Income',
                'type' => 'revenue',
                'parent_code' => '7000',
                'purpose' => SystemAccountPurpose::PaymentToleranceIncome->value,
            ],
            [
                'code' => '5810',
                'name' => 'POS Tender Clearing (Voucher Redemption)',
                'type' => 'asset',
                'parent_code' => null,
                'purpose' => SystemAccountPurpose::PosTenderClearing->value,
            ],
            [
                'code' => '6070',
                'name' => 'Purchase Expenses',
                'type' => 'expense',
                'parent_code' => '6000',
                'purpose' => SystemAccountPurpose::PurchaseExpenses->value,
            ],
            [
                'code' => '6585',
                'name' => 'Écart sur prix d\'achat',
                'type' => 'expense',
                'parent_code' => '6000',
                'purpose' => SystemAccountPurpose::PurchasePriceVarianceExpense->value,
            ],
            [
                'code' => '7585',
                'name' => 'Écart sur prix d\'achat',
                'type' => 'revenue',
                'parent_code' => '7000',
                'purpose' => SystemAccountPurpose::PurchasePriceVarianceIncome->value,
            ],
            [
                'code' => '6350',
                'name' => 'Purchase Stamp Duty',
                'type' => 'expense',
                'parent_code' => '6000',
                'purpose' => SystemAccountPurpose::PurchaseStampDuty->value,
            ],
            [
                'code' => '6588',
                'name' => 'Rounding Loss Expense (Voucher)',
                'type' => 'expense',
                'parent_code' => '6000',
                'purpose' => SystemAccountPurpose::RoundingLossExpense->value,
            ],
            [
                'code' => '7091',
                'name' => 'Sales Discounts',
                'type' => 'revenue',
                'parent_code' => '7000',
                'purpose' => SystemAccountPurpose::SalesDiscount->value,
            ],
            [
                'code' => '7092',
                'name' => 'Sales Returns Clearing (Voucher)',
                'type' => 'expense',
                'parent_code' => '7000',
                'purpose' => SystemAccountPurpose::SalesReturnsClearing->value,
            ],
            [
                'code' => '4197',
                'name' => 'Voucher Liability',
                'type' => 'liability',
                'parent_code' => '4000',
                'purpose' => SystemAccountPurpose::VoucherLiability->value,
            ],
        ];
    }
}
