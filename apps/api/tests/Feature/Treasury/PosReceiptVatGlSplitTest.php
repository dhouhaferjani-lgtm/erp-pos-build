<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\PosVatRefusalReason;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\PosVatProjectionRefusedException;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * W4-9 — the POS receipt GL projection must split the tender into net revenue
 * and output VAT per SEALED rate.
 *
 * Campaign evidence (`docs/handoff/PLAYWRIGHT-first-tenant-campaign-wave4-critical-path-2026-08-24.md`
 * §W4-9): `createPOSPaymentEntry()` wrote exactly two lines — Dr repository GL
 * account, Cr ProductRevenue — both for the GROSS tender. `4457 TVA collectée`
 * had no journal line for any POS receipt on the campaign tenant, while
 * `pos_receipt_vat_details` and the DGI declaration both carried the correct
 * three-rate split. Revenue was overstated by exactly the VAT, VAT payable was
 * unrecorded, and the trial balance still closed.
 *
 * Every assertion below reads the SEALED `pos_receipt_vat_details` rows and
 * compares the ledger to them. Nothing here recomputes VAT from a rate — a test
 * that did would agree with a buggy implementation that made the same mistake.
 *
 * Scale is TND 3 throughout (the first tenant's currency), because a scale-2
 * fixture cannot exercise the truncation residual that the split-tender
 * apportionment has to absorb.
 */
final class PosReceiptVatGlSplitTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $repositoryId;

    private string $cashAccountId;

    private string $revenueAccountId;

    private string $vatAccountId;

    private string $discountAccountId;

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

        app(CompanyContext::class)->setCompanyId($this->companyId);

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Test Cashier']);
        $this->operatorId = $user->id;

        foreach ([['CASH', 'Cash'], ['CARD', 'Card']] as [$code, $name]) {
            PaymentMethod::factory()->create([
                'tenant_id' => $this->tenantId,
                'company_id' => $this->companyId,
                'code' => $code,
                'name' => $name,
            ]);
        }

        $this->app->make(ChartOfAccountsService::class)->seedForCompany(
            Company::query()->findOrFail($this->companyId),
        );

        $cashAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Cash);
        $this->cashAccountId = $cashAccount->id;
        $this->revenueAccountId = Account::findByPurposeOrFail(
            $this->companyId,
            SystemAccountPurpose::ProductRevenue,
        )->id;
        $this->vatAccountId = Account::findByPurposeOrFail(
            $this->companyId,
            SystemAccountPurpose::VatCollected,
        )->id;
        $this->discountAccountId = Account::findByPurposeOrFail(
            $this->companyId,
            SystemAccountPurpose::SalesDiscount,
        )->id;

        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->cashAccountId,
            'currency' => 'TND',
        ]);
        $this->repositoryId = $repository->id;
    }

    // =================================================================
    // The defect itself
    // =================================================================

    public function test_sale_credits_net_revenue_and_one_output_vat_line_per_sealed_rate(): void
    {
        // Worked example at TND scale 3, three rates:
        //   7 %: net 100.000  VAT  7.000   gross 107.000
        //  13 %: net 200.000  VAT 26.000   gross 226.000
        //  19 %: net 300.000  VAT 57.000   gross 357.000
        //  ---------------------------------------------
        //        subtotal 600.000  VAT 90.000  total 690.000
        //
        // Expected entry:
        //   Dr  53  Caisse            690.000
        //     Cr  70x ProductRevenue  600.000
        //     Cr  4457 @  7.00 %        7.000
        //     Cr  4457 @ 13.00 %       26.000
        //     Cr  4457 @ 19.00 %       57.000
        $event = $this->projectedThreeRateSale();

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $lines = $this->posEntryLines($receipt->id, 'pos_receipt');

        $this->assertSame('690.000', $this->sumDebits($lines, $this->cashAccountId));
        $this->assertSame('600.000', $this->sumCredits($lines, $this->revenueAccountId));

        $vatLines = array_values(array_filter(
            $lines,
            fn (object $line): bool => (string) $line->account_id === $this->vatAccountId,
        ));
        $this->assertCount(3, $vatLines, 'one output-VAT line per sealed rate');

        // The ledger must equal the SEALED rows rate for rate, not "some 90".
        $this->assertSame($this->sealedVatByRate($receipt->id), $this->ledgerVatByRate($lines));
    }

    public function test_ledger_vat_total_equals_the_sealed_receipt_vat_total(): void
    {
        $event = $this->projectedThreeRateSale();
        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $sealedTotal = (string) DB::table('pos_receipt_vat_details')
            ->where('receipt_id', $receipt->id)
            ->sum('vat_amount');

        $lines = $this->posEntryLines($receipt->id, 'pos_receipt');

        $this->assertSame(
            bcadd($sealedTotal, '0', 3),
            $this->sumCredits($lines, $this->vatAccountId),
        );
        // …and revenue is the tender MINUS that VAT, never the gross tender.
        $this->assertSame(
            bcsub('690.000', bcadd($sealedTotal, '0', 3), 3),
            $this->sumCredits($lines, $this->revenueAccountId),
        );
    }

    // =================================================================
    // Split tender — the apportionment must be exact
    // =================================================================

    public function test_split_tender_apportions_the_sealed_vat_across_legs_without_losing_a_millime(): void
    {
        // 690.000 = 400.000 cash + 290.000 card. Neither leg's share of any
        // rate divides evenly at scale 3, so this is the case that proves the
        // residual is absorbed rather than dropped.
        $event = $this->projectedThreeRateSale([
            ['amount' => '400.000', 'method_code' => 'CASH'],
            ['amount' => '290.000', 'method_code' => 'CARD'],
        ]);

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();

        $entries = DB::table('journal_entries')
            ->where('source_type', 'pos_receipt')
            ->where('source_id', $receipt->id)
            ->pluck('id');
        $this->assertCount(2, $entries, 'one journal entry per tender leg');

        $lines = $this->posEntryLines($receipt->id, 'pos_receipt');

        // Across BOTH entries the sealed totals are reproduced exactly.
        $this->assertSame('690.000', $this->sumDebits($lines, $this->cashAccountId));
        $this->assertSame('600.000', $this->sumCredits($lines, $this->revenueAccountId));
        $this->assertSame($this->sealedVatByRate($receipt->id), $this->ledgerVatByRate($lines));

        // And every individual entry balances on its own.
        foreach ($entries as $entryId) {
            $entryLines = array_values(array_filter(
                $lines,
                static fn (object $line): bool => (string) $line->journal_entry_id === (string) $entryId,
            ));
            $this->assertSame(
                $this->sumColumn($entryLines, 'debit'),
                $this->sumColumn($entryLines, 'credit'),
                'each per-leg POS entry must balance',
            );
        }
    }

    // =================================================================
    // Refund mirrors the sale
    // =================================================================

    public function test_refund_mirrors_the_split_debiting_revenue_net_and_vat_per_rate(): void
    {
        $sale = $this->projectedThreeRateSale();
        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($sale);

        $refund = $this->projectedThreeRateSale(
            null,
            invoiceTypeCode: 'REFUND',
            originalEventId: $sale->id,
            sequenceNumber: 2,
        );
        $this->app->make(TreasuryReceiptBridge::class)->apply($refund);

        $refundReceipt = Receipt::query()->where('fiscal_event_id', $refund->id)->firstOrFail();
        $lines = $this->posEntryLines($refundReceipt->id, 'pos_receipt_refund');

        // Money OUT of the drawer, revenue and VAT both reversed — not the
        // gross tender against revenue alone.
        $this->assertSame('690.000', $this->sumCredits($lines, $this->cashAccountId));
        $this->assertSame('600.000', $this->sumDebits($lines, $this->revenueAccountId));
        $this->assertSame($this->sealedVatByRate($refundReceipt->id), $this->ledgerVatByRate($lines, 'debit'));

        // Sale + refund nets every POS account back to zero.
        $all = array_merge(
            $this->posEntryLines(
                Receipt::query()->where('fiscal_event_id', $sale->id)->firstOrFail()->id,
                'pos_receipt',
            ),
            $lines,
        );
        foreach ([$this->cashAccountId, $this->revenueAccountId, $this->vatAccountId] as $accountId) {
            $this->assertSame(
                $this->sumDebits($all, $accountId),
                $this->sumCredits($all, $accountId),
                'a refunded sale must leave account '.$accountId.' flat',
            );
        }
    }

    // =================================================================
    // Rounding leg unchanged
    // =================================================================

    public function test_cash_rounding_entry_is_untouched_and_vat_is_not_rounded_with_the_till(): void
    {
        // v3 receipt: sale value 690.000, rounded UP by 0.005 to 690.005.
        // `payload.total` is the ROUNDED amount collected, so the tender leg
        // carries 690.005 while the sealed VAT stays on the SALE value.
        //   Dr 53 690.005 / Cr 70x 600.005 / Cr 4457 90.000  (tender entry)
        //   Dr 70x 0.005  / Cr 7580 0.005                    (§4.6 entry 1)
        // Revenue therefore lands on exactly 600.000 and 4457 on exactly
        // 90.000 — the State is owed VAT on the sale, not on the till.
        $event = $this->projectedThreeRateSale(
            [['amount' => '690.005', 'method_code' => 'CASH']],
            total: '690.005',
            eventVersion: 3,
            cashRoundingAdjustment: '0.005',
            cashRoundingDenomination: '0.010',
        );

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();

        $tenderLines = $this->posEntryLines($receipt->id, 'pos_receipt');
        $this->assertSame('690.005', $this->sumDebits($tenderLines, $this->cashAccountId));
        $this->assertSame('90.000', $this->sumCredits($tenderLines, $this->vatAccountId));
        $this->assertSame($this->sealedVatByRate($receipt->id), $this->ledgerVatByRate($tenderLines));

        $roundingLines = $this->posEntryLines($receipt->id, 'pos_cash_rounding');
        $this->assertNotSame([], $roundingLines, 'the §4.6 rounding entry must still post');
        $this->assertSame('0.005', $this->sumDebits($roundingLines, $this->revenueAccountId));
        // The rounding entry never touches VAT.
        $this->assertSame('0.000', $this->sumCredits($roundingLines, $this->vatAccountId));
        $this->assertSame('0.000', $this->sumDebits($roundingLines, $this->vatAccountId));

        $all = array_merge($tenderLines, $roundingLines);
        $this->assertSame('600.000', bcsub(
            $this->sumCredits($all, $this->revenueAccountId),
            $this->sumDebits($all, $this->revenueAccountId),
            3,
        ));
    }

    // =================================================================
    // Transaction discount
    // =================================================================

    public function test_transaction_discount_is_a_contra_revenue_line_so_the_ledger_base_matches_the_declaration(): void
    {
        // W4-9 gate r1, F-4. The device seals the VAT on the PRE-discount base:
        // `subtotal + vat_total == total + transaction_discount_amount`. Here
        // subtotal 600.000, VAT 90.000, discount 50.000 → the customer tenders
        // 640.000.
        //
        // Letting the revenue credit absorb the discount (640.000 − 90.000 =
        // 550.000) balances arithmetically but puts the ledger's implied taxable
        // base at 550.000 while the DGI declaration reports 600.000 for the same
        // receipt — books and filing agreeing about the VAT and disagreeing
        // about what it was charged on. Expected instead:
        //
        //   Dr  53   Caisse              640.000
        //   Dr  709  Sales discount       50.000
        //     Cr  70x ProductRevenue     600.000   ← the declaration's base_amount
        //     Cr  4457 per sealed rate    90.000
        //
        // This is how the POS's own ACCOUNT_CHARGE arm already books a
        // discounted sale (`createPOSChargeEntry`), so the two POS arms agree.
        $event = $this->projectedThreeRateSale(
            [['amount' => '640.000', 'method_code' => 'CASH']],
            total: '640.000',
            discountTotal: '50.000',
        );

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $lines = $this->posEntryLines($receipt->id, 'pos_receipt');

        $this->assertSame('640.000', $this->sumDebits($lines, $this->cashAccountId));
        $this->assertSame('50.000', $this->sumDebits($lines, $this->discountAccountId));
        $this->assertSame('600.000', $this->sumCredits($lines, $this->revenueAccountId));
        $this->assertSame($this->sealedVatByRate($receipt->id), $this->ledgerVatByRate($lines));

        // The revenue credit IS the sum of the sealed net bases — the same
        // number the declaration reports.
        $sealedNet = (string) DB::table('pos_receipt_vat_details')
            ->where('receipt_id', $receipt->id)
            ->sum('net_amount');
        $this->assertSame(
            bcadd($sealedNet, '0', 3),
            $this->sumCredits($lines, $this->revenueAccountId),
            'the ledger revenue base must equal the sealed (declared) taxable base',
        );

        $this->assertSame($this->sumColumn($lines, 'debit'), $this->sumColumn($lines, 'credit'));
    }

    public function test_a_discounted_refund_credits_the_discount_account_back(): void
    {
        $sale = $this->projectedThreeRateSale(
            [['amount' => '640.000', 'method_code' => 'CASH']],
            total: '640.000',
            discountTotal: '50.000',
        );
        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($sale);

        $refund = $this->projectedThreeRateSale(
            [['amount' => '640.000', 'method_code' => 'CASH']],
            total: '640.000',
            discountTotal: '50.000',
            invoiceTypeCode: 'REFUND',
            originalEventId: $sale->id,
            sequenceNumber: 2,
        );
        $this->app->make(TreasuryReceiptBridge::class)->apply($refund);

        $refundReceipt = Receipt::query()->where('fiscal_event_id', $refund->id)->firstOrFail();
        $refundLines = $this->posEntryLines($refundReceipt->id, 'pos_receipt_refund');

        // The contra line mirrors: a debit on the sale, a credit on its reversal.
        $this->assertSame('50.000', $this->sumCredits($refundLines, $this->discountAccountId));
        $this->assertSame('600.000', $this->sumDebits($refundLines, $this->revenueAccountId));

        $all = array_merge(
            $this->posEntryLines(
                Receipt::query()->where('fiscal_event_id', $sale->id)->firstOrFail()->id,
                'pos_receipt',
            ),
            $refundLines,
        );
        foreach ([$this->cashAccountId, $this->revenueAccountId, $this->vatAccountId, $this->discountAccountId] as $accountId) {
            $this->assertSame(
                $this->sumDebits($all, $accountId),
                $this->sumCredits($all, $accountId),
                'a refunded discounted sale must leave account '.$accountId.' flat',
            );
        }
    }

    public function test_a_discount_larger_than_the_net_subtotal_still_books(): void
    {
        // W4-9 gate r2, R2-1. `cartTotals.ts` clamps the transaction discount to
        // the GROSS subtotal, so a 620.000 discount on a 690.000 gross ticket is
        // device-authorable and device-SIGNED (`600 + 90 == 70 + 620` ✓). F-4
        // taught the per-leg net guard about the discount but left the
        // receipt-level one comparing the sealed VAT against the bare TENDER, so
        // any receipt whose discount exceeds its net subtotal was refused —
        // and nothing catches the refusal, so the projection job failed forever:
        // no payment, no movement, no GL entry, while the declaration still
        // reported the sealed VAT. The guard now compares against the gross base
        // the device actually sealed the VAT on, `tender + discount`.
        $event = $this->projectedThreeRateSale(
            [['amount' => '70.000', 'method_code' => 'CASH']],
            total: '70.000',
            discountTotal: '620.000',
        );

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $lines = $this->posEntryLines($receipt->id, 'pos_receipt');

        $this->assertSame('70.000', $this->sumDebits($lines, $this->cashAccountId));
        $this->assertSame('620.000', $this->sumDebits($lines, $this->discountAccountId));
        $this->assertSame('600.000', $this->sumCredits($lines, $this->revenueAccountId));
        $this->assertSame($this->sealedVatByRate($receipt->id), $this->ledgerVatByRate($lines));
        $this->assertSame($this->sumColumn($lines, 'debit'), $this->sumColumn($lines, 'credit'));
    }

    public function test_a_hundred_percent_comp_books_its_revenue_vat_and_discount_with_no_cash(): void
    {
        // The limiting case of R2-1: the whole ticket is comped, so the tender is
        // 0.000 and the discount is the entire gross. The sale still HAPPENED —
        // the goods left, the device sealed 90.000 of VAT on a 600.000 base, and
        // the declaration will report both — so the ledger has to recognise the
        // revenue and the VAT it owes, and carry the give-away on 709. What it
        // must NOT do is refuse the receipt outright.
        $event = $this->projectedThreeRateSale(
            [['amount' => '0.000', 'method_code' => 'CASH']],
            total: '0.000',
            discountTotal: '690.000',
        );

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $lines = $this->posEntryLines($receipt->id, 'pos_receipt');

        $this->assertSame('0.000', $this->sumDebits($lines, $this->cashAccountId));
        $this->assertSame('690.000', $this->sumDebits($lines, $this->discountAccountId));
        $this->assertSame('600.000', $this->sumCredits($lines, $this->revenueAccountId));
        $this->assertSame($this->sealedVatByRate($receipt->id), $this->ledgerVatByRate($lines));
        $this->assertSame($this->sumColumn($lines, 'debit'), $this->sumColumn($lines, 'credit'));
    }

    // =================================================================
    // Zero-rated + refusal
    // =================================================================

    public function test_fully_exempt_sale_posts_no_vat_line_at_all(): void
    {
        $event = $this->projectedThreeRateSale(
            [['amount' => '100.000', 'method_code' => 'CASH']],
            vatBreakdown: [['rate' => '0.00', 'net' => '100.000', 'vat' => '0.000']],
            subtotal: '100.000',
            taxTotal: '0.000',
            total: '100.000',
        );

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $lines = $this->posEntryLines($receipt->id, 'pos_receipt');

        $this->assertSame('100.000', $this->sumCredits($lines, $this->revenueAccountId));
        $this->assertSame(
            [],
            array_values(array_filter(
                $lines,
                fn (object $line): bool => (string) $line->account_id === $this->vatAccountId,
            )),
            'a 0 % sale must not post a 0.000 VAT line',
        );
    }

    public function test_receipt_without_sealed_vat_details_is_refused_and_nothing_is_written(): void
    {
        $event = $this->projectedThreeRateSale();
        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();

        // Simulate the only state in which the ledger cannot know the sale's
        // VAT: the sealed breakdown is absent. Recomputing it from the lines is
        // exactly what must NOT happen — the projection has to refuse.
        DB::table('pos_receipt_vat_details')->where('receipt_id', $receipt->id)->delete();

        $this->app->make(CompanyContext::class)->clear();

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('the bridge must refuse a receipt with no sealed VAT details');
        } catch (PosVatProjectionRefusedException $e) {
            $this->assertSame(PosVatRefusalReason::MissingSealedVatDetails, $e->reason);
            $this->assertSame($receipt->id, $e->receiptId);
        }

        // Fail-CLOSED: the refusal rolls the whole projection back, so no
        // half-booked receipt survives for someone to reconcile later.
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(
            0,
            DB::table('journal_entries')->where('source_type', 'pos_receipt')->count(),
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @return list<object>
     */
    private function posEntryLines(string $receiptId, string $sourceType): array
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source_type', $sourceType)
            ->where('journal_entries.source_id', $receiptId)
            ->orderBy('journal_entries.entry_number')
            ->orderBy('journal_lines.line_order')
            ->select([
                'journal_lines.journal_entry_id',
                'journal_lines.account_id',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description',
                'journal_lines.line_order',
            ])
            ->get()
            ->all();
    }

    /**
     * @param  list<object>  $lines
     * @return numeric-string
     */
    private function sumDebits(array $lines, string $accountId): string
    {
        return $this->sumColumn(
            array_values(array_filter($lines, static fn (object $l): bool => (string) $l->account_id === $accountId)),
            'debit',
        );
    }

    /**
     * @param  list<object>  $lines
     * @return numeric-string
     */
    private function sumCredits(array $lines, string $accountId): string
    {
        return $this->sumColumn(
            array_values(array_filter($lines, static fn (object $l): bool => (string) $l->account_id === $accountId)),
            'credit',
        );
    }

    /**
     * @param  list<object>  $lines
     * @return numeric-string
     */
    private function sumColumn(array $lines, string $column): string
    {
        $total = bcadd('0', '0', 3);
        foreach ($lines as $line) {
            $value = (string) $line->{$column};
            $total = bcadd($total, is_numeric($value) ? $value : '0', 3);
        }

        return $total;
    }

    /**
     * The SEALED breakdown, keyed by rate — the fiscal fact the ledger must
     * reproduce.
     *
     * @return array<string, string>
     */
    private function sealedVatByRate(string $receiptId): array
    {
        $out = [];
        foreach (
            DB::table('pos_receipt_vat_details')
                ->where('receipt_id', $receiptId)
                ->orderBy('tax_rate')
                ->get(['tax_rate', 'vat_amount']) as $row
        ) {
            $rate = bcadd((string) $row->tax_rate, '0', 2);
            $out[$rate] = bcadd((string) $row->vat_amount, '0', 3);
        }
        ksort($out);

        return $out;
    }

    /**
     * The same shape read back OUT of the ledger, by parsing the rate off each
     * VAT line's description. Reading the rate from the line (rather than
     * trusting order) is what makes a mislabelled line fail.
     *
     * @param  list<object>  $lines
     * @return array<string, string>
     */
    private function ledgerVatByRate(array $lines, string $column = 'credit'): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ((string) $line->account_id !== $this->vatAccountId) {
                continue;
            }
            if (preg_match('/([0-9]+\.[0-9]{2})%$/', (string) $line->description, $m) !== 1) {
                throw new RuntimeException('VAT line description must name its rate: '.(string) $line->description);
            }
            $rate = $m[1];
            $out[$rate] = bcadd($out[$rate] ?? '0.000', (string) $line->{$column}, 3);
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  list<array{amount: string, method_code: string}>|null  $paymentLines
     * @param  list<array{rate: string, net: string, vat: string}>|null  $vatBreakdown
     */
    private function projectedThreeRateSale(
        ?array $paymentLines = null,
        ?array $vatBreakdown = null,
        string $subtotal = '600.000',
        string $taxTotal = '90.000',
        string $total = '690.000',
        string $discountTotal = '0.000',
        string $invoiceTypeCode = 'SALE',
        ?string $originalEventId = null,
        ?string $originalReceiptUuid = null,
        int $sequenceNumber = 1,
        int $eventVersion = 1,
        ?string $cashRoundingAdjustment = null,
        ?string $cashRoundingDenomination = null,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $payLines = $paymentLines ?? [['amount' => $total, 'method_code' => 'CASH']];
        $payments = [];
        foreach ($payLines as $pl) {
            $payments[] = [
                'amount' => $pl['amount'],
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => $pl['method_code'],
            ];
        }

        $rates = $vatBreakdown ?? [
            ['rate' => '7.00', 'net' => '100.000', 'vat' => '7.000'],
            ['rate' => '13.00', 'net' => '200.000', 'vat' => '26.000'],
            ['rate' => '19.00', 'net' => '300.000', 'vat' => '57.000'],
        ];

        $vatRows = [];
        $lineItems = [];
        foreach ($rates as $i => $r) {
            $vatRows[] = [
                'gross_amount' => bcadd($r['net'], $r['vat'], 3),
                'net_amount' => $r['net'],
                'rate' => $r['rate'],
                'tax_category_code' => 'S',
                'vat_amount' => $r['vat'],
            ];
            $lineItems[] = [
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => $r['net'],
                'line_vat' => $r['vat'],
                'name' => 'Item at '.$r['rate'].'%',
                'non_collected_subtype' => null,
                'product_id' => 'prod-'.$i,
                'quantity' => '1.0000',
                'sku' => 'SKU-'.$i,
                'tax_category_code' => 'S',
                'unit_price' => bcadd($r['net'], $r['vat'], 3),
                'vat_rate' => $r['rate'],
            ];
        }

        $payload = [
            'approval_references' => [],
            'business_date' => $businessDate->toDateString(),
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => '2026-08-24T10:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => $lineItems,
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalEventId === null ? null : [
                'fiscal_event_id' => $originalEventId,
                'original_business_date' => $businessDate->toDateString(),
                'original_receipt_uuid' => $originalReceiptUuid ?? '',
                'refund_reason' => 'customer_return',
            ],
            'payments' => $payments,
            'receipt_uuid' => (string) Str::uuid(),
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue de Rome'],
                'name' => 'Default Seller SARL',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AAM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $subtotal,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => $discountTotal,
            'transaction_discount_reason' => bccomp($discountTotal, '0', 3) > 0 ? 'loyalty' : null,
            'vat_breakdown' => $vatRows,
            'vat_total' => $taxTotal,
            'vouchers_redeemed' => [],
        ];

        if ($cashRoundingAdjustment !== null) {
            $payload['cash_rounding_adjustment'] = $cashRoundingAdjustment;
            $payload['cash_rounding_denomination'] = $cashRoundingDenomination ?? '0.010';
        }

        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => $eventVersion,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => $previousHash,
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => $sequenceNumber,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);

        $event = FiscalEvent::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => $eventVersion,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'server_received_at' => $eventTime,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => null,
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => $previousHash,
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        $event = $event->refresh();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn (mixed $v): mixed => $this->sortRecursive($v), $value);
    }
}
