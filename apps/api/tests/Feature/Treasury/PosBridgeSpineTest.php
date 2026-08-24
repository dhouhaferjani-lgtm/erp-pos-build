<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Accounting\Domain\Services\PosReceiptVatAllocator;
use App\Shared\Domain\CurrencyScale;
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
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 20 (Treasury spine Wave D) — POS projection bridges write repository
 * movements via the single write port with canonical-index idempotency.
 *
 * Contract pinned here:
 *   - each SALE_RECEIPT tender line → ONE movement keyed
 *     `fiscal_event:{event_id}:payment:{canonical_index}` (positional order in
 *     the sealed canonical `payload.payments[]` — stable across replays);
 *   - complete-set replay: a PARTIAL prior write (only leg 0) is COMPLETED
 *     (leg 1 written, leg 0 idempotent hit), never skipped;
 *   - full replay is fully idempotent (no double-count on the balance);
 *   - DEPOSIT_RECEIPT (server-authored, isServerOnly) → one movement keyed
 *     `payment:0`, resolved on `gl_account_id`;
 *   - freeze policy: a device-replay SALE_RECEIPT leg SUCCEEDS on a frozen repo
 *     (recorded_while_frozen=true, allowWhileFrozen), while a server
 *     DEPOSIT_RECEIPT leg THROWS (allowWhileFrozen=false).
 *
 * Rule 20: projections run with NO CompanyContext — setUp() clears it after
 * seeding, and every scale-resolution path is passed the entity currency.
 */
