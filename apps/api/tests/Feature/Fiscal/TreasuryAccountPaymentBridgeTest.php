<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\PosCustomerAlias;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TreasuryAccountPaymentBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $customerId;

    private string $cashierId;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $repository;

    private Account $cashAccount;

    private RecordingPaymentAllocationService $allocationService;

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

        $cashier = User::factory()->create(['tenant_id' => $this->tenantId, 'name' => 'Default Cashier']);
        $this->cashierId = $cashier->id;
        $this->grantCompanyMembership($cashier->id, $this->companyId);

        $customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'name' => 'Mariam Ben Ali',
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

        $this->allocationService = new RecordingPaymentAllocationService;
    }

    public function test_treasury_bridge_creates_pos_payment_with_fiscal_event_id(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();

        $this->bridge()->apply($event);

        $payment = Payment::query()->firstOrFail();
        $this->assertSame($this->tenantId, $payment->tenant_id);
        $this->assertSame($this->companyId, $payment->company_id);
        $this->assertSame($this->customerId, $payment->partner_id);
        $this->assertSame($this->paymentMethod->id, $payment->payment_method_id);
        $this->assertSame($this->repository->id, $payment->repository_id);
        $this->assertSame('100.000', $payment->amount);
        $this->assertSame('TND', $payment->currency);
        $this->assertSame('2026-05-21', $payment->payment_date->toDateString());
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertSame(PaymentType::DocumentPayment, $payment->payment_type);
        $this->assertSame(PaymentOrigin::Pos, $payment->origin);
        $this->assertSame($event->id, $payment->fiscal_event_id);
        $this->assertSame($this->cashierId, $payment->created_by);
    }

    public function test_bridge_invokes_fifo_allocation_command(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();

        $this->bridge()->apply($event);

        $command = $this->allocationService->singleCommand();
        $payment = Payment::query()->firstOrFail();

        $this->assertSame($this->tenantId, $command->tenantId);
        $this->assertSame($this->companyId, $command->companyId);
        $this->assertSame($payment->id, $command->paymentId);
        $this->assertSame(AllocationMethod::FIFO, $command->allocationMethod);
        $this->assertSame($this->cashierId, $command->actorUserId);
        $this->assertSame('fiscal_event:ACCOUNT_PAYMENT', $command->source);
        $this->assertNull($command->manualAllocations);
    }

    public function test_bridge_is_idempotent_on_retry(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();
        $bridge = $this->bridge();

        $bridge->apply($event);
        Payment::query()->firstOrFail()->forceFill([
            'payment_type' => PaymentType::Advance,
        ])->save();
        $bridge->apply($event);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, $this->allocationService->callCount());
    }

    public function test_bridge_fails_loud_on_conflicting_fiscal_event_id_payment(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();
        Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'partner_id' => $this->customerId,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '99.000',
            'currency' => 'TND',
            'payment_date' => '2026-05-21',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::Pos,
            'fiscal_event_id' => $event->id,
            'created_by' => $this->cashierId,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idempotency_conflict');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_when_existing_fiscal_event_payment_has_non_pos_origin(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent();
        Payment::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'partner_id' => $this->customerId,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => '100.000',
            'currency' => 'TND',
            'payment_date' => '2026-05-21',
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'origin' => PaymentOrigin::WebAdmin,
            'fiscal_event_id' => $event->id,
            'created_by' => $this->cashierId,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idempotency_conflict');

        try {
            $this->bridge()->apply($event);
        } finally {
            $this->assertSame(1, Payment::query()->count());
            $this->assertSame(0, $this->allocationService->callCount());
        }
    }

    public function test_bridge_resolves_pending_customer_alias_before_payment_creation(): void
    {
        $clientCustomerUuid = '77777777-7777-4777-8777-777777777777';
        PosCustomerAlias::query()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'client_customer_uuid' => $clientCustomerUuid,
            'server_partner_id' => $this->customerId,
        ]);
        $event = $this->storeAccountPaymentFiscalEvent($this->accountPaymentPayload([
            'customer' => [
                'customer_id' => $clientCustomerUuid,
                'customer_sync_status' => 'pending_create',
            ],
        ]));

        $this->bridge()->apply($event);

        $this->assertSame($this->customerId, Payment::query()->firstOrFail()->partner_id);
    }

    public function test_bridge_dead_letters_when_pending_customer_has_no_alias(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent($this->accountPaymentPayload([
            'customer' => [
                'customer_id' => '77777777-7777-4777-8777-777777777777',
                'customer_sync_status' => 'pending_create',
            ],
        ]));

        try {
            $this->bridge()->apply($event);
            $this->fail('Missing pending-customer alias must throw a retryable projection dependency exception.');
        } catch (ProjectionDependencyMissingException $exception) {
            $this->assertStringContainsString('pos_customer_aliases row', $exception->getMessage());
        }

        $row = FiscalEventProjectionRow::query()->create([
            'id' => Str::uuid()->toString(),
            'fiscal_event_id' => $event->id,
            'projector_name' => 'treasury_account_payment_bridge',
        ]);

        $this->app->tag([TreasuryAccountPaymentBridge::class], FiscalEventProjector::class);
        $this->app->instance(PaymentAllocationService::class, $this->allocationService);

        $job = new ApplyFiscalEventProjectionJob($row->id);
        $job->failed(new ProjectionDependencyMissingException(
            projectorName: 'treasury_account_payment_bridge',
            fiscalEventId: $event->id,
            missingDependency: 'pos_customer_aliases row',
        ));

        $this->assertSame('dead_lettered', $row->refresh()->projection_status->value);
        $this->assertStringContainsString('pos_customer_aliases row', (string) $row->last_error);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_bridge_rejects_pending_customer_alias_from_another_company(): void
    {
        $clientCustomerUuid = '77777777-7777-4777-8777-777777777777';
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $foreignCustomer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
        ]);
        PosCustomerAlias::query()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
            'client_customer_uuid' => $clientCustomerUuid,
            'server_partner_id' => $foreignCustomer->id,
        ]);
        $event = $this->storeAccountPaymentFiscalEvent($this->accountPaymentPayload([
            'customer' => [
                'customer_id' => $clientCustomerUuid,
                'customer_sync_status' => 'pending_create',
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('customer_alias_cross_company');

        $this->bridge()->apply($event);
    }

    public function test_bridge_rejects_cross_company_partner(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $foreignCustomer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
        ]);
        $event = $this->storeAccountPaymentFiscalEvent($this->accountPaymentPayload([
            'customer' => ['customer_id' => $foreignCustomer->id],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('customer_not_found');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_when_payment_method_missing(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent($this->accountPaymentPayload([
            'payment' => ['method_code' => 'CARD'],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment_method_not_found');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_when_repository_missing(): void
    {
        $event = $this->storeAccountPaymentFiscalEvent($this->accountPaymentPayload([
            'payment' => ['repository_id' => Str::uuid()->toString()],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment_repository_not_found');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_when_repository_account_is_cross_company(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $foreignAccount = Account::factory()->asset()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
            'code' => '102',
            'name' => 'Foreign Cash',
            'is_active' => true,
        ]);
        $this->repository->forceFill(['account_id' => $foreignAccount->id])->save();
        $event = $this->storeAccountPaymentFiscalEvent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('payment_repository_account_not_found');

        try {
            $this->bridge()->apply($event);
        } finally {
            $this->assertSame(0, Payment::query()->count());
            $this->assertSame(0, $this->allocationService->callCount());
        }
    }

    public function test_bridge_fails_loud_when_allocation_throws(): void
    {
        $this->allocationService->throwOnApply(new RuntimeException('allocation exploded'));
        $event = $this->storeAccountPaymentFiscalEvent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('allocation exploded');

        try {
            $this->bridge()->apply($event);
        } finally {
            $this->assertSame(0, Payment::query()->count());
        }
    }

    public function test_bridge_sets_created_by_null_when_operator_lacks_company_membership(): void
    {
        UserCompanyMembership::query()->where('user_id', $this->cashierId)->delete();
        $event = $this->storeAccountPaymentFiscalEvent();

        $this->bridge()->apply($event);

        $payment = Payment::query()->firstOrFail();
        $this->assertNull($payment->created_by);
        $this->assertNull($this->allocationService->singleCommand()->actorUserId);
        $this->assertStringContainsString('unresolved_cashier_id='.$this->cashierId, (string) $payment->notes);
    }

    public function test_bridge_contract_and_provider_registration(): void
    {
        $bridge = $this->bridge();

        $this->assertSame('treasury_account_payment_bridge', $bridge->name());
        $this->assertTrue($bridge->handlesEventType(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::SALE_RECEIPT));
        $this->assertSame('Treasury', $bridge->requiresModule());
        $this->assertSame(150, $bridge->priority());

        $names = array_map(
            static fn (FiscalEventProjector $projector): string => $projector->name(),
            iterator_to_array($this->app->tagged(FiscalEventProjector::class), false),
        );

        $this->assertContains('treasury_account_payment_bridge', $names);
    }

    private function bridge(): TreasuryAccountPaymentBridge
    {
        return new TreasuryAccountPaymentBridge(
            canonicalReader: new CanonicalPayloadReader,
            allocationService: $this->allocationService,
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

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function storeAccountPaymentFiscalEvent(?array $payload = null): FiscalEvent
    {
        $payload ??= $this->accountPaymentPayload();
        $eventId = Str::uuid()->toString();

        return FiscalEvent::query()->create([
            'id' => $eventId,
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
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-21 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'account_payments',
            'source_event_id' => $payload['account_payment_uuid'] ?? null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $this->canonicalEncode($payload),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountPaymentPayload(array $overrides = []): array
    {
        $payload = [
            'account_payment_uuid' => '44444444-4444-4444-8444-444444444444',
            'business_date' => '2026-05-21',
            'cashier_id' => $this->cashierId,
            'cashier_name' => 'Default Cashier',
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
                'tax_number' => '1234567A/A/A/000',
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
        ];

        return $this->mergeRecursiveDistinct($payload, $overrides);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                /** @var array<string, mixed> $baseValue */
                $baseValue = $base[$key];
                /** @var array<string, mixed> $overrideValue */
                $overrideValue = $value;
                $base[$key] = $this->mergeRecursiveDistinct($baseValue, $overrideValue);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        return json_encode($this->sortRecursive($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->sortRecursive($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }
}

final class RecordingPaymentAllocationService extends PaymentAllocationService
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

        return [
            'success' => true,
            'allocations' => [],
            'journal_entry_id' => null,
            'advance_journal_entry_id' => null,
            'excess_amount' => '0.0000',
            'total_allocated' => '0.0000',
            'fully_paid_documents' => [],
        ];
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
