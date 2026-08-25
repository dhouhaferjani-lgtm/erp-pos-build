<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W4-9 deploy check — `pos:census-vat-legs`.
 *
 * The fix changes what NEW receipts post; journal entries are immutable
 * (rule 8), so every receipt booked before it stays wrong. The census is how an
 * operator finds out whether a given tenant has any, and it must be able to say
 * "none" as confidently as it says "twelve" — a census that cannot report clean
 * is not a deploy check, it is noise.
 */
final class PosReceiptVatLegCensusCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $cashierId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $this->cashierId = User::factory()->create(['tenant_id' => $this->tenantId])->id;

        $this->app->make(ChartOfAccountsService::class)->seedForCompany(
            Company::query()->findOrFail($this->companyId),
        );
    }

    public function test_reports_clean_when_every_vat_bearing_receipt_has_a_vat_leg(): void
    {
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($receipt, withVatLeg: true);

        [$code, $output] = $this->runCensus();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('none', $output);
    }

    public function test_flags_a_receipt_booked_without_a_vat_leg(): void
    {
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($receipt, withVatLeg: false);

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString((string) $receipt->receipt_number, $output);
        $this->assertStringContainsString('sealed_vat=19.000  ledger_vat=0.000', $output);
        $this->assertStringContainsString('wrong or partial VAT leg', $output);
    }

    public function test_a_zero_rated_receipt_is_not_flagged(): void
    {
        // No VAT was ever owed, so no VAT leg is missing. Flagging these would
        // bury the real hits under every exempt sale the shop ever made.
        $receipt = $this->receipt('100.000', '0.000', true);
        $this->bookEntry($receipt, withVatLeg: false);

        $this->assertSame(0, $this->runCensus()[0]);
    }

    public function test_a_receipt_that_never_reached_the_gl_is_reported_separately(): void
    {
        $this->receipt('119.000', '19.000', true);

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('never reached the GL', $output);
        $this->assertStringContainsString('pos_entries=0', $output);
    }

    public function test_the_tenant_filter_scopes_the_report(): void
    {
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($receipt, withVatLeg: false);

        $this->assertSame(0, $this->runCensus(['--tenant' => (string) Str::uuid()])[0]);
        $this->assertSame(1, $this->runCensus(['--tenant' => $this->tenantId])[0]);
    }

    public function test_a_multi_entry_receipt_reports_its_sealed_vat_once_not_once_per_entry(): void
    {
        // Gate r1, F-2. The POS books ONE ENTRY PER TENDER LEG. The first cut
        // joined the rate rows and the entries flat, so `SUM(vat_amount)` ran
        // over the product and a two-leg receipt reported 180.000 where the
        // sealed fact is 90.000. Detection was unaffected — the MONEY FIGURE an
        // operator reads off the deploy check was not.
        $receipt = $this->receipt('690.000', '90.000', true, [
            ['7.00', '100.000', '7.000'],
            ['13.00', '200.000', '26.000'],
            ['19.00', '300.000', '57.000'],
        ]);
        $this->bookEntry($receipt, withVatLeg: false, legTotal: '400.000', legVat: '0.000');
        $this->bookEntry($receipt, withVatLeg: false, legTotal: '290.000', legVat: '0.000');

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sealed_vat=90.000', $output);
        $this->assertStringNotContainsString('sealed_vat=180', $output);
        $this->assertStringContainsString('pos_entries=2', $output);
    }

    public function test_a_receipt_booked_with_a_vat_leg_on_only_some_legs_is_drift_not_clean(): void
    {
        // Gate r1, F-3. `COUNT(vat lines) = 0` calls this receipt CLEAN: leg 0
        // carries a 4457 line, so the count is non-zero. That state is exactly
        // what a mid-deploy cutover or a partially replayed multi-leg receipt
        // produces — the population this command exists to find. The census now
        // compares AMOUNTS, so 26.000 booked against 90.000 sealed is drift.
        $receipt = $this->receipt('690.000', '90.000', true, [
            ['7.00', '100.000', '7.000'],
            ['13.00', '200.000', '26.000'],
            ['19.00', '300.000', '57.000'],
        ]);
        $this->bookEntry($receipt, withVatLeg: true, legTotal: '400.000', legVat: '26.000');
        $this->bookEntry($receipt, withVatLeg: false, legTotal: '290.000', legVat: '0.000');

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sealed_vat=90.000  ledger_vat=26.000', $output);
        $this->assertStringContainsString('wrong or partial VAT leg', $output);
    }

    public function test_a_fully_booked_split_tender_receipt_is_clean(): void
    {
        // The control for the two above: both legs carry their share, the
        // magnitudes match, exit 0. Without this a census that flagged
        // everything would also pass F-2 and F-3.
        $receipt = $this->receipt('690.000', '90.000', true, [
            ['7.00', '100.000', '7.000'],
            ['13.00', '200.000', '26.000'],
            ['19.00', '300.000', '57.000'],
        ]);
        $this->bookEntry($receipt, withVatLeg: true, legTotal: '400.000', legVat: '52.175');
        $this->bookEntry($receipt, withVatLeg: true, legTotal: '290.000', legVat: '37.825');

        [$code, $output] = $this->runCensus();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('none', $output);
    }

    public function test_a_ledger_vat_leg_that_exceeds_the_sealed_figure_is_drift_too(): void
    {
        // R2-4 — the opposite drift direction: a double-booked or twice-replayed
        // receipt whose 4457 carries MORE than the sealed rows say. The amount
        // comparison catches it because magnitude inequality is symmetric, but
        // nothing pinned that until now, and a census that only ever looked for
        // "too little" would miss the replay defect entirely.
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($receipt, withVatLeg: true);
        $this->bookEntry($receipt, withVatLeg: true);

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sealed_vat=19.000  ledger_vat=38.000', $output);
        $this->assertStringContainsString('wrong or partial VAT leg', $output);
    }

    public function test_a_correctly_booked_refund_is_clean_even_though_it_debits_the_vat_account(): void
    {
        // A refund receipt DEBITS 4457. Comparing the raw signed sum against the
        // (always non-negative) sealed rows would flag every correct refund;
        // magnitudes are what reconcile.
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookRefundEntry($receipt);

        $this->assertSame(0, $this->runCensus()[0]);
    }

    public function test_a_training_receipt_is_not_drift(): void
    {
        // Treasury gate I-1. A training receipt is a rehearsal on a live
        // terminal: it seals its `pos_receipt_vat_details` rows and, BY DESIGN,
        // writes no journal entry at all (`TreasuryReceiptBridge` returns on
        // `trainingFlag`). The declaration excludes it
        // (`EloquentVatDataRepository`: `is_voided = false AND is_training =
        // false`), so a census that counts it reports drift that cannot be
        // fixed — exit 1 forever on any tenant that trains its cashiers during
        // onboarding, which tenant #1 does. An operator who learns to ignore
        // this gate will ignore the real drift with it.
        $this->receipt('119.000', '19.000', true, isTraining: true);

        [$code, $output] = $this->runCensus();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('none', $output);
    }

    public function test_a_voided_receipt_is_not_drift(): void
    {
        // Same predicate, other flag — mirrored on the declaration's own filter
        // rather than reasoned about separately, so the two cannot drift apart.
        $this->receipt('119.000', '19.000', true, isVoided: true);

        $this->assertSame(0, $this->runCensus()[0]);
    }

    public function test_a_training_receipt_does_not_mask_a_real_drift_next_to_it(): void
    {
        // The control that stops the I-1 fix from becoming a blanket suppressor.
        $this->receipt('119.000', '19.000', true, isTraining: true);
        $real = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($real, withVatLeg: false);

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString((string) $real->receipt_number, $output);
        $this->assertSame(
            1,
            substr_count($output, 'sealed_vat='),
            'exactly one receipt is drift; the training one must not appear: '.$output,
        );
    }

    public function test_an_unprovisioned_chart_exits_with_its_own_code_and_names_every_missing_purpose(): void
    {
        // Treasury gate I-2 + M-3. Since W4-9 a POS tender leg resolves up to
        // three purposes, so the deploy gate has to check BOTH hard ones — a
        // gate that names only `vat_collected` sends the operator back for a
        // second round trip when `sales_discount` is also absent. And
        // "unprovisioned" needs an exit code distinct from "drift found": they
        // have different remedies and a deploy script branches on the number.
        $this->receipt('119.000', '19.000', true);

        DB::table('accounts')
            ->whereIn('system_purpose', [
                SystemAccountPurpose::VatCollected->value,
                SystemAccountPurpose::SalesDiscount->value,
            ])
            ->update(['system_purpose' => null]);

        [$code, $output] = $this->runCensus();

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('`vat_collected`', $output);
        $this->assertStringContainsString('`sales_discount`', $output);
    }

    public function test_a_chart_missing_only_the_sales_discount_purpose_is_still_reported(): void
    {
        $this->receipt('119.000', '19.000', true);

        DB::table('accounts')
            ->where('system_purpose', SystemAccountPurpose::SalesDiscount->value)
            ->update(['system_purpose' => null]);

        [$code, $output] = $this->runCensus();

        $this->assertSame(2, $code, $output);
        $this->assertStringContainsString('`sales_discount`', $output);
        $this->assertStringNotContainsString('`vat_collected`', $output);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Run the census and hand back BOTH halves of its contract.
     *
     * `Artisan::call()` rather than `$this->artisan()`: the exit code and the
     * rendered text are asserted together, and a listing line is checked as one
     * contiguous string (`sealed_vat=X  ledger_vat=Y`) so a test cannot pass on
     * two numbers that happen to appear on different receipts' lines.
     *
     * @param  array<string, string>  $options
     * @return array{0: int, 1: string}
     */
    private function runCensus(array $options = []): array
    {
        $code = Artisan::call('pos:census-vat-legs', $options);

        return [$code, Artisan::output()];
    }

    /**
     * @param  numeric-string  $total
     * @param  numeric-string  $taxAmount
     * @param  list<array{0: string, 1: string, 2: string}>|null  $sealedRates  [rate, net, vat]; null = one row
     *                                                                          derived from $taxAmount
     */
    // =====================================================================
    // D-1 gate r1 (treasury) F-1 — the BASE arm
    // =====================================================================

    /**
     * D-1's own defect class is a WRONG REVENUE BASE with a CORRECT VAT. The
     * P0 this lane closed booked 574.547 against a sealed base of 524.547 while
     * the VAT matched to the millime — and before the base arm this census
     * returned exit 0 on exactly that. The deploy gate the fleet is told to
     * trust could not see the failure the lane exists to prevent.
     *
     * Era-agnostic by construction: the identity is
     * `Σ sealed net_amount == Σ (credit − debit) on ProductRevenue`, which the
     * derivation reproduces in BOTH eras.
     */
    public function test_a_receipt_whose_revenue_base_drifts_from_the_sealed_base_is_flagged(): void
    {
        // Sealed: base 100.000 + VAT 19.000 = 119.000. The VAT leg is exactly
        // right; only the revenue credit is wrong.
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->writeEntry($receipt, 'pos_receipt', [
            [SystemAccountPurpose::Cash, '119.000', '0'],
            // 110.000, not the sealed 100.000 — D-1's defect class exactly.
            [SystemAccountPurpose::ProductRevenue, '0', '110.000'],
            [SystemAccountPurpose::VatCollected, '0', '19.000'],
        ]);

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sealed_base=100.000  ledger_base=110.000', $output);
        // The VAT arm alone would have said "clean".
        $this->assertStringContainsString('sealed_vat=19.000  ledger_vat=19.000', $output);
    }

    /** A post-remise (v5) receipt booked at its sealed base is clean. */
    public function test_a_post_remise_receipt_booked_at_its_sealed_base_is_clean(): void
    {
        $receipt = $this->receipt('119.000', '19.000', true);
        DB::table('pos_receipt_vat_details')
            ->where('receipt_id', $receipt->id)
            ->update(['discount_allocated' => '10.000']);
        $this->writeEntry($receipt, 'pos_receipt', [
            [SystemAccountPurpose::Cash, '119.000', '0'],
            [SystemAccountPurpose::ProductRevenue, '0', '100.000'],
            [SystemAccountPurpose::VatCollected, '0', '19.000'],
        ]);

        $this->assertSame(0, $this->runCensus()[0]);
    }

    /**
     * A FULLY EXEMPT sale has a real taxable base and zero VAT. The base arm
     * must still check it — the VAT-only skip used to wave the whole receipt
     * through.
     */
    public function test_a_fully_exempt_receipt_with_a_wrong_revenue_base_is_flagged(): void
    {
        $receipt = $this->receipt('100.000', '0.000', true);
        $this->writeEntry($receipt, 'pos_receipt', [
            [SystemAccountPurpose::Cash, '100.000', '0'],
            [SystemAccountPurpose::ProductRevenue, '0', '90.000'],
        ]);

        [$code, $output] = $this->runCensus();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sealed_base=100.000  ledger_base=90.000', $output);
    }

    /** A correctly booked exempt sale stays clean — no new false positives. */
    public function test_a_fully_exempt_receipt_booked_at_its_sealed_base_is_clean(): void
    {
        $receipt = $this->receipt('100.000', '0.000', true);
        $this->writeEntry($receipt, 'pos_receipt', [
            [SystemAccountPurpose::Cash, '100.000', '0'],
            [SystemAccountPurpose::ProductRevenue, '0', '100.000'],
        ]);

        $this->assertSame(0, $this->runCensus()[0]);
    }

    private function receipt(
        string $total,
        string $taxAmount,
        bool $withSealedRows,
        ?array $sealedRates = null,
        bool $isTraining = false,
        bool $isVoided = false,
    ): Receipt {
        $receipt = Receipt::factory()
            ->withTotal($total, $taxAmount)
            ->create([
                'tenant_id' => $this->tenantId,
                'company_id' => $this->companyId,
                'location_id' => $this->locationId,
                'terminal_id' => $this->terminalId,
                'cashier_id' => $this->cashierId,
                'currency' => 'TND',
                'is_training' => $isTraining,
                'is_voided' => $isVoided,
                // `pos_receipts_void_logic` (PG) requires the audit columns
                // whenever `is_voided` is true — a fixture that sets the flag
                // alone describes a receipt the schema forbids.
                'voided_at' => $isVoided ? now() : null,
                'voided_by' => $isVoided ? $this->cashierId : null,
            ]);

        if ($withSealedRows) {
            $rows = $sealedRates ?? [[
                bccomp($taxAmount, '0', 3) > 0 ? '19.00' : '0.00',
                bcsub($total, $taxAmount, 3),
                $taxAmount,
            ]];
            foreach ($rows as [$rate, $net, $vat]) {
                ReceiptVatDetail::create([
                    'id' => Str::uuid()->toString(),
                    'receipt_id' => $receipt->id,
                    'tax_category' => 'S',
                    'tax_rate' => $rate,
                    'net_amount' => $net,
                    'vat_amount' => $vat,
                    'gross_amount' => bcadd($net, $vat, 3),
                ]);
            }
        }

        return $receipt;
    }

    /**
     * Book the mirror of a correct sale entry against `pos_receipt_refund`:
     * cash out, revenue and 4457 both DEBITED.
     */
    private function bookRefundEntry(Receipt $receipt): void
    {
        $this->writeEntry(
            $receipt,
            'pos_receipt_refund',
            [
                [SystemAccountPurpose::Cash, '0', (string) $receipt->total],
                [SystemAccountPurpose::ProductRevenue, bcsub((string) $receipt->total, (string) $receipt->tax_amount, 3), '0'],
                [SystemAccountPurpose::VatCollected, (string) $receipt->tax_amount, '0'],
            ],
        );
    }

    /**
     * Book ONE journal entry against the receipt, the way the pre-W4-9 writer did
     * (Dr cash / Cr revenue for the gross) or the way it does now (net + VAT).
     *
     * `$legTotal`/`$legVat` let a test express ONE LEG of a split-tender receipt,
     * which is how the partially-fixed state (F-3) and the multi-entry double
     * count (F-2) become reproducible at all.
     *
     * @param  ?numeric-string  $legTotal
     * @param  ?numeric-string  $legVat
     */
    private function bookEntry(
        Receipt $receipt,
        bool $withVatLeg,
        ?string $legTotal = null,
        ?string $legVat = null,
    ): void {
        $tax = $legVat ?? (string) $receipt->tax_amount;
        $total = $legTotal ?? (string) $receipt->total;

        $lines = [[SystemAccountPurpose::Cash, $total, '0']];
        if ($withVatLeg && bccomp($tax, '0', 3) > 0) {
            $lines[] = [SystemAccountPurpose::ProductRevenue, '0', bcsub($total, $tax, 3)];
            $lines[] = [SystemAccountPurpose::VatCollected, '0', $tax];
        } else {
            $lines[] = [SystemAccountPurpose::ProductRevenue, '0', $total];
        }

        $this->writeEntry($receipt, 'pos_receipt', $lines);
    }

    /**
     * @param  list<array{0: SystemAccountPurpose, 1: string, 2: string}>  $lines  [purpose, debit, credit]
     */
    private function writeEntry(Receipt $receipt, string $sourceType, array $lines): void
    {
        $entryId = Str::uuid()->toString();
        DB::table('journal_entries')->insert([
            'id' => $entryId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'entry_number' => 'JE-CENSUS-'.substr($entryId, 0, 12),
            'entry_date' => $receipt->posted_at,
            'description' => 'census fixture',
            'status' => JournalEntryStatus::Posted->value,
            'source_type' => $sourceType,
            'source_id' => $receipt->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = 0;
        foreach ($lines as [$purpose, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => Str::uuid()->toString(),
                'journal_entry_id' => $entryId,
                'account_id' => Account::findByPurposeOrFail($this->companyId, $purpose)->id,
                'partner_id' => null,
                'debit' => $debit,
                'credit' => $credit,
                'description' => 'census fixture',
                'line_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
