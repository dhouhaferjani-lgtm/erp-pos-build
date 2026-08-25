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

    public function test_a_correctly_booked_refund_is_clean_even_though_it_debits_the_vat_account(): void
    {
        // A refund receipt DEBITS 4457. Comparing the raw signed sum against the
        // (always non-negative) sealed rows would flag every correct refund;
        // magnitudes are what reconcile.
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookRefundEntry($receipt);

        $this->assertSame(0, $this->runCensus()[0]);
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
    private function receipt(
        string $total,
        string $taxAmount,
        bool $withSealedRows,
        ?array $sealedRates = null,
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
