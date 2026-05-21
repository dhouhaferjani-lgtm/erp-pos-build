<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentView;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionInvariantViolationException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\PosCustomerAlias;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\FiscalEventProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Treasury-operational bridge for device-authored ACCOUNT_PAYMENT events.
 *
 * Gated behind the Treasury module per SoT v3 D16: POS-core remains able to
 * project printable ACCOUNT_PAYMENT receipts without Treasury, while this
 * bridge owns the outbound Payment row plus server-side FIFO allocation.
 */
final class TreasuryAccountPaymentBridge implements FiscalEventProjector
{
    public function __construct(
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly PaymentAllocationService $allocationService,
    ) {}

    public function name(): string
    {
        return 'treasury_account_payment_bridge';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::ACCOUNT_PAYMENT;
    }

    public function requiresModule(): string
    {
        return 'Treasury';
    }

    public function priority(): int
    {
        return 150;
    }

    public function apply(FiscalEvent $event): void
    {
        $view = $this->canonicalReader->forAccountPayment($event);

        DB::transaction(function () use ($event, $view): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'SELECT pg_advisory_xact_lock(hashtext(?))',
                    [$event->id.':treasury_account_payment_bridge'],
                );
            }

            $partner = $this->resolveCustomer($event, $view);
            $paymentMethod = $this->resolvePaymentMethod($event, $view);
            $repository = $this->resolveRepository($event, $view);
            $actorUserId = $this->resolveActorUserId($event, $view);

            $existing = $this->existingPaymentForEvent($event);
            if ($existing instanceof Payment) {
                $this->assertExistingPaymentMatches(
                    event: $event,
                    existing: $existing,
                    partner: $partner,
                    paymentMethod: $paymentMethod,
                    repository: $repository,
                    view: $view,
                    actorUserId: $actorUserId,
                );

                return;
            }

