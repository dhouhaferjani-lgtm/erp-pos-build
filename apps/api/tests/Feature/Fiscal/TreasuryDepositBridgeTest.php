<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Projections\Concerns\HandlesMaturityTenderLeg;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4 — `TreasuryDepositBridge` (Treasury-gated, priority 150).
 *
 * The outbound money-movement bridge for server-authored DEPOSIT_RECEIPT events:
 * it creates a back-office `Payment` and delegates to the SAME
 * `PaymentAllocationService` with `AllocationMethod::FIFO` that the device
 * `ACCOUNT_PAYMENT` path uses, so the money math is identical by construction
 * (spec §2.2). These tests cover the bridge's own responsibilities — Payment row
 * shape + allocation command shape + idempotency — with a recording allocation
 * service (mirroring TreasuryAccountPaymentBridgeTest). Real FIFO settle/overflow
 * + balance refresh are exercised end-to-end by the Phase 5 full-flow test.
 */
final class TreasuryDepositBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $actorId;

    private string $customerId;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $repository;

    private Account $cashAccount;

    private RecordingDepositAllocationService $allocationService;

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
        Location::factory()->create(['company_id' => $this->companyId]);

        $actor = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Owner On The Road']);
        $this->actorId = $actor->id;
        $this->grantCompanyMembership($actor->id, $this->companyId);

        $customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'name' => 'Mariam Ben Ali',
            'customer_category' => CustomerCategory::Business,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);
        $this->customerId = $customer->id;

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $this->cashAccount = Account::factory()->asset()->create([
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
            'account_id' => $this->cashAccount->id,
            'is_active' => true,
        ]);

        $this->allocationService = new RecordingDepositAllocationService;
    }

    public function test_bridge_creates_back_office_payment_with_fiscal_event_id(): void
    {
        $event = $this->authorDeposit('100');

        $this->bridge()->apply($event);

        $payment = Payment::query()->firstOrFail();
        $this->assertSame($this->tenantId, $payment->tenant_id);
        $this->assertSame($this->companyId, $payment->company_id);
        $this->assertSame($this->customerId, $payment->partner_id);
        $this->assertSame($this->paymentMethod->id, $payment->payment_method_id);
        $this->assertSame($this->repository->id, $payment->repository_id);
        $this->assertSame('100.000', $payment->amount);
        $this->assertSame('TND', $payment->currency);
        $this->assertSame($event->business_date->toDateString(), $payment->payment_date->toDateString());
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertSame(PaymentType::DocumentPayment, $payment->payment_type);
        $this->assertSame(PaymentOrigin::BackOffice, $payment->origin);
        $this->assertSame($event->id, $payment->fiscal_event_id);
        $this->assertSame($this->actorId, $payment->created_by);
    }

    public function test_bridge_invokes_fifo_allocation_command_with_deposit_source(): void
    {
        $event = $this->authorDeposit('100');

        $this->bridge()->apply($event);

        $command = $this->allocationService->singleCommand();
        $payment = Payment::query()->firstOrFail();

        $this->assertSame($this->tenantId, $command->tenantId);
        $this->assertSame($this->companyId, $command->companyId);
        $this->assertSame($payment->id, $command->paymentId);
        $this->assertSame(AllocationMethod::FIFO, $command->allocationMethod);
        $this->assertSame($this->actorId, $command->actorUserId);
        $this->assertSame('fiscal_event:DEPOSIT_RECEIPT', $command->source);
        $this->assertNull($command->manualAllocations);
    }

    public function test_bridge_is_idempotent_on_retry(): void
    {
        $event = $this->authorDeposit('100');
        $bridge = $this->bridge();

        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $this->allocationService->callCount());
    }

    public function test_bridge_fails_loud_on_conflicting_fiscal_event_id_payment(): void
    {
        $event = $this->authorDeposit('100');
        Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'partner_id' => $this->customerId,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '99.000',
            'currency' => 'TND',
            'payment_date' => $event->business_date->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::BackOffice,
            'fiscal_event_id' => $event->id,
            'created_by' => $this->actorId,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idempotency_conflict');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_when_payment_method_missing(): void
    {
        $event = $this->authorDeposit('100', methodCode: 'BANK_TRANSFER');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment_method_not_found');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_when_repository_missing(): void
    {
        $event = $this->authorDeposit('100', repositoryId: '11111111-1111-4111-8111-111111111111');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment_repository_not_found');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_when_actor_lacks_company_membership(): void
    {
        // A server-authored back-office deposit is recorded by an authenticated
        // company member. An unresolved / non-member actor must FAIL LOUD before
        // creating the Payment — a null actor would make the shared allocation
        // engine skip the CustomerAdvance GL posting + balance refresh on the
        // pure-advance path, recording money that never reaches the credit balance.
        $stranger = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'No Membership']);
        $event = $this->authorDeposit('100', actor: $stranger);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('actor_not_active_company_member');

        try {
            $this->bridge()->apply($event);
        } finally {
            $this->assertSame(0, Payment::query()->count());
        }
    }

    public function test_bridge_rolls_back_payment_when_allocation_throws(): void
    {
        $event = $this->authorDeposit('100');
        $this->allocationService->throwOnApply(new RuntimeException('allocation exploded'));

        try {
            $this->bridge()->apply($event);
            $this->fail('expected allocation failure to propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('allocation exploded', $e->getMessage());
        }

        // create + allocation share one transaction — a failing allocation must
        // roll back the just-created Payment.
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_bridge_contract_priority_and_provider_registration(): void
    {
        $bridge = $this->bridge();

        $this->assertSame('treasury_deposit_bridge', $bridge->name());
        $this->assertSame('Treasury', $bridge->requiresModule());
        $this->assertSame(150, $bridge->priority());
        $this->assertTrue($bridge->handlesEventType(FiscalEventType::DEPOSIT_RECEIPT));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::ACCOUNT_PAYMENT));

        // The provider must tag the bridge — without it the dispatcher would
        // never create the money-movement projection row.
        $taggedNames = array_map(
            static fn (object $p): string => $p->name(),
            iterator_to_array(app()->tagged(FiscalEventProjector::class)),
        );
        $this->assertContains('treasury_deposit_bridge', $taggedNames);
    }

    private function authorDeposit(string $amount, ?string $methodCode = 'CASH', ?string $repositoryId = null, ?User $actor = null): FiscalEvent
    {
        $actor ??= User::query()->whereKey($this->actorId)->sole();

        return app(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
            partner: Partner::query()->whereKey($this->customerId)->sole(),
            actorUserId: $actor->id,
            actorName: $actor->name,
            currencyCode: 'TND',
            amount: $amount,
            methodCode: $methodCode ?? 'CASH',
            repositoryId: $repositoryId ?? $this->repository->id,
            notes: null,
        );
    }

    private function bridge(): TreasuryDepositBridge
    {
        return new TreasuryDepositBridge(
            canonicalReader: new CanonicalPayloadReader,
            allocationService: $this->allocationService,
            movementService: $this->app->make(TreasuryMovementServiceInterface::class),
            // INHERITED-RED REPAIR — REVIVED, NOT NEW. This helper still passed
            // 3 arguments after `HandlesMaturityTenderLeg` became a 4th
            // constructor dependency of TreasuryDepositBridge, so every test in
            // this file that builds the bridge died with `ArgumentCountError`
            // and had been proving nothing. Same repair as
            // TreasuryAccountPaymentBridgeTest::bridge() (G-3 wave). Resolved
            // from the container rather than hand-built so the concern keeps
            // its own wiring. No production behaviour change.
            maturityLegHandler: $this->app->make(HandlesMaturityTenderLeg::class),
        );
    }

    private function grantCompanyMembership(string $userId, string $companyId): void
    {
        UserCompanyMembership::query()->create([
            'user_id' => $userId,
            'company_id' => $companyId,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);
    }
}

final class RecordingDepositAllocationService extends PaymentAllocationService
{
    /** @var list<ApplyPaymentAllocationCommand> */
    private array $commands = [];

    private ?RuntimeException $exception = null;

    public function __construct() {}

    public function throwOnApply(RuntimeException $exception): void
    {
        $this->exception = $exception;
    }

    /**
     * @return array{success: bool, allocations: array<int, array{document_id: string, amount: string}>, journal_entry_id: string|null, advance_journal_entry_id: string|null, excess_amount: string, total_allocated: string, fully_paid_documents: array<int, array{documentId: string, tenantId: string, companyId: string, documentNumber: string, documentType: string, partnerId: string, totalPaid: string, paidAt: string}>}
     */
    public function applyAllocationFromCommand(ApplyPaymentAllocationCommand $command): array
    {
        $this->commands[] = $command;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        // Task 24 Fix A contract: a cash-moving allocation ALWAYS links a Posted
        // journal entry to the payment. The bridge records the cash movement with
        // `payment->journal_entry_id` (FK-referenced by repository_movements) and
        // guards against a null JE, so this double must stamp one just like the
        // real service does.
        $journalEntryId = $this->stampPaymentJournalEntry($command);

        return [
            'success' => true,
            'allocations' => [],
            'journal_entry_id' => $journalEntryId,
            'advance_journal_entry_id' => $journalEntryId,
            'excess_amount' => '0.0000',
            'total_allocated' => '0.0000',
            'fully_paid_documents' => [],
        ];
    }

    private function stampPaymentJournalEntry(ApplyPaymentAllocationCommand $command): string
    {
        $entry = JournalEntry::query()->create([
            'tenant_id' => $command->tenantId,
            'company_id' => $command->companyId,
            'entry_number' => 'JE-FAKE-'.substr((string) Str::uuid(), 0, 8),
            'entry_date' => now(),
            'description' => 'Fake allocation entry',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'fake_allocation',
            'source_id' => $command->paymentId,
            'posted_at' => now(),
        ]);

        Payment::query()->whereKey($command->paymentId)->update(['journal_entry_id' => $entry->id]);

        return $entry->id;
    }

    public function singleCommand(): ApplyPaymentAllocationCommand
    {
        TestCase::assertCount(1, $this->commands);

        return $this->commands[0];
    }

    public function callCount(): int
    {
        return count($this->commands);
    }
}
