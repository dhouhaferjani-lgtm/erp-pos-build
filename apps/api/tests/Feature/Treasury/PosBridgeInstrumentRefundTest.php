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
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    private function projectedRefundReceipt(string $amount): array
    {
        $original = $this->projectedSaleReceipt($amount, invoiceTypeCode: 'SALE', originalReceiptReference: null);
        $originalReceipt = Receipt::query()->where('fiscal_event_id', $original->id)->firstOrFail();

        $refund = $this->projectedSaleReceipt(
            $amount,
            invoiceTypeCode: 'REFUND',
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
    ): FiscalEvent {
        return $this->projectedSaleReceiptLines([$amount], $invoiceTypeCode, $originalReceiptReference);
    }

    /**
     * @param  list<numeric-string>  $amounts
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function projectedSaleReceiptLines(
        array $amounts,
        string $invoiceTypeCode,
        ?array $originalReceiptReference,
    ): FiscalEvent {
        $event = $this->storeSaleReceiptFiscalEvent($amounts, $invoiceTypeCode, $originalReceiptReference);
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        return $event;
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
                'line_subtotal' => $total,
                'line_vat' => '0.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => $total,
                'vat_rate' => '0.00',
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
            'subtotal' => $total,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $total,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $total,
                'net_amount' => $total,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
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
