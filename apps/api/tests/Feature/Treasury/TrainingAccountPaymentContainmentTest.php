<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Projections\Concerns\HandlesMaturityTenderLeg;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LEDGER gate G-3, second surface — **a TRAINING account payment must not move
 * real money.**
 *
 * `TreasuryAccountPaymentBridge` had ZERO training discrimination. A trainee
 * rehearsing "customer settles their account" would otherwise get: a completed
 * `payments` row (origin=Pos), a real FIFO allocation against the customer's
 * GENUINE open invoices with its posted GL consequence, and a real
 * `repository_movements` drawer movement. Unlike a rehearsed sale this also
 * mutates PARTNER state — it marks real receivables as settled — so containment
 * matters more here, not less.
 *
 * ⚠️ **REACHABILITY — this is defense-in-depth, not a live-path fix.**
 * The device stamps `training_flag` on ACCOUNT_PAYMENT payloads
 * (`accountPaymentService.ts:261`), but `FiscalEventEngine` REFUSES TO SEAL the
 * event: `ACCOUNT_PAYMENT` is in `OPERATIONAL_CHAIN_EVENT_TYPES`
 * (`FiscalEventEngine.ts:220`), `accountPaymentService.ts:294` passes no
 * `chain_context`, the engine defaults it to `'operational'` (`:565`), and
 * `:815-818` throws `FiscalEventChainContextError` on `training_flag = true`
 * without a `training_*` context. That is the SAME refusal that blocks a
 * training SALE_RECEIPT — ACCOUNT_PAYMENT is not an exception to it.
 *
 * So no producer can author a training account payment today. This guard covers
 * legacy rows, replays, quarantine repairs, directly-inserted rows, and — the
 * real motivation — the moment training authoring is enabled, at which point
 * containment must already be in place rather than discovered afterwards.
 *
 * **Why this class lives under `tests/Feature/Treasury/` and not next to the
 * other bridge tests in `tests/Feature/Fiscal/`:** Fiscal is a deferred manifest
 * group and `TreasuryAccountPaymentBridgeTest` matches no `ci.yml` filter, so
 * pins placed there would run in NO CI job. `tests/Feature/Treasury/` is a
 * whole-directory PG lane (`ci.yml:1109`), so these arms actually execute on
 * every run. The bridge's other 17 tests stay in the original file.
 *
 * Rule 20: the bridge runs on a Horizon worker with NO `CompanyContext` bound;
 * nothing here binds one.
 */
final class TrainingAccountPaymentContainmentTest extends TestCase
{
    use RefreshDatabase;

    /** TND — every money assertion in this file is at the company currency scale. */
    private const SCALE = 3;

    private string $tenantId;

    private string $companyId;

    private string $cashierId;

    private string $customerId;

    private PaymentRepository $repository;

    private TrainingContainmentRecordingAllocationService $allocationService;

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