            $payment = Payment::query()->create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $event->tenant_id,
                'company_id' => $event->company_id,
                'partner_id' => $partner->id,
                'payment_method_id' => $paymentMethod->id,
                'repository_id' => $repository->id,
                'amount' => $this->paymentAmount($event, $view),
                'currency' => $view->payload->currencyCode,
                'payment_date' => $view->payload->businessDate,
                'status' => PaymentStatus::Completed,
                'payment_type' => PaymentType::DocumentPayment,
                'origin' => PaymentOrigin::Pos,
                'fiscal_event_id' => $event->id,
                'created_by' => $actorUserId,
                'reference' => 'POS Account Payment '.$view->payload->accountPaymentUuid,
                'notes' => $this->paymentNotes($view, $actorUserId),
            ]);

            $this->allocationService->applyAllocationFromCommand(new ApplyPaymentAllocationCommand(
                tenantId: $event->tenant_id,
                companyId: $event->company_id,
                paymentId: $payment->id,
                allocationMethod: AllocationMethod::FIFO,
                actorUserId: $actorUserId,
                source: 'fiscal_event:ACCOUNT_PAYMENT',
                manualAllocations: null,
            ));
        });
    }

    private function resolveCustomer(FiscalEvent $event, AccountPaymentView $view): Partner
    {
        $customerId = $view->customer->customerId;

        if ($view->customer->customerSyncStatus === 'pending_create') {
            $alias = PosCustomerAlias::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('company_id', $event->company_id)
                ->where('client_customer_uuid', $customerId)
                ->first();

            if (! $alias instanceof PosCustomerAlias) {
                throw new ProjectionDependencyMissingException(
                    projectorName: $this->name(),
                    fiscalEventId: $event->id,
                    missingDependency: 'pos_customer_aliases row for client_customer_uuid='.$customerId,
                );
            }

            $customerId = $alias->server_partner_id;
        } elseif ($view->customer->customerSyncStatus !== 'synced') {
            throw $this->invariant($event, 'customer_sync_status_unsupported:'.$view->customer->customerSyncStatus);
        }

        $partner = Partner::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
            ->whereKey($customerId)
            ->first();

        if (! $partner instanceof Partner) {
            throw $this->invariant($event, 'customer_not_found:customer_id='.$customerId);
        }

        return $partner;
    }

    private function resolvePaymentMethod(FiscalEvent $event, AccountPaymentView $view): PaymentMethod
    {
        $paymentMethod = PaymentMethod::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->where('code', $view->payment->methodCode)
            ->where('is_active', true)
            ->first();

        if (! $paymentMethod instanceof PaymentMethod) {
            throw $this->invariant($event, 'payment_method_not_found:method_code='.$view->payment->methodCode);
        }

        return $paymentMethod;
    }

    private function resolveRepository(FiscalEvent $event, AccountPaymentView $view): PaymentRepository
    {
        if ($view->payment->repositoryId === null) {
            throw $this->invariant($event, 'payment_repository_required');
        }

        $repository = PaymentRepository::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->where('is_active', true)
            ->whereKey($view->payment->repositoryId)
            ->first();

        if (! $repository instanceof PaymentRepository) {
            throw $this->invariant($event, 'payment_repository_not_found:repository_id='.$view->payment->repositoryId);
        }

        if ($repository->account_id === null) {
            throw $this->invariant($event, 'payment_repository_missing_account_id:repository_id='.$repository->id);
        }

        return $repository;
    }

    private function resolveActorUserId(FiscalEvent $event, AccountPaymentView $view): ?string
    {
        $user = User::query()
            ->where('tenant_id', $event->tenant_id)
            ->whereKey($view->payload->cashierId)
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        $hasActiveCompanyMembership = UserCompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('company_id', $event->company_id)
            ->where('status', MembershipStatus::Active)
            ->exists();

        return $hasActiveCompanyMembership ? $user->id : null;
    }

    private function existingPaymentForEvent(FiscalEvent $event): ?Payment
    {
        $existing = Payment::query()
            ->where('fiscal_event_id', $event->id)
            ->where('origin', PaymentOrigin::Pos)
            ->get();

        if ($existing->count() > 1) {
            throw $this->invariant($event, 'idempotency_conflict:multiple_pos_payments_for_event');
        }

        $payment = $existing->first();

        return $payment instanceof Payment ? $payment : null;
    }

    private function assertExistingPaymentMatches(
        FiscalEvent $event,
        Payment $existing,
        Partner $partner,
        PaymentMethod $paymentMethod,
        PaymentRepository $repository,
        AccountPaymentView $view,
        ?string $actorUserId,
    ): void {
        $mismatches = [];

        $expected = [
            'tenant_id' => $event->tenant_id,
            'company_id' => $event->company_id,
            'partner_id' => $partner->id,
            'payment_method_id' => $paymentMethod->id,
            'repository_id' => $repository->id,
            'currency' => $view->payload->currencyCode,
            'payment_date' => $view->payload->businessDate,
            'status' => PaymentStatus::Completed->value,
            'created_by' => $actorUserId,
        ];

        foreach ($expected as $field => $value) {
            $actual = match ($field) {
                'payment_date' => $existing->payment_date->toDateString(),
                'status' => $existing->status->value,
                default => $existing->{$field},
            };

            if ($actual !== $value) {
                $mismatches[] = $field;
            }
        }

        if (bccomp($existing->amount, $this->paymentAmount($event, $view), $view->payload->currencyScale) !== 0) {
            $mismatches[] = 'amount';
        }

        if ($mismatches !== []) {
            throw $this->invariant($event, 'idempotency_conflict:'.implode(',', $mismatches));
        }
    }

    /**
     * @return numeric-string
     */
    private function paymentAmount(FiscalEvent $event, AccountPaymentView $view): string
    {
        if (! is_numeric($view->payment->amount)) {
            throw $this->invariant($event, 'payment_amount_not_numeric');
        }

        return $view->payment->amount;
    }

    private function paymentNotes(AccountPaymentView $view, ?string $actorUserId): string
    {
        $notes = 'ACCOUNT_PAYMENT fiscal bridge; allocation_policy='.$view->payload->treasuryAllocationPolicy;

        if ($actorUserId === null) {
            $notes .= '; unresolved_cashier_id='.$view->payload->cashierId;
        }

        return $notes;
    }

    private function invariant(FiscalEvent $event, string $reason): ProjectionInvariantViolationException
    {
        return new ProjectionInvariantViolationException(
            projectorName: $this->name(),
            fiscalEventId: $event->id,
            reason: $reason,
        );
    }
}
