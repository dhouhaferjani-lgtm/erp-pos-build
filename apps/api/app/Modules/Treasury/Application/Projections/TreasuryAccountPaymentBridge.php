<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionDependencyMissingException;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionInvariantViolationException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\PosCustomerAlias;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\DTOs\MaturityLegContext;
use App\Modules\Treasury\Application\DTOs\MaturityLegResult;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\Projections\Concerns\HandlesMaturityTenderLeg;
use App\Modules\Treasury\Application\Projections\Concerns\ResolvesTerminalLocation;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
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
    use ResolvesTerminalLocation;

    public function __construct(
        private readonly CanonicalPayloadReader $canonicalReader,
        private readonly PaymentAllocationService $allocationService,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly HandlesMaturityTenderLeg $maturityLegHandler,
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

        // ============================================================
        // LEDGER gate G-3 — TRAINING events never move real money.
        // ============================================================
        // A trainee rehearsing "customer settles their account" would otherwise
        // get real money out of this bridge: a `payments` row (status=Completed,
        // origin=Pos), a real FIFO allocation against the customer's OPEN
        // INVOICES with its posted GL consequence, and a real
        // `repository_movements` drawer movement. Unlike a rehearsed sale this
        // also mutates PARTNER state — it would mark genuine receivables as
        // settled — so containment matters more here, not less.
        //
        // DEFENSE-IN-DEPTH, not a live-path fix. The device stamps
        // `training_flag` on ACCOUNT_PAYMENT payloads
        // (`accountPaymentService.ts:261`) but `FiscalEventEngine` REFUSES TO
        // SEAL the event: ACCOUNT_PAYMENT is in `OPERATIONAL_CHAIN_EVENT_TYPES`
        // (`FiscalEventEngine.ts:220`), `accountPaymentService.ts:294` passes no
        // `chain_context`, the engine defaults it to `'operational'` (`:565`),
        // and `:815-818` throws on `training_flag = true` outside a `training_*`
        // context. That is the SAME refusal that blocks a training SALE_RECEIPT
        // — ACCOUNT_PAYMENT is NOT an exception to it. So no producer can author
        // one today; this covers legacy rows, replays, quarantine repairs, and
        // the moment training authoring is enabled.
        //
        // Keyed on the SEALED payload flag, same shape as the sibling gate in
        // TreasuryReceiptBridge. Returning cleanly (not throwing) keeps
        // ApplyFiscalEventProjectionJob's `applied` path intact so a rehearsal
        // never parks a permanently-retrying projection row.
        if ($view->payload->trainingFlag === true) {
            return;
        }

        $terminalLocationId = $this->resolveTerminalLocationId($event);

        DB::transaction(function () use ($event, $view, $terminalLocationId): void {
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
            $isMaturityLeg = $this->maturityLegHandler->handles($paymentMethod);
            $cashAccountOverrideId = null;
            $instrument = null;
            $shouldRecordMovement = true;

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

                $payment = $existing;
                if ($isMaturityLeg) {
                    $linkedJournalEntryId = $existing->journal_entry_id;
                    if ($linkedJournalEntryId === null) {
                        throw $this->invariant($event, 'maturity_payment_missing_journal_entry');
                    }
                    $debitAccountId = DB::table('journal_lines')
                        ->where('journal_entry_id', $linkedJournalEntryId)
                        ->where('line_order', 0)
                        ->value('account_id');
                    if ($debitAccountId === $repository->gl_account_id) {
                        $isMaturityLeg = false;
                    } elseif ($debitAccountId === $this->maturityLegHandler->portfolioAccountId($paymentMethod, $event->company_id)) {
                        $result = $this->handleMaturityLeg($event, $view, $paymentMethod, $repository, $partner, $actorUserId, $terminalLocationId);
                        if ($existing->instrument_id !== null && $existing->instrument_id !== $result->instrument->id) {
                            throw $this->invariant($event, 'maturity_payment_instrument_conflict');
                        }
                        if ($existing->instrument_id === null) {
                            $existing->instrument_id = $result->instrument->id;
                            $existing->save();
                        }
                        if ($result->instrument->payment_id !== null && $result->instrument->payment_id !== $existing->id) {
                            throw $this->invariant($event, 'maturity_instrument_payment_conflict');
                        }
                        if ($result->instrument->payment_id === null) {
                            $result->instrument->payment_id = $existing->id;
                            $result->instrument->save();
                        }
                        if (DB::table('repository_movements')
                            ->where('idempotency_key', sprintf('fiscal_event:%s:payment:0', $event->id))
                            ->exists()) {
                            throw $this->invariant($event, 'portfolio_maturity_leg_has_cash_movement');
                        }
                        $shouldRecordMovement = false;
                    } else {
                        throw $this->invariant($event, 'maturity_payment_unrecognized_debit_account');
                    }
                }
            } else {
                if ($isMaturityLeg) {
                    $result = $this->handleMaturityLeg($event, $view, $paymentMethod, $repository, $partner, $actorUserId, $terminalLocationId);
                    $instrument = $result->instrument;
                    $cashAccountOverrideId = $result->portfolioAccountId;
                    $shouldRecordMovement = false;
                }

                $payment = Payment::query()->create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $event->tenant_id,
                    'company_id' => $event->company_id,
                    'partner_id' => $partner->id,
                    'payment_method_id' => $paymentMethod->id,
                    'instrument_id' => $instrument?->id,
                    'repository_id' => $repository->id,
                    // Device-authored account payments are attributed only to
                    // their terminal; the repository fallback is reserved for
                    // server-authored deposits.
                    'location_id' => $terminalLocationId,
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
                    cashAccountOverrideId: $cashAccountOverrideId,
                ));

                if ($instrument !== null) {
                    $instrument->payment_id = $payment->id;
                    $instrument->save();
                }
            }

            if (! $shouldRecordMovement) {
                return;
            }

            // Task 20 — move the repository balance through the single write
            // port. ONE movement per account payment, keyed on the canonical
            // `payment:0` leg. Recorded on BOTH the create AND the
            // idempotent-existing path (complete-set replay). record() is
            // idempotent on the leg key.
            //
            // Task 24 Fix A guard (belt-and-suspenders, mirrors
            // PaymentRefundService): a FiscalEvent cash movement must carry a
            // journal_entry_id or the daily treasury:reconcile freezes the drawer.
            // The allocation service now posts + links a JE for every cash-moving
            // account payment — including the null-actor (unresolved cashier) path,
            // where it seals the advance/AR consequence as a SYSTEM-generated entry —
            // so this is UNREACHABLE for a GL-linked repository. It fails LOUD only
            // for a mis-configured repository (no gl_account_id → no GL to post),
            // refusing to record a null-JE movement rather than silently poisoning
            // the reconcile.
            $linkedJournalEntryId = $payment->fresh()?->journal_entry_id;
            if ($linkedJournalEntryId === null) {
                throw new \DomainException(
                    'ACCOUNT_PAYMENT cash movement requires a linked journal entry; payment '.
                        $payment->id.' for fiscal event '.$event->id.' resolved a null journal_entry_id '.
                        '(repository '.$repository->id.'). Refusing to record a null-JE movement the reconcile would freeze on.',
                );
            }

            // allowWhileFrozen is TRUE: ACCOUNT_PAYMENT is a DEVICE-authored
            // event (NOT `isServerOnly()`), so a leg replayed after a
            // server-side freeze must still record (offline device replay must
            // never poison the projection queue — HIGH-4). Decided from the
            // event type, never inferred from the movement sourceType.
            $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $event->tenant_id,
                companyId: $event->company_id,
                direction: MovementDirection::In,
                amount: $this->paymentAmount($event, $view),
                // Task 20 review Fix 2 (MINOR) — pass the TENDER currency (the
                // currency the amount is denominated in, also stamped on the
                // Payment row above), NOT the repository currency. Passing the
                // repo currency makes the port's CurrencyMismatchException guard
                // (`$repo->currency !== $intent->currency`) trivially always-
                // equal and silently disarms it. Currencies match today so
                // behavior is unchanged; the guard is restored.
                currency: $view->payload->currencyCode,
                sourceType: MovementSourceType::FiscalEvent,
                sourceId: $event->id,
                idempotencyLeg: 'payment:0',
                journalEntryId: $linkedJournalEntryId,
                occurredAt: null,
                reasonCode: null,
                reversesMovementId: null,
                createdBy: $actorUserId,
                notes: null,
                allowWhileFrozen: ! $event->event_type->isServerOnly(),
                allowBehindCheckpoint: ! $event->event_type->isServerOnly(),
                // W-5b Option B intent-flag sweep: this bridge runs inside
                // ApplyFiscalEventProjectionJob (ShouldQueue), mirroring the
                // allowWhileFrozen precedent site-for-site. Direction is
                // always In here so the negative-balance guard never
                // actually trips on this leg — set for consistency/defense
                // in depth, not because this specific call can go negative.
                allowNegative: ! $event->event_type->isServerOnly(),
            ));
        });
    }

    private function handleMaturityLeg(
        FiscalEvent $event,
        AccountPaymentView $view,
        PaymentMethod $method,
        PaymentRepository $repository,
        Partner $partner,
        ?string $actorUserId,
        ?string $locationId,
    ): MaturityLegResult {
        return $this->maturityLegHandler->handleMaturityLeg(
            $event,
            new PaymentDTO(
                amount: $this->paymentAmount($event, $view),
                foreignCurrencyAmount: $view->payment->foreignCurrencyAmount,
                foreignCurrencyCode: $view->payment->foreignCurrencyCode,
                instrumentSerial: $view->payment->instrumentSerial,
                instrumentType: $view->payment->instrumentType,
                methodCode: $view->payment->methodCode,
            ),
            0,
            $method,
            new MaturityLegContext(
                currency: $view->payload->currencyCode,
                repositoryId: $repository->id,
                partnerId: $partner->id,
                receivedDate: $view->payload->businessDate,
                createdBy: $actorUserId,
                locationId: $locationId,
            ),
        );
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
                $foreignCompanyAlias = PosCustomerAlias::query()
                    ->where('tenant_id', $event->tenant_id)
                    ->where('client_customer_uuid', $customerId)
                    ->first();

                if ($foreignCompanyAlias instanceof PosCustomerAlias) {
                    throw $this->invariant(
                        $event,
                        'customer_alias_cross_company:client_customer_uuid='.
                            $customerId.
                            ':alias_company_id='.
                            $foreignCompanyAlias->company_id,
                    );
                }

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

        $accountId = $repository->account_id ?? $repository->gl_account_id;
        if ($accountId === null) {
            throw $this->invariant($event, 'payment_repository_missing_account_id:repository_id='.$repository->id);
        }

        $accountExists = Account::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->where('is_active', true)
            ->whereKey($accountId)
            ->exists();

        if (! $accountExists) {
            throw $this->invariant(
                $event,
                'payment_repository_account_not_found:repository_id='.
                    $repository->id.
                    ':account_id='.
                    $accountId,
            );
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
            'origin' => PaymentOrigin::Pos->value,
            'created_by' => $actorUserId,
        ];

        foreach ($expected as $field => $value) {
            $actual = match ($field) {
                'payment_date' => $existing->payment_date->toDateString(),
                'status' => $existing->status->value,
                'origin' => $existing->origin?->value,
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