        $cashier = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Training Cashier']);
        $this->cashierId = $cashier->id;
        UserCompanyMembership::query()->create([
            'user_id' => $cashier->id,
            'company_id' => $this->companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);

        $customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'name' => 'Mariam Ben Ali',
        ]);
        $this->customerId = $customer->id;

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $cashAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => '101',
            'name' => 'POS Cash',
            'is_active' => true,
        ]);

        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'DRAWER-1',
            'name' => 'Drawer 1',
            'account_id' => $cashAccount->id,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'TND',
            'balance' => '0.000',
            'is_active' => true,
        ]);

        $this->allocationService = new TrainingContainmentRecordingAllocationService;
    }

    // =================================================================
    // The gate
    // =================================================================

    public function test_training_account_payment_writes_no_payment_no_allocation_and_no_movement(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent(['training_flag' => true]);

        $this->bridge()->apply($event);

        $this->assertNoTreasuryArtefacts();
    }

    /**
     * Containment must survive redelivery: a replayed training event still
     * writes nothing, and (unlike a throw) leaves the projection free to be
     * marked `applied` rather than retried forever.
     */
    public function test_training_account_payment_stays_contained_on_replay(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent(['training_flag' => true]);
        $bridge = $this->bridge();

        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertNoTreasuryArtefacts();
    }

    // =================================================================
    // Control — the identical NON-training payment still moves money
    // =================================================================

    public function test_control_non_training_account_payment_writes_payment_allocation_and_movement(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent(['training_flag' => false]);

        $this->bridge()->apply($event);

        $this->assertSame(
            1,
            Payment::query()->count(),
            'The control fixture must actually write a payment — otherwise the gate arms prove nothing.',
        );
        $this->assertSame(1, $this->allocationService->callCount());
        $this->assertSame(
            1,
            DB::table('repository_movements')->where('payment_repository_id', $this->repository->id)->count(),
        );
        // String comparison — never a float on money.
        $this->assertSame(0, bccomp($this->drawerBalance(), '100.000', self::SCALE));
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function assertNoTreasuryArtefacts(): void
    {
        $this->assertSame(
            0,
            Payment::query()->count(),
            'A training account payment must not write a Treasury payment.',
        );
        $this->assertSame(
            0,
            $this->allocationService->callCount(),
            'A training account payment must not allocate against real open invoices.',
        );
        $this->assertSame(
            0,
            DB::table('repository_movements')->where('payment_repository_id', $this->repository->id)->count(),
            'A training account payment must not move the drawer balance.',
        );
        $this->assertSame(
            0,
            JournalEntry::query()->count(),
            'A training account payment must not post to the general ledger.',
        );
        // The operator-visible half of the containment.
        $this->assertSame(0, bccomp($this->drawerBalance(), '0.000', self::SCALE));
    }

    /**
     * @return numeric-string
     */
    private function drawerBalance(): string
    {
        return PaymentRepository::query()->whereKey($this->repository->id)->firstOrFail()->balance;
    }

    private function bridge(): TreasuryAccountPaymentBridge
    {
        return new TreasuryAccountPaymentBridge(
            canonicalReader: new CanonicalPayloadReader,
            allocationService: $this->allocationService,
            movementService: $this->app->make(TreasuryMovementServiceInterface::class),
            maturityLegHandler: $this->app->make(HandlesMaturityTenderLeg::class),
        );
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    private function storeAccountPaymentFiscalEvent(array $payloadOverrides): FiscalEvent
    {
        $payload = $this->accountPaymentPayload($payloadOverrides);

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->cashierId,
            'event_type' => FiscalEventType::ACCOUNT_PAYMENT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 7,
            'event_time_device' => '2026-05-21 10:15:30',
            'business_date' => '2026-05-21',
            'server_received_at' => '2026-05-21 10:15:31',
            'source_event_class' => 'account_payments',
            'source_event_id' => $payload['account_payment_uuid'],
            'canonical_bytes' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * The ONLY thing that varies between the gate arms and the control arm is
     * `training_flag`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountPaymentPayload(array $overrides): array
    {
        return array_replace([
            'account_payment_uuid' => '44444444-4444-4444-8444-444444444444',
            'business_date' => '2026-05-21',
            'cashier_id' => $this->cashierId,
            'cashier_name' => 'Training Cashier',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => $this->customerId,
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'payment_amount' => '100.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '200.000',
                'projected_receivable_balance_after' => '200.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => '100.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
                'repository_id' => $this->repository->id,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => '2026-05-21T10:10:00.000Z',
                'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ], $overrides);
    }
}

/**
 * Records allocation invocations so the gate arms can assert the bridge never
 * touched the customer's real open invoices. Stamps a journal entry on the
 * payment exactly like the real service does, because the bridge refuses to
 * record a cash movement against a null `journal_entry_id`.
 *
 * Deliberately a local class rather than a shared one: the sibling double in
 * `TreasuryAccountPaymentBridgeTest` is declared inside that test file, so it is
 * not PSR-4 autoloadable from here.
 */
final class TrainingContainmentRecordingAllocationService extends PaymentAllocationService
{
    /** @var list<ApplyPaymentAllocationCommand> */
    private array $commands = [];

    public function __construct() {}

    /**
     * @return array{success: bool, allocations: array<int, array{document_id: string, amount: string}>, journal_entry_id: string|null, advance_journal_entry_id: string|null, excess_amount: string, total_allocated: string, fully_paid_documents: array<int, array{documentId: string, tenantId: string, companyId: string, documentNumber: string, documentType: string, partnerId: string, totalPaid: string, paidAt: string}>}
     */
    public function applyAllocationFromCommand(ApplyPaymentAllocationCommand $command): array
    {
        $this->commands[] = $command;

        $entry = JournalEntry::query()->create([
            'tenant_id' => $command->tenantId,
            'company_id' => $command->companyId,
            'entry_number' => 'JE-TRAINGATE-'.substr((string) Str::uuid(), 0, 8),
            'entry_date' => now(),
            'description' => 'Training-gate allocation entry',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'fake_allocation',
            'source_id' => $command->paymentId,
            'posted_at' => now(),
        ]);

        Payment::query()->whereKey($command->paymentId)->update(['journal_entry_id' => $entry->id]);

        return [
            'success' => true,
            'allocations' => [],
            'journal_entry_id' => $entry->id,
            'advance_journal_entry_id' => $entry->id,
            'excess_amount' => '0.0000',
            'total_allocated' => '0.0000',
            'fully_paid_documents' => [],
        ];
    }

    public function callCount(): int
    {
        return count($this->commands);
    }
}
