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
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 21 (Treasury spine) — a SALE_RECEIPT fiscal event whose
 * `invoice_type_code='REFUND'` (or 'VOID') pays cash OUT of the drawer and
 * posts a GL REVERSAL of the sale entry, NOT cash IN + a sale GL.
 *
 * There is NO separate REFUND fiscal event type (REFUND_RECEIPT / SALE_VOID
 * are RESERVED_UNREACHABLE and never produced). Refunds ride the SALE_RECEIPT
 * event carrying `invoice_type_code='REFUND'` + a non-null
 * `original_receipt_reference` (see SaleReceiptPayload docblock). The
 * TreasuryReceiptBridge branches on that code:
 *   - refund/void leg → `movement(..., Out)` (drawer decrements) + a GL
 *     reversal (Dr Revenue / Cr Cash — the inverse of the sale entry a normal
 *     receipt of the same tender posts);
 *   - normal sale leg → `movement(..., In)` + the sale GL (unchanged).
 *
 * Rule 20: projections run with NO CompanyContext — setUp() clears it after
 * seeding, and every scale-resolution path is passed the entity currency.
 */
final class PosRefundReceiptBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $repositoryId;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create(['tenant_id' => $this->tenantId, 'currency' => 'EUR']);
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

        // Rule 20 — projections run on a Horizon worker with NO CompanyContext.
        app(CompanyContext::class)->clear();
    }

    // =================================================================
    // (a) refund SALE_RECEIPT → drawer decrements (Out) + GL reversal
    // =================================================================

    public function test_refund_receipt_decrements_drawer_and_posts_gl_reversal(): void
    {
        [$refundEvent] = $this->projectedRefundReceipt('10.00');

        $this->app->make(TreasuryReceiptBridge::class)->apply($refundEvent);

        // One Payment leg, one movement — the refund tender leg.
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $refundEvent->id)->count());

        $movement = DB::table('repository_movements')
            ->where('source_type', MovementSourceType::FiscalEvent->value)
            ->where('source_id', $refundEvent->id)
            ->first();
        $this->assertNotNull($movement);
        // Direction is OUT — cash leaves the drawer.
        $this->assertSame(MovementDirection::Out->value, $movement->direction);
        $this->assertSame("fiscal_event:{$refundEvent->id}:payment:0", $movement->idempotency_key);
        $this->assertNotNull($movement->journal_entry_id);

        // Balance dropped by the refund amount exactly once (100 - 10 = 90).
        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '90.000', 3));

        // GL REVERSAL: cash CREDITED (out), revenue DEBITED — the inverse of a
        // normal sale receipt (which debits cash, credits revenue).
        $receipt = Receipt::query()->where('fiscal_event_id', $refundEvent->id)->firstOrFail();
        $entry = DB::table('journal_entries')
            ->where('source_type', 'pos_receipt_refund')
            ->where('source_id', $receipt->id)
            ->first();
        $this->assertNotNull($entry, 'a pos_receipt_refund journal entry must be posted');

        $cashAccountId = (string) Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Cash)->id;
        $revenueAccountId = (string) Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::ProductRevenue)->id;

        $lines = DB::table('journal_lines')->where('journal_entry_id', $entry->id)->get();
        $this->assertCount(2, $lines);

        $cashLine = $lines->firstWhere('account_id', $cashAccountId);
        $revenueLine = $lines->firstWhere('account_id', $revenueAccountId);
        $this->assertNotNull($cashLine);
        $this->assertNotNull($revenueLine);

        // Cash is CREDITED (money out), revenue is DEBITED (revenue reversed).
        $this->assertMoneyEquals('10.00', $cashLine->credit);
        $this->assertMoneyEquals('0', $cashLine->debit);
        $this->assertMoneyEquals('10.00', $revenueLine->debit);
        $this->assertMoneyEquals('0', $revenueLine->credit);

        // The JE balances (total debit == total credit).
        $totalDebit = '0';
        $totalCredit = '0';
        foreach ($lines as $l) {
            $totalDebit = bcadd($totalDebit, $this->numeric($l->debit), 2);
            $totalCredit = bcadd($totalCredit, $this->numeric($l->credit), 2);
        }
        $this->assertSame(0, bccomp($totalDebit, $totalCredit, 2));

        // Payment is linked to the reversal entry.
        $payment = Payment::query()->where('fiscal_event_id', $refundEvent->id)->sole();
        $this->assertSame((string) $entry->id, (string) $payment->journal_entry_id);
    }

    // =================================================================
    // (b) REGRESSION — a normal SALE_RECEIPT still increments (In) + sale GL
    // =================================================================

    public function test_normal_sale_receipt_still_increments_with_sale_gl(): void
    {
        $event = $this->projectedSaleReceipt('10.00', invoiceTypeCode: 'SALE', originalReceiptReference: null);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $movement = DB::table('repository_movements')
            ->where('source_id', $event->id)
            ->first();
        $this->assertNotNull($movement);
        // Direction IN — cash into the drawer (unchanged Task-20 behavior).
        $this->assertSame(MovementDirection::In->value, $movement->direction);

        // Balance rose by the sale amount (100 + 10 = 110).
        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '110.000', 3));

        // A normal sale GL entry (source_type=pos_receipt), NOT a refund reversal.
        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
        $this->assertSame(1, DB::table('journal_entries')
            ->where('source_type', 'pos_receipt')
            ->where('source_id', $receipt->id)
            ->count());
        $this->assertSame(0, DB::table('journal_entries')
            ->where('source_type', 'pos_receipt_refund')
            ->where('source_id', $receipt->id)
            ->count());
    }

    // =================================================================
    // (c) refund replay is idempotent — balance decrements once
    // =================================================================

    public function test_refund_replay_is_idempotent(): void
    {
        [$refundEvent] = $this->projectedRefundReceipt('10.00');

        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($refundEvent);
        $bridge->apply($refundEvent);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $refundEvent->id)->count());
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $refundEvent->id)->count());

        $receipt = Receipt::query()->where('fiscal_event_id', $refundEvent->id)->firstOrFail();
        $this->assertSame(1, DB::table('journal_entries')
            ->where('source_type', 'pos_receipt_refund')
            ->where('source_id', $receipt->id)
            ->count());

        // Balance decremented exactly once.
        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '90.000', 3));
    }

    // =================================================================
    // (d) freeze — a device refund leg succeeds on a frozen repo
    // =================================================================

    public function test_device_refund_leg_succeeds_on_frozen_repo(): void
    {
        [$refundEvent] = $this->projectedRefundReceipt('10.00');

        $this->app->make(TreasuryMovementServiceInterface::class)
            ->freeze($this->repositoryId, 'reconcile in progress');

        // A SALE_RECEIPT (refund) is device-authored — allowWhileFrozen=true.
        $this->app->make(TreasuryReceiptBridge::class)->apply($refundEvent);

        $movement = DB::table('repository_movements')
            ->where('source_id', $refundEvent->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame(MovementDirection::Out->value, $movement->direction);
        $this->assertTrue((bool) $movement->recorded_while_frozen);
    }

    // =================================================================
    // (e) W-5b Option B intent-flag sweep — a device refund leg RECORDS +
    // ALERTS (never throws) when it would take the repository negative,
    // mirroring the allowWhileFrozen precedent above site-for-site.
    // =================================================================

    public function test_device_refund_leg_records_and_alerts_when_it_would_go_negative(): void
    {
        Log::spy();

        // Lower the seeded repository's balance below the refund amount, via
        // the same port-GUC bracket PaymentRepositoryFactory::store() uses —
        // a direct write outside the port is otherwise trigger-blocked on
        // pgsql, and this must not lay down a phantom movement.
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($isPgsql) {
            DB::statement("SET LOCAL app.treasury_movement_port = 'on'");
        }
        DB::table('payment_repositories')->where('id', $this->repositoryId)->update(['balance' => '5.000']);
        if ($isPgsql) {
            DB::statement("SET LOCAL app.treasury_movement_port = 'off'");
        }

        [$refundEvent] = $this->projectedRefundReceipt('10.00');

        // A SALE_RECEIPT (refund) is device-authored — allowNegative mirrors
        // allowWhileFrozen and is TRUE on this leg, so the bridge RECORDS the
        // Out movement instead of throwing InsufficientRepositoryBalanceException
        // and poisoning the fiscal-projection queue. Company currency is EUR
        // (scale 2, the ISO 4217 default) — 5.00 - 10.00 = -5.00.
        $this->app->make(TreasuryReceiptBridge::class)->apply($refundEvent);

        $movement = DB::table('repository_movements')
            ->where('source_id', $refundEvent->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertSame(MovementDirection::Out->value, $movement->direction);

        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '-5.00', 2));

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                'Treasury movement recorded a negative repository balance',
                \Mockery::on(fn (array $context): bool => $context['repository_id'] === $this->repositoryId
                    && $context['balance_after'] === '-5.00'),
            );
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
        $event = $this->storeSaleReceiptFiscalEvent($amount, $invoiceTypeCode, $originalReceiptReference);
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        return $event;
    }

    /**
     * @param  numeric-string  $amount
     * @param  array<string, mixed>|null  $originalReceiptReference
     */
    private function storeSaleReceiptFiscalEvent(
        string $amount,
        string $invoiceTypeCode,
        ?array $originalReceiptReference,
    ): FiscalEvent {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);
        $this->sequence++;

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
                'line_subtotal' => $amount,
                'line_vat' => '0.00',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'X',
                'tax_category_code' => 'Z',
                'unit_price' => $amount,
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => $originalReceiptReference,
            'payments' => [[
                'amount' => $amount,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
            ]],
            'receipt_uuid' => Str::uuid()->toString(),
            'seller' => [
                'address' => ['city' => 'Paris', 'country_code' => 'FR', 'postal_code' => '75001', 'street' => '1 rue de la Paix'],
                'name' => 'Default Seller S.A.',
                'tax_jurisdiction_country_code' => 'FR',
                'tax_number' => '12345678901234',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'subtotal' => $amount,
            'table_id' => null,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'total' => $amount,
            'training_flag' => false,
            'transaction_discount_amount' => '0.00',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => $amount,
                'net_amount' => $amount,
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.00',
            ]],
            'vat_total' => '0.00',
            'vouchers_redeemed' => [],
        ];

        return $this->persistEvent(FiscalEventType::SALE_RECEIPT, $payload, $businessDate, $eventTime, $previousHash, $this->sequence);
    }

    /**
     * Coerce a DB-sourced money value to a numeric-string for bc* math.
     *
     * @return numeric-string
     */
    private function numeric(mixed $value): string
    {
        $str = is_scalar($value) ? (string) $value : '0';
        if (! is_numeric($str)) {
            throw new RuntimeException("non-numeric money value: {$str}");
        }

        return $str;
    }

    private function assertMoneyEquals(string $expected, mixed $actual, int $scale = 2): void
    {
        if (! is_numeric($expected)) {
            throw new RuntimeException("non-numeric expected: {$expected}");
        }
        $this->assertSame(0, bccomp($expected, $this->numeric($actual), $scale));
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
