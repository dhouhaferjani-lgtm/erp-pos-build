<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
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
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 17 — maturity refunds cancel exactly one still-Received original
 * instrument without cash; active/missing/ambiguous paper takes the standard
 * refund path plus one durable alert. CompanyContext stays cleared throughout.
 */
final class PosBridgeInstrumentRefundTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $repositoryId;

    private string $bankRepositoryId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId, 'country_code' => 'FR', 'currency' => 'EUR']);
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
        $this->grantMembership($user->id, $this->companyId);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CHECK',
            'name' => 'Check',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);

        $cashAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Cash);
        // Seed a positive opening balance so a refund (cash OUT) leaves the
        // drawer with a realistic non-negative balance.
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'EUR',
            'balance' => '100.000',
        ]);
        $this->repositoryId = $repository->id;
        $bankAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Bank);
        $bankRepository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::BankAccount,
            'gl_account_id' => $bankAccount->id,
            'currency' => 'EUR',
            'balance' => '100.000',
        ]);
        $this->bankRepositoryId = $bankRepository->id;

        // Rule 20 — projections run on a Horizon worker with NO CompanyContext.
        app(CompanyContext::class)->clear();
    }

    public function test_same_day_check_refund_cancels_the_original_instrument_without_cash(): void
    {
        [$refundEvent, $saleEvent] = $this->projectedRefundReceipt('10.00');
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        $bridge->apply($saleEvent);
        $originalInstrument = PaymentInstrument::query()
            ->where('idempotency_key', "fiscal_event:{$saleEvent->id}:instrument:0")
            ->sole();
        $this->assertSame(InstrumentStatus::Received, $originalInstrument->status);
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $saleEvent->id)->count());

        $refundReceipt = Receipt::query()->where('fiscal_event_id', $refundEvent->id)->sole();
        $projectionBefore = DB::table('pos_receipt_payments')
            ->where('receipt_id', $refundReceipt->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $bridge->apply($refundEvent);

        $originalInstrument->refresh();
        $this->assertSame(InstrumentStatus::Cancelled, $originalInstrument->status);
        $this->assertSame(PaymentStatus::Reversed, $originalInstrument->payment()->sole()->status);
        $this->assertSame(1, PaymentInstrument::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $refundEvent->id)->count());
        $this->assertSame('100.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);

        $cancellationEntry = DB::table('journal_entries')
            ->where('source_type', 'instrument')
            ->where('source_id', $originalInstrument->id)
            ->sole();
        $revenueAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::ProductRevenue);
        $portfolioAccount = Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', '5112')
            ->sole();
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $cancellationEntry->id,
            'account_id' => $revenueAccount->id,
            'debit' => '10.000',
            'credit' => '0.000',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $cancellationEntry->id,
            'account_id' => $portfolioAccount->id,
            'debit' => '0.000',
            'credit' => '10.000',
        ]);
        $revenueDebit = '0.000';
        $revenueCredit = '0.000';
        foreach (DB::table('journal_lines')->where('account_id', $revenueAccount->id)->get() as $line) {
            $revenueDebit = bcadd($revenueDebit, $this->numeric($line->debit), 3);
            $revenueCredit = bcadd($revenueCredit, $this->numeric($line->credit), 3);
        }
        $this->assertSame(0, bccomp($revenueDebit, $revenueCredit, 3));
        $this->assertSame(0, DB::table('journal_entries')
            ->where('source_type', 'pos_receipt_refund')
            ->where('source_id', $refundReceipt->id)
            ->count());

        $projectionAfter = DB::table('pos_receipt_payments')
            ->where('receipt_id', $refundReceipt->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertSame($projectionBefore, $projectionAfter);

        $bridge->apply($refundEvent);
        $this->assertSame(1, DB::table('journal_entries')
            ->where('source_type', 'instrument')
            ->where('source_id', $originalInstrument->id)
            ->count());
        $this->assertSame(1, DB::table('instrument_events')
            ->where('instrument_id', $originalInstrument->id)
            ->where('event_type', 'cancelled')
            ->count());
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'pos_refund_on_active_instrument')
            ->where('aggregate_id', "{$refundEvent->id}:payment:0")
            ->count());
    }

    public function test_vat_bearing_check_refund_reverses_revenue_net_and_output_vat_per_rate(): void
    {
        // W4-9 gate r1, F-1. A maturity-instrument (cheque/effet) POS refund does
        // NOT take `createPOSRefundReversalEntry` — it cancels the paper through
        // `CancellationShape::PosRevenue`. That arm debited revenue at the GROSS
        // instrument nominal and reversed no VAT at all, so once the SALE started
        // crediting `70x` net + `4457` per rate, every cheque-tendered POS refund
        // left `4457` permanently overstated by the VAT while the DGI declaration
        // netted the same refund out. Books and filing diverged again on exactly
        // the transaction this lane exists to fix.
        //
        // The pre-existing cases here all seal `vat_total = 0.00`, which is why
        // the asymmetry was invisible to them: reversing gross and reversing net
        // are the same number on a 0 % receipt. 10.00 TTC at 25 % = net 8.00 +
        // VAT 2.00, exact at scale 2.
        [$refundEvent, $saleEvent] = $this->projectedRefundReceipt('10.00', vatRate: '25.00');
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        $bridge->apply($saleEvent);
        $instrument = PaymentInstrument::query()
            ->where('idempotency_key', "fiscal_event:{$saleEvent->id}:instrument:0")
            ->sole();
        $this->assertSame(InstrumentStatus::Received, $instrument->status);

        $revenueAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::ProductRevenue);
        $vatAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::VatCollected);

        // The SALE recognised net revenue + output VAT (the portfolio account
        // carries the paper instead of cash — that part is unchanged).
        $this->assertSame(['8.000', '0.000'], $this->accountTotals($revenueAccount->id));
        $this->assertSame(['2.000', '0.000'], $this->accountTotals($vatAccount->id));

        $bridge->apply($refundEvent);

        $cancellationEntry = DB::table('journal_entries')
            ->where('source_type', 'instrument')
            ->where('source_id', $instrument->id)
            ->sole();

        // The cancellation reverses the SAME decomposition: net revenue and the
        // output VAT per sealed rate, NOT one gross revenue debit.
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $cancellationEntry->id,
            'account_id' => $revenueAccount->id,
            'debit' => '8.000',
            'credit' => '0.000',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $cancellationEntry->id,
            'account_id' => $vatAccount->id,
            'debit' => '2.000',
            'credit' => '0.000',
        ]);
        $this->assertSame(
            'POS output VAT reversed (instrument cancellation) 25.00%',
            (string) DB::table('journal_lines')
                ->where('journal_entry_id', $cancellationEntry->id)
                ->where('account_id', $vatAccount->id)
                ->value('description'),
            'the reversal line must name the sealed rate it reverses',
        );

        // The property that matters: sale + refund leaves BOTH accounts flat.
        [$revenueCredit, $revenueDebit] = $this->accountTotals($revenueAccount->id);
        $this->assertSame(0, bccomp($revenueCredit, $revenueDebit, 3), 'revenue must be flat after sale+refund');
        [$vatCredit, $vatDebit] = $this->accountTotals($vatAccount->id);
        $this->assertSame(0, bccomp($vatCredit, $vatDebit, 3), '4457 must be flat after sale+refund');

        // Still no cash and no separate refund entry — the instrument lane owns it.
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $refundEvent->id)->count());
        $this->assertSame('100.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
        $this->assertSame(InstrumentStatus::Cancelled, $instrument->refresh()->status);
    }

    public function test_the_vat_leg_census_reports_an_instrument_tendered_refund_as_clean(): void
    {
        // W4-9 gate r2, R2-2. This refund writes NO `pos_receipt_refund` entry:
        // the instrument lane cancels the paper, and the cancellation is keyed
        // `source_type='instrument'`, `source_id=<instrument id>`. The census
        // only knew about `pos_receipt*` entries keyed on `pos_receipts.id`, so
        // it flagged the very receipt F-1 had just taught to reverse `4457` —
        // and called it "never reached the GL", which points the operator at
        // re-provisioning something that is booked correctly. On a tenant that
        // takes cheques that is systematic noise in the one command that has to
        // be trusted at deploy time.
        [$refundEvent, $saleEvent] = $this->projectedRefundReceipt('10.00', vatRate: '25.00');
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($saleEvent);
        $bridge->apply($refundEvent);

        $code = Artisan::call('pos:census-vat-legs');

        $this->assertSame(
            0,
            $code,
            'a correctly booked instrument-tendered refund is not drift: '.Artisan::output(),
        );
    }

    public function test_refund_after_remittance_uses_standard_cash_reversal_and_one_alert(): void
    {
        [$refundEvent, $saleEvent] = $this->projectedRefundReceipt('10.00');
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($saleEvent);
        $instrument = PaymentInstrument::query()
            ->where('idempotency_key', "fiscal_event:{$saleEvent->id}:instrument:0")
            ->sole();
        $this->app->make(InstrumentLifecycleService::class)->deposit(
            $instrument->id,
            $this->bankRepositoryId,
            $this->operatorId,
        );
        $this->assertSame(InstrumentStatus::Deposited, $instrument->refresh()->status);

        $bridge->apply($refundEvent);
        $bridge->apply($refundEvent);

        $this->assertSame(InstrumentStatus::Deposited, $instrument->refresh()->status);
        $movement = DB::table('repository_movements')->where('source_id', $refundEvent->id)->sole();
        $this->assertSame(MovementDirection::Out->value, $movement->direction);
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $refundEvent->id)->count());
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $refundEvent->id)->count());
        $refundReceipt = Receipt::query()->where('fiscal_event_id', $refundEvent->id)->sole();
        $this->assertSame(1, DB::table('journal_entries')
            ->where('source_type', 'pos_receipt_refund')
            ->where('source_id', $refundReceipt->id)
            ->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'pos_refund_on_active_instrument')
            ->where('aggregate_id', "{$refundEvent->id}:payment:0")
            ->count());
        $movementRepositoryId = $movement->payment_repository_id;
        if (! is_string($movementRepositoryId)) {
            throw new RuntimeException('Movement repository id is not a string.');
        }
        $movementRepository = PaymentRepository::query()->findOrFail($movementRepositoryId);
        $this->assertSame('90.000', (string) $movementRepository->balance);
    }

    /**
     * W4R2-2 — the refund leg must not carry an `isIncoming() === true` type.
     *
     * Before the fix the bridge stamped `PaymentType::POS` on EVERY leg it
     * wrote, refund receipts included, with a positive amount. `POS->isIncoming()`
     * is `true`, so `DashboardController`'s "Payments Received" tile — which
     * filters on exactly that predicate — counted every refund as money that had
     * come IN. On the campaign tenant two refunds (42.800 + 85.600) were added to
     * a 452.000 sale instead of being left out of it.
     *
     * The arrangement deposits the original instrument first so the refund takes
     * the STANDARD CASH REVERSAL path (the same one
     * `test_refund_after_remittance_uses_standard_cash_reversal_and_one_alert`
     * pins), which is the path that actually writes a refund Payment row.
     */
    public function test_pos_refund_leg_is_typed_pos_refund_and_is_not_incoming(): void
    {
        [$refundEvent, $saleEvent] = $this->projectedRefundReceipt('10.00');
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($saleEvent);

        $instrument = PaymentInstrument::query()
            ->where('idempotency_key', "fiscal_event:{$saleEvent->id}:instrument:0")
            ->sole();
        $this->app->make(InstrumentLifecycleService::class)->deposit(
            $instrument->id,
            $this->bankRepositoryId,
            $this->operatorId,
        );

        $bridge->apply($refundEvent);

        $sale = Payment::query()->where('fiscal_event_id', $saleEvent->id)->sole();
        $refund = Payment::query()->where('fiscal_event_id', $refundEvent->id)->sole();

        // The SALE leg is unchanged — this fix must not move money the other way.
        $this->assertSame(PaymentType::POS, $sale->payment_type);
        $this->assertTrue($sale->payment_type->isIncoming());

        $this->assertSame(PaymentType::POSRefund, $refund->payment_type);
        $this->assertFalse(
            $refund->payment_type->isIncoming(),
            'A POS refund hands cash back; it must never satisfy the "payments received" predicate.',
        );
        $this->assertTrue($refund->payment_type->isOutgoing());

        // The amount stays POSITIVE — the direction lives in the type, not the
        // sign. Pinned so a later "fix" does not flip it and double-count.
        $this->assertSame(1, bccomp((string) $refund->amount, '0', 3));

        // W4R2-2 backfill — re-taint both legs to the legacy shape and let the
        // data migration separate them USING THE JOURNAL ENTRY the bridge linked.
        // The sale leg is the negative control: it must survive untouched.
        DB::table('payments')
            ->whereIn('id', [$sale->id, $refund->id])
            ->update(['payment_type' => 'pos']);

        $backfill = require base_path(
            'database/migrations/tenant/2026_08_25_150100_retype_supplier_and_pos_refund_payments.php'
        );
        $backfill->up();

        $this->assertSame(PaymentType::POSRefund, $refund->fresh()->payment_type);
        $this->assertSame(PaymentType::POS, $sale->fresh()->payment_type);

        // Idempotent.
        $backfill->up();
        $this->assertSame(PaymentType::POSRefund, $refund->fresh()->payment_type);
        $this->assertSame(PaymentType::POS, $sale->fresh()->payment_type);
    }

    public function test_ambiguous_received_candidates_are_not_cancelled_and_take_the_alert_cash_path(): void
    {
        $saleEvent = $this->projectedSaleReceiptLines(
            ['10.00', '10.00'],
            invoiceTypeCode: 'SALE',
            originalReceiptReference: null,
        );
        $refundEvent = $this->projectedSaleReceipt(
            '10.00',
            invoiceTypeCode: 'REFUND',
            originalReceiptReference: [
                'fiscal_event_id' => $saleEvent->id,
                'original_business_date' => $saleEvent->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'ambiguous partial refund',
            ],
        );
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($saleEvent);

        $bridge->apply($refundEvent);

        $this->assertSame(2, PaymentInstrument::query()->where('status', InstrumentStatus::Received)->count());
        $this->assertSame(0, PaymentInstrument::query()->where('status', InstrumentStatus::Cancelled)->count());
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $refundEvent->id)->count());
        $refundReceipt = Receipt::query()->where('fiscal_event_id', $refundEvent->id)->sole();
        $this->assertSame(1, DB::table('journal_entries')
            ->where('source_type', 'pos_receipt_refund')
            ->where('source_id', $refundReceipt->id)
            ->count());
        $this->assertSame(1, DB::table('audit_events')
            ->where('event_type', 'pos_refund_on_active_instrument')
            ->where('aggregate_id', "{$refundEvent->id}:payment:0")
            ->count());
    }

    public function test_gl_failure_during_cancel_propagates_and_rolls_back(): void
    {
        [$refundEvent, $saleEvent] = $this->projectedRefundReceipt('10.00');
        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($saleEvent);
        $instrument = PaymentInstrument::query()
            ->where('idempotency_key', "fiscal_event:{$saleEvent->id}:instrument:0")
            ->sole();
        Account::query()
            ->where('company_id', $this->companyId)
            ->where('system_purpose', SystemAccountPurpose::ProductRevenue)
            ->update(['system_purpose' => null]);

        try {
            $bridge->apply($refundEvent);
            $this->fail('Expected cancellation GL failure to propagate.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('product_revenue', strtolower($exception->getMessage()));
        }

        $this->assertSame(InstrumentStatus::Received, $instrument->refresh()->status);
        $this->assertSame(0, Payment::query()->where('fiscal_event_id', $refundEvent->id)->count());
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $refundEvent->id)->count());
        $this->assertSame(0, DB::table('instrument_events')
            ->where('instrument_id', $instrument->id)
            ->where('event_type', 'cancelled')
            ->count());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Project an original SALE receipt then a REFUND receipt that references it,
     * so PosCoreReceiptProjection resolves the refund to a Return receipt row
     * the Treasury bridge can bind Payment rows to.
     *
     * @param  numeric-string  $amount
     * @return array{0: FiscalEvent, 1: FiscalEvent} [refundEvent, originalSaleEvent]
     */
    private function projectedRefundReceipt(string $amount, string $vatRate = '0.00'): array
    {
        $original = $this->projectedSaleReceipt($amount, invoiceTypeCode: 'SALE', originalReceiptReference: null, vatRate: $vatRate);
        $originalReceipt = Receipt::query()->where('fiscal_event_id', $original->id)->firstOrFail();

        $refund = $this->projectedSaleReceipt(
            $amount,
            invoiceTypeCode: 'REFUND',
            vatRate: $vatRate,
            originalReceiptReference: [
                'fiscal_event_id' => $original->id,
                'original_business_date' => $original->business_date->toDateString(),
                'original_receipt_uuid' => '00000000-0000-4000-8000-000000000001',
                'refund_reason' => 'customer changed mind',
            ],
        );

        // sanity: the refund projected as a Return linked to the original.
        $refundReceipt = Receipt::query()->where('fiscal_event_id', $refund->id)->firstOrFail();
        $this->assertSame((string) $originalReceipt->id, (string) $refundReceipt->original_receipt_id);

        return [$refund, $original];
    }

    /**
     * @param  numeric-string  $amount
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function projectedSaleReceipt(
        string $amount,
        string $invoiceTypeCode,
        ?array $originalReceiptReference,
        string $vatRate = '0.00',
    ): FiscalEvent {
        return $this->projectedSaleReceiptLines([$amount], $invoiceTypeCode, $originalReceiptReference, $vatRate);
    }

    /**
     * @param  list<numeric-string>  $amounts
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function projectedSaleReceiptLines(
        array $amounts,
        string $invoiceTypeCode,
        ?array $originalReceiptReference,
        string $vatRate = '0.00',
    ): FiscalEvent {
        $event = $this->storeSaleReceiptFiscalEvent($amounts, $invoiceTypeCode, $originalReceiptReference, $vatRate);
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        return $event;
    }

    /**
     * Ledger totals for one account across EVERY posted entry, as
     * `[credit, debit]` at scale 3.
     *
     * @return array{0: numeric-string, 1: numeric-string}
     */
    private function accountTotals(string $accountId): array
    {
        $credit = '0.000';
        $debit = '0.000';
        foreach (DB::table('journal_lines')->where('account_id', $accountId)->get() as $line) {
            $credit = bcadd($credit, $this->numeric($line->credit), 3);
            $debit = bcadd($debit, $this->numeric($line->debit), 3);
        }

        return [$credit, $debit];
    }

    /** @return numeric-string */
    private function numeric(mixed $value): string
    {
        $numeric = is_scalar($value) ? (string) $value : '';
        if (! is_numeric($numeric)) {
            throw new RuntimeException('Expected a numeric money value.');
        }

        return $numeric;
    }

    /**
     * @param  list<numeric-string>  $amounts
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function storeSaleReceiptFiscalEvent(
        array $amounts,
        string $invoiceTypeCode,
        ?array $originalReceiptReference,
        string $vatRate = '0.00',
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);
        $this->sequence++;
        $total = '0.00';
        $payments = [];
        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount, 2);
            $payments[] = [
                'amount' => $amount,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CHECK',
            ];
        }

        // W4-9 gate r1 (F-1) — the 0 % default is kept so every pre-existing
        // case stays byte-identical, but a VAT-bearing arm is now expressible.
        // The tender is TTC, so the net is backed OUT of it: at 25 % a 10.00
        // cheque is net 8.00 + VAT 2.00, exact at scale 2 with no rounding to
        // argue about. `vat_breakdown` is the sealed fact the ledger reads.
        $net = bccomp($vatRate, '0', 2) === 0
            ? $total
            : bcdiv(bcmul($total, '100', 4), bcadd('100', $vatRate, 4), 2);
        $vat = bcsub($total, $net, 2);
        // R2-5 — the category has to follow the rate, or the fixture describes a
        // receipt that cannot exist ('Z' = zero-rated at 25 %). Nothing in the
        // projector or the allocator reads it (that was F-6's point), but the
        // next person to parameterise this must not be misled.
        $taxCategory = bccomp($vatRate, '0', 2) === 0 ? 'Z' : 'S';

        $payload = [
            'business_date' => $businessDate->toDateString(),
            'approval_references' => [],
            'buyer' => null,
            'cashier_id' => '11111111-1111-4111-8111-111111111111',
            'cashier_name' => 'Default Cashier',
            'consumption_mode' => null,
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'event_time_device' => '2026-05-20T14:30:00.000Z',
            'invoice_type_code' => $invoiceTypeCode,
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.00',
                'line_discount_reason' => null,
                'line_subtotal' => $net,
                'line_vat' => $vat,
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => $taxCategory,
                'unit_price' => $total,
                'vat_rate' => $vatRate,
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => $payments,
            'receipt_uuid' => Str::uuid()->toString(),
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $net,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $total,
                'net_amount' => $net,
                'rate' => $vatRate,
                'tax_category_code' => $taxCategory,
                'vat_amount' => $vat,
            ]],
            'vat_total' => $vat,
            'vouchers_redeemed' => [],
        ];

        return $this->persistEvent(FiscalEventType::SALE_RECEIPT, $payload, $businessDate, $eventTime, $previousHash, $this->sequence);
    }

    private function grantMembership(string $userId, string $companyId): void
    {
        UserCompanyMembership::query()->create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persistEvent(
        FiscalEventType $type,
        array $payload,
        CarbonInterface $businessDate,
        CarbonInterface $eventTime,
        string $previousHash,
        int $sequenceNumber,
    ): FiscalEvent {
        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => $type->value,
            'event_version' => 1,
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
        $currentHash = hash('sha256', $canonicalBytes);

        $event = FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => $type,
            'event_version' => 1,
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
            'current_hash' => $currentHash,
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $sorted = $this->sortRecursive($value);
        $json = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            return array_map(fn ($v) => $this->sortRecursive($v), $value);
        }
        ksort($value);

        return array_map(fn ($v) => $this->sortRecursive($v), $value);
    }
}
