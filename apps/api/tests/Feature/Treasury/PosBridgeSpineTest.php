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
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
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

        // Simulate a PARTIAL prior write: only leg 0's movement exists.
        // Record it directly through the port with the canonical leg key so
        // the replay path sees it as an idempotent hit.
        $this->recordLegDirectly($event, index: 0, amount: '7.00');
        $this->assertSame(1, $this->movementCount($event));

        // Replay the bridge — it must write the MISSING leg 1 and treat leg 0
        // as an idempotent hit (never skip the whole event).
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $this->assertSame(2, $this->movementCount($event));
        $keys = DB::table('repository_movements')
            ->where('source_id', $event->id)
            ->pluck('idempotency_key')
            ->all();
        $this->assertContains("fiscal_event:{$event->id}:payment:0", $keys);
        $this->assertContains("fiscal_event:{$event->id}:payment:1", $keys);
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

    private function recordLegDirectly(FiscalEvent $event, int $index, string $amount): void
    {
        $repo = PaymentRepository::query()->findOrFail($this->repositoryId);
        DB::transaction(function () use ($event, $index, $amount, $repo): void {
            $this->app->make(TreasuryMovementServiceInterface::class)->record(
                new MovementIntent(
                    repositoryId: $repo->id,
                    tenantId: $event->tenant_id,
                    companyId: $event->company_id,
                    direction: MovementDirection::In,
                    amount: $amount,
                    currency: $repo->currency,
                    sourceType: MovementSourceType::FiscalEvent,
                    sourceId: $event->id,
                    idempotencyLeg: "payment:{$index}",
                    journalEntryId: null,
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