final class PosBridgeSpineTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $operatorId;

    private string $cashMethodId;

    private string $cardMethodId;

    private string $repositoryId;

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

        $this->cashMethodId = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ])->id;

        $this->cardMethodId = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CARD',
            'name' => 'Card',
        ])->id;

        $companyModel = Company::query()->findOrFail($this->companyId);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($companyModel);

        // The default chart seed does not assign the CustomerAdvance purpose;
        // the deposit bridge's pure-advance FIFO path posts a CustomerAdvance
        // journal entry, so seed one explicitly for test (d).
        if (Account::findByPurpose($this->companyId, SystemAccountPurpose::CustomerAdvance) === null) {
            Account::factory()->liability()->create([
                'tenant_id' => $this->tenantId,
                'company_id' => $this->companyId,
                'code' => 'ADV-TEST-419',
                'name' => 'Customer Advances',
                'system_purpose' => SystemAccountPurpose::CustomerAdvance,
                'is_active' => true,
            ]);
        }

        $cashAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Cash);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'EUR',
            'balance' => '0.000',
        ]);
        $this->repositoryId = $repository->id;

        // Rule 20 — projections run on a Horizon worker with NO CompanyContext.
        // Clear the context the seeding bound so the bridges see worker reality.
        app(CompanyContext::class)->clear();
    }

    // =================================================================
    // (a) two-tender sale → two distinct-key movements, balances move
    // =================================================================

    public function test_two_tender_sale_creates_two_movements_with_distinct_canonical_keys(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '7.00', 'method_code' => 'CASH'],
            ['amount' => '3.00', 'method_code' => 'CARD'],
        ]);

        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        // Two payments, two GL entries, two movements.
        $this->assertSame(2, Payment::query()->where('fiscal_event_id', $event->id)->count());

        $movements = DB::table('repository_movements')
            ->where('source_type', MovementSourceType::FiscalEvent->value)
            ->where('source_id', $event->id)
            ->orderBy('idempotency_key')
            ->get();
        $this->assertCount(2, $movements);

        $keys = $movements->pluck('idempotency_key')->all();
        $this->assertContains("fiscal_event:{$event->id}:payment:0", $keys);
        $this->assertContains("fiscal_event:{$event->id}:payment:1", $keys);

        // Both movements are IN legs linked to a GL entry.
        foreach ($movements as $m) {
            $this->assertSame(MovementDirection::In->value, $m->direction);
            $this->assertNotNull($m->journal_entry_id);
        }

        // The repository balance moved by the full receipt total (10.000).
        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '10.000', 3));
    }

    // =================================================================
    // (b) complete-set replay — partial (leg 0 only) → leg 1 written
    // =================================================================

    public function test_complete_set_replay_completes_a_partial_prior_write(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '7.00', 'method_code' => 'CASH'],
            ['amount' => '3.00', 'method_code' => 'CARD'],
        ]);

        // Simulate the REALISTIC partial that the atomic bridge can actually
        // leave: leg 0's Payment + its POS-payment GL entry + its movement all
        // landed (per-leg idempotency_key `payment:0`), while leg 1 never ran.
        // This is what a prior apply() that failed mid-loop, or a resume after
        // a crash between legs, leaves behind — NOT a bare movement with no
        // Payment (which nothing in the bridge produces).
        [$leg0PaymentId, $leg0JournalEntryId] = $this->seedPriorLegWrite(
            event: $event,
            index: 0,
            amount: '7.00',
            methodId: $this->cashMethodId,
            idempotencyKey: "fiscal_event:{$event->id}:payment:0",
            withMovement: true,
        );

        // Baseline: leg 0 only — one Payment, one GL entry, one movement, and
        // the repository balance moved by leg 0's amount alone.
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, $this->movementCount($event));
        $this->assertSame(1, $this->posPaymentEntryCount($event));
        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '7.000', 3));

        // Replay the bridge — it must REUSE leg 0 (same Payment row, same GL
        // entry, no second GL post for leg 0) and newly create leg 1, for a
        // total of exactly 2 legs.
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        // Exactly 2 Payments / 2 movements total.
        $this->assertSame(2, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(2, $this->movementCount($event));

        // Leg 0 was REUSED: the same Payment row (identity unchanged) still
        // carries the same GL entry — the create branch never ran for it, so no
        // duplicate POS-revenue post. Exactly 2 POS-payment GL entries exist
        // (leg 0's pre-seeded entry + leg 1's new one).
        $leg0After = Payment::query()
            ->where('idempotency_key', "fiscal_event:{$event->id}:payment:0")
            ->sole();
        $this->assertSame($leg0PaymentId, $leg0After->id);
        $this->assertSame($leg0JournalEntryId, $leg0After->journal_entry_id);
        $this->assertSame(2, $this->posPaymentEntryCount($event));

        $keys = DB::table('repository_movements')
            ->where('source_id', $event->id)
            ->pluck('idempotency_key')
            ->all();
        $this->assertContains("fiscal_event:{$event->id}:payment:0", $keys);
        $this->assertContains("fiscal_event:{$event->id}:payment:1", $keys);

        // Balance moved by the full receipt total exactly once (7 + 3 = 10).
        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '10.000', 3));
    }

    // =================================================================
    // (b2) legacy null-key replay — a pre-Task-20 event is recognized
    //      and skipped (no double Payment / GL / movement). This is the
    //      REPLAY path clean-DB tests miss: the old bridge left Payments
    //      with idempotency_key = NULL, which the per-leg lookup can't see.
    // =================================================================

    public function test_legacy_null_key_event_is_recognized_and_skipped_on_replay(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CASH'],
        ]);

        // Simulate what the PRE-Task-20 bridge left: a Payment stamped
        // origin=Pos + fiscal_event_id but NO idempotency_key (the per-leg key
        // scheme did not exist yet), plus its POS-payment GL entry. The old
        // bridge predates the movement port, so it left ZERO movements.
        $this->seedPriorLegWrite(
            event: $event,
            index: 0,
            amount: '10.00',
            methodId: $this->cashMethodId,
            idempotencyKey: null,
            withMovement: false,
        );

        // Baseline: one legacy Payment, one GL entry, no movements, balance 0.
        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, $this->posPaymentEntryCount($event));
        $this->assertSame(0, $this->movementCount($event));

        // Replay the bridge. Because a legacy null-key pos Payment exists for
        // this event, the whole event is treated as already-settled: NO new
        // Payment, NO new GL entry, NO new movement, balance untouched.
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(1, $this->posPaymentEntryCount($event));
        $this->assertSame(0, $this->movementCount($event));

        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '0.000', 3));
    }

    // =================================================================
    // (c) full replay is fully idempotent (balance moved once)
    // =================================================================

    public function test_full_replay_is_idempotent_and_balance_moves_once(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '7.00', 'method_code' => 'CASH'],
            ['amount' => '3.00', 'method_code' => 'CARD'],
        ]);

        $bridge = $this->app->make(TreasuryReceiptBridge::class);
        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(2, $this->movementCount($event));
        $this->assertSame(2, Payment::query()->where('fiscal_event_id', $event->id)->count());

        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '10.000', 3));
    }

    // =================================================================
    // (d) deposit bridge resolves gl_account_id and records a movement
    // =================================================================

    public function test_deposit_bridge_resolves_gl_account_id_and_records_movement(): void
    {
        $event = $this->depositReceiptEvent('50.00');

        $this->app->make(TreasuryDepositBridge::class)->apply($event);

        $this->assertSame(1, Payment::query()->where('fiscal_event_id', $event->id)->count());

        $movements = DB::table('repository_movements')
            ->where('source_type', MovementSourceType::FiscalEvent->value)
            ->where('source_id', $event->id)
            ->get();
        $this->assertCount(1, $movements);
        $this->assertSame("fiscal_event:{$event->id}:payment:0", $movements->first()->idempotency_key);
        $this->assertSame(MovementDirection::In->value, $movements->first()->direction);

        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertSame(0, bccomp((string) $repo->balance, '50.000', 3));
    }

    // =================================================================
    // (e) freeze policy: device leg succeeds, server leg throws
    // =================================================================

    public function test_device_replay_leg_succeeds_on_frozen_repo(): void
    {
        $event = $this->projectedSaleReceipt([
            ['amount' => '10.00', 'method_code' => 'CASH'],
        ]);

        $this->app->make(TreasuryMovementServiceInterface::class)
            ->freeze($this->repositoryId, 'reconcile in progress');

        // A device-authored SALE_RECEIPT leg carries allowWhileFrozen=true —
        // an offline device must never poison the projection queue on a frozen
        // repo. It records + is flagged recorded_while_frozen.
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $movement = DB::table('repository_movements')
            ->where('source_id', $event->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertTrue((bool) $movement->recorded_while_frozen);
    }

    public function test_server_deposit_leg_throws_on_frozen_repo(): void
    {
        $event = $this->depositReceiptEvent('25.00');

        $this->app->make(TreasuryMovementServiceInterface::class)
            ->freeze($this->repositoryId, 'reconcile in progress');

        // A server-authored DEPOSIT_RECEIPT (isServerOnly) is NOT offline device
        // replay — allowWhileFrozen=false → the frozen repo rejects the leg and
        // the whole bridge transaction rolls back.
        $this->expectException(\Throwable::class);
        try {
            $this->app->make(TreasuryDepositBridge::class)->apply($event);
        } finally {
            $this->assertSame(0, $this->movementCount($event));
            $this->assertSame(0, Payment::query()->where('fiscal_event_id', $event->id)->count());
        }
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function movementCount(FiscalEvent $event): int
    {
        return DB::table('repository_movements')->where('source_id', $event->id)->count();
    }

    /**
     * Count the POS-payment GL entries the bridge posts for this event.
     * `createPOSPaymentEntry()` stamps `source_type='pos_receipt'` +
     * `source_id=$receipt->id` — one per Payment leg. `journal_entries`
     * carries no global (source_type, source_id) uniqueness, so a double-write
     * would show up here as an extra row.
     */
    private function posPaymentEntryCount(FiscalEvent $event): int
    {
        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();

        return DB::table('journal_entries')
            ->where('source_type', 'pos_receipt')
            ->where('source_id', $receipt->id)
            ->count();
    }

    /**
     * Reproduce EXACTLY what one Payment leg looks like after a prior write —
     * the same Payment row + POS-payment GL entry (+ optional movement) the
     * bridge's create branch produces. Used to stage the two replay scenarios:
     *   - per-leg partial (Fix 3): `idempotencyKey` = the canonical per-leg key,
     *     `withMovement=true` — the realistic "leg 0 landed, leg 1 absent".
     *   - legacy null-key (Fix 1): `idempotencyKey=null`, `withMovement=false` —
     *     the PRE-Task-20 bridge that predates the movement port.
     *
     * @param  numeric-string  $amount
     * @return array{0: string, 1: ?string} [paymentId, journalEntryId]
     */
    private function seedPriorLegWrite(
        FiscalEvent $event,
        int $index,
        string $amount,
        string $methodId,
        ?string $idempotencyKey,
        bool $withMovement,
    ): array {
        $receipt = Receipt::query()->where('fiscal_event_id', $event->id)->firstOrFail();
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
            'idempotency_key' => $idempotencyKey,
            'reference' => "POS Receipt {$receipt->receipt_number} - Payment ".($index + 1),
            'notes' => 'prior-write simulation',
        ]);

        // W4-9 — mirror the production entry shape (net revenue + output VAT
        // per sealed rate) so the replay assertions below still describe a real
        // prior write.
        $vatSplit = $this->app->make(PosReceiptVatAllocator::class)->allocate(
            $receipt,
            [$amount],
            CurrencyScale::for((string) $receipt->currency),
        )[0];
        $journalEntry = $this->app->make(GeneralLedgerService::class)->createPOSPaymentEntry(
            payment: $payment,
            receipt: $receipt,
            repository: $repository,
            vatSplit: $vatSplit,
        );
        $payment->journal_entry_id = $journalEntry->id;
        $payment->save();

        if ($withMovement) {
            DB::transaction(function () use ($event, $repository, $index, $amount, $journalEntry): void {
                $this->app->make(TreasuryMovementServiceInterface::class)->record(
                    new MovementIntent(
                        repositoryId: $repository->id,
                        tenantId: $event->tenant_id,
                        companyId: $event->company_id,
                        direction: MovementDirection::In,
                        amount: $amount,
                        currency: $repository->currency,
                        sourceType: MovementSourceType::FiscalEvent,
                        sourceId: $event->id,
                        idempotencyLeg: "payment:{$index}",
                        journalEntryId: $journalEntry->id,
                        occurredAt: null,
                        reasonCode: null,
                        reversesMovementId: null,
                        createdBy: null,
                        notes: null,
                        allowWhileFrozen: true,
                    ),
                );
            });
        }

        return [$payment->id, $payment->journal_entry_id];
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
     * @param  list<array<string, mixed>>  $paymentLines
     */
    private function projectedSaleReceipt(array $paymentLines): FiscalEvent
    {
        $event = $this->storeSaleReceiptFiscalEvent($paymentLines);
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);

        return $event;
    }

    /**
     * @param  list<array<string, mixed>>  $paymentLines
     */
    private function storeSaleReceiptFiscalEvent(array $paymentLines): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $previousHash = str_repeat('0', 64);

        $total = '0.00';
        $payments = [];
        foreach ($paymentLines as $pl) {
            /** @var numeric-string $amt */
            $amt = $pl['amount'] ?? '0.00';
            $total = bcadd($total, $amt, 2);
            $payments[] = [
                'amount' => $amt,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => $pl['instrument_serial'] ?? null,
                'instrument_type' => $pl['instrument_type'] ?? null,
                'method_code' => $pl['method_code'] ?? 'CASH',
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

        return $this->persistEvent(FiscalEventType::SALE_RECEIPT, $payload, $businessDate, $eventTime, $previousHash, 1);
    }

    private function depositReceiptEvent(string $amount): FiscalEvent
    {
        // Author a REAL, canonical DEPOSIT_RECEIPT via the server-side service so
        // the payload shape is exactly what CanonicalPayloadReader expects — the
        // bridge under test runs the real allocation + movement path.
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'name' => 'Deposit Customer',
            'customer_category' => CustomerCategory::Business,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);

        $actor = User::query()->whereKey($this->operatorId)->sole();

        return $this->app->make(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: $partner,
            actorUserId: $actor->id,
            actorName: (string) $actor->name,
            currencyCode: 'EUR',
            amount: $amount,
            methodCode: 'CASH',
            repositoryId: $this->repositoryId,
            notes: null,
        );
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
