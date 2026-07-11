<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
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
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\ReceiveInstrumentData;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class PosBridgeInstrumentTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $repositoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'FR',
            'currency' => 'EUR',
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

        $user = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Bridge Cashier']);
        $this->operatorId = $user->id;
        UserCompanyMembership::query()->create([
            'user_id' => $user->id,
            'company_id' => $this->companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CHECK',
            'name' => 'Check',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CARD',
            'name' => 'Card',
            'has_maturity' => false,
            'instrument_kind' => null,
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'DIRECT_DEBIT',
            'name' => 'Direct debit',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Other,
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
        $cashAccount = Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', '53')
            ->firstOrFail();
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'EUR',
            'balance' => '0.000',
        ]);
        $this->repositoryId = $repository->id;

        app(CompanyContext::class)->clear();
    }

    public function test_cash_and_check_split_moves_only_cash_and_posts_check_to_portfolio(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '7.00', 'method_code' => 'CASH'],
            ['amount' => '3.00', 'method_code' => 'CHECK'],
        ]);
        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->sole();
        $projectionBefore = DB::table('pos_receipt_payments')
            ->where('receipt_id', $receipt->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payments = Payment::query()
            ->where('fiscal_event_id', $event->id)
            ->orderBy('idempotency_key')
            ->get();
        $this->assertCount(2, $payments);

        $checkPayment = $payments->firstWhere('payment_method_id', PaymentMethod::query()->where('code', 'CHECK')->value('id'));
        $this->assertInstanceOf(Payment::class, $checkPayment);
        $this->assertNotNull($checkPayment->instrument_id);

        $instrument = PaymentInstrument::query()->findOrFail($checkPayment->instrument_id);
        $this->assertSame(InstrumentKind::Cheque, $instrument->kind);
        $this->assertSame(InstrumentDirection::Inbound, $instrument->direction);
        $this->assertSame(InstrumentOrigin::Pos, $instrument->origin);
        $this->assertSame(InstrumentStatus::Received, $instrument->status);
        $this->assertTrue($instrument->needs_details);
        $this->assertSame('POS-'.substr($event->id, 0, 8).'-1', $instrument->reference);
        $this->assertSame("fiscal_event:{$event->id}:instrument:1", $instrument->idempotency_key);

        $portfolioAccount = Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', '5112')
            ->sole();
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $checkPayment->journal_entry_id,
            'account_id' => $portfolioAccount->id,
            'debit' => '3.000',
            'credit' => '0.000',
            'line_order' => 0,
        ]);

        $movements = DB::table('repository_movements')->where('source_id', $event->id)->get();
        $this->assertCount(1, $movements);
        $this->assertSame("fiscal_event:{$event->id}:payment:0", $movements->sole()->idempotency_key);
        $this->assertSame('7.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);

        $projectionAfter = DB::table('pos_receipt_payments')
            ->where('receipt_id', $receipt->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertSame($projectionBefore, $projectionAfter);
    }

    public function test_replay_keeps_the_complete_maturity_leg_set_idempotent(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '7.00', 'method_code' => 'CASH'],
            ['amount' => '3.00', 'method_code' => 'CHECK'],
        ]);
        $bridge = $this->app->make(TreasuryReceiptBridge::class);

        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(2, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, PaymentInstrument::query()->where('idempotency_key', "fiscal_event:{$event->id}:instrument:1")->count());
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertSame(2, $this->posPaymentEntryCount($event));
        $this->assertSame('7.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    public function test_pre_cutover_cash_debit_replay_does_not_mint_an_instrument(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CHECK'],
        ]);
        $method = PaymentMethod::query()->where('code', 'CHECK')->sole();
        [$paymentId, $journalEntryId] = $this->seedPriorLegWrite($event, $method->id, '10.00');

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertSame($paymentId, $payment->id);
        $this->assertSame($journalEntryId, $payment->journal_entry_id);
        $this->assertNull($payment->instrument_id);
        $this->assertSame(0, PaymentInstrument::query()->count());
        $this->assertSame(1, $this->posPaymentEntryCount($event));
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertSame('10.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    public function test_post_cutover_replay_recovers_a_missing_instrument_without_moving_cash(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CHECK'],
        ]);
        $method = PaymentMethod::query()->where('code', 'CHECK')->sole();
        $portfolioAccountId = Account::query()
            ->where('company_id', $this->companyId)
            ->where('code', '5112')
            ->value('id');
        $this->assertIsString($portfolioAccountId);
        [$paymentId, $journalEntryId] = $this->seedPriorLegWrite(
            $event,
            $method->id,
            '10.00',
            $portfolioAccountId,
            false,
        );

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertSame($paymentId, $payment->id);
        $this->assertSame($journalEntryId, $payment->journal_entry_id);
        $this->assertNotNull($payment->instrument_id);
        $this->assertSame(1, PaymentInstrument::query()->whereKey($payment->instrument_id)->count());
        $this->assertSame($payment->id, PaymentInstrument::query()->findOrFail($payment->instrument_id)->payment_id);
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertSame('0.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    public function test_conflicting_instrument_idempotency_key_is_rejected_semantically(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CHECK'],
        ]);
        $method = PaymentMethod::query()->where('code', 'CHECK')->sole();
        $this->app->make(InstrumentLifecycleService::class)->receive(new ReceiveInstrumentData(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            paymentMethodId: $method->id,
            kind: InstrumentKind::Cheque,
            direction: InstrumentDirection::Inbound,
            origin: InstrumentOrigin::Pos,
            reference: 'wrong-semantic-replay',
            amount: '9.00',
            currency: 'EUR',
            repositoryId: $this->repositoryId,
            idempotencyKey: "fiscal_event:{$event->id}:instrument:0",
            needsDetails: true,
        ));

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected the semantic idempotency mismatch to abort the bridge.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('idempotency conflict', $exception->getMessage());
        }

        $this->assertSame(0, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, PaymentInstrument::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $event->id)->count());
    }

    public function test_missing_portfolio_account_fails_closed_and_rolls_back_the_leg(): void
    {
        Account::query()->where('company_id', $this->companyId)->where('code', '5112')->delete();
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CHECK'],
        ]);

        try {
            $this->app->make(TreasuryReceiptBridge::class)->apply($event);
            $this->fail('Expected the missing portfolio account to abort the bridge.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('checks_to_collect', $exception->getMessage());
        }

        $this->assertSame(0, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(0, PaymentInstrument::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertSame('0.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    public function test_voucher_shaped_non_maturity_leg_keeps_the_cash_path(): void
    {
        $event = $this->projectedSaleReceipt([[
            'amount' => '5.00',
            'method_code' => 'CARD',
            'instrument_type' => 'restaurant_voucher',
            'instrument_serial' => 'RV-2026-001',
        ]]);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertNull($payment->instrument_id);
        $this->assertSame(0, PaymentInstrument::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertJournalDebitUsesRepository($payment);
        $this->assertSame('5.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    public function test_has_maturity_other_kind_keeps_the_existing_cash_path(): void
    {
        $event = $this->projectedSaleReceipt([[
            'amount' => '6.00',
            'method_code' => 'DIRECT_DEBIT',
        ]]);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertNull($payment->instrument_id);
        $this->assertSame(0, PaymentInstrument::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertJournalDebitUsesRepository($payment);
        $this->assertSame('6.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    /**
     * @param  numeric-string  $amount
     * @return array{0: string, 1: string}
     */
    private function seedPriorLegWrite(
        FiscalEvent $event,
        string $methodId,
        string $amount,
        ?string $debitOverrideId = null,
        bool $withMovement = true,
    ): array {
        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->sole();
        $repository = PaymentRepository::query()->findOrFail($this->repositoryId);
        $payment = Payment::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $event->tenant_id,
            'company_id' => $event->company_id,
            'partner_id' => $receipt->partner_id,
            'payment_method_id' => $methodId,
            'repository_id' => $repository->id,
            'amount' => $amount,
            'currency' => $receipt->currency,
            'payment_date' => $receipt->posted_at,
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::POS,
            'origin' => PaymentOrigin::Pos,
            'fiscal_event_id' => $event->id,
            'idempotency_key' => "fiscal_event:{$event->id}:payment:0",
            'reference' => "POS Receipt {$receipt->receipt_number} - Payment 1",
            'notes' => 'pre-cutover fixture',
        ]);
        $entry = $this->app->make(GeneralLedgerService::class)->createPOSPaymentEntry(
            $payment,
            $receipt,
            $repository,
            $debitOverrideId,
        );
        $payment->journal_entry_id = $entry->id;
        $payment->save();

        if ($withMovement) {
            DB::transaction(function () use ($event, $repository, $amount, $entry): void {
                $this->app->make(TreasuryMovementServiceInterface::class)->record(new MovementIntent(
                    repositoryId: $repository->id,
                    tenantId: $event->tenant_id,
                    companyId: $event->company_id,
                    direction: MovementDirection::In,
                    amount: $amount,
                    currency: $repository->currency,
                    sourceType: MovementSourceType::FiscalEvent,
                    sourceId: $event->id,
                    idempotencyLeg: 'payment:0',
                    journalEntryId: $entry->id,
                    occurredAt: null,
                    reasonCode: null,
                    reversesMovementId: null,
                    createdBy: null,
                    notes: null,
                    allowWhileFrozen: true,
                ));
            });
        }

        return [$payment->id, $entry->id];
    }

    private function posPaymentEntryCount(FiscalEvent $event): int
    {
        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->sole();

        return DB::table('journal_entries')
            ->where('source_type', 'pos_receipt')
            ->where('source_id', $receipt->id)
            ->count();
    }

    private function assertJournalDebitUsesRepository(Payment $payment): void
    {
        $repository = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $payment->journal_entry_id,
            'account_id' => $repository->gl_account_id,
            'line_order' => 0,
        ]);
    }

    /** @param list<array<string, mixed>> $paymentLines */
    private function projectedSaleReceipt(array $paymentLines): FiscalEvent
    {
        $event = $this->storeSaleReceiptFiscalEvent($paymentLines);
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        return $event;
    }

    /** @param list<array<string, mixed>> $paymentLines */
    private function storeSaleReceiptFiscalEvent(array $paymentLines): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $total = '0.00';
        $payments = [];
        foreach ($paymentLines as $line) {
            /** @var numeric-string $amount */
            $amount = $line['amount'] ?? '0.00';
            $total = bcadd($total, $amount, 2);
            $payments[] = [
                'amount' => $amount,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => $line['instrument_serial'] ?? null,
                'instrument_type' => $line['instrument_type'] ?? null,
                'method_code' => $line['method_code'] ?? 'CASH',
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
            'invoice_type_code' => 'SALE',
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
            'original_receipt_reference' => null,
            'payments' => $payments,
            'receipt_uuid' => '00000000-0000-4000-8000-000000000001',
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

        return $this->persistEvent($payload, $businessDate, $eventTime);
    }

    /** @param array<string, mixed> $payload */
    private function persistEvent(array $payload, CarbonInterface $businessDate, CarbonInterface $eventTime): FiscalEvent
    {
        $canonicalArray = [
            'business_date' => $businessDate->toDateString(),
            'company_id' => $this->companyId,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'event_type' => FiscalEventType::SALE_RECEIPT->value,
            'event_version' => 1,
            'operator_id' => $this->operatorId,
            'payload' => $payload,
            'previous_hash' => str_repeat('0', 64),
            'reference_document_id' => null,
            'reference_event_id' => null,
            'sequence_number' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'tenant_id' => $this->tenantId,
            'terminal_id' => $this->terminalId,
        ];
        $canonicalBytes = $this->canonicalEncode($canonicalArray);

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 1,
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'server_received_at' => $eventTime,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('0', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /** @param array<string, mixed> $value */
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
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
    }
}
