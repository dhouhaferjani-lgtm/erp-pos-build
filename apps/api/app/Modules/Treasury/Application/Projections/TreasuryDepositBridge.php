<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Projections;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\DTOs\Canonical\DepositReceiptView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Exceptions\ProjectionInvariantViolationException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
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
 * Treasury-operational bridge for server-authored DEPOSIT_RECEIPT events — the
 * back-office counterpart of {@see TreasuryAccountPaymentBridge}.
 *
 * Gated behind the Treasury module per SoT v3 D16: POS-core remains able to
 * project the printable deposit receipt without Treasury, while this bridge owns
 * the outbound `Payment` row plus server-side FIFO allocation.
 *
 * **Anti-divergence guarantee (spec §2.2).** This bridge calls the SAME
 * `PaymentAllocationService::applyAllocationFromCommand()` with the SAME
 * `AllocationMethod::FIFO` that the device `ACCOUNT_PAYMENT` path uses. The money
 * math — settle open invoices oldest-first, overflow to `CustomerAdvance`, and
 * the partner-balance refresh that the allocation's GL postings trigger — is
 * therefore identical by construction; only the ingress envelope (server-authored
 * vs device) and the `Payment.origin` differ.
 *
 * Unlike the device bridge there is no `pos_customer_aliases` indirection: the
 * server-authored payload's `customer.customer_id` already equals the server
 * `partner_id` (an invariant enforced by the DEPOSIT_RECEIPT payload validator),
 * and the actor is a resolved server user, not a device cashier.
 *
 * Idempotent on `payments.fiscal_event_id` — a re-delivery whose existing payment
 * matches no-ops; a conflicting existing payment fails loud.
 */
final class TreasuryDepositBridge implements FiscalEventProjector
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
        return 'treasury_deposit_bridge';
    }

    public function handlesEventType(FiscalEventType $type): bool
    {
        return $type === FiscalEventType::DEPOSIT_RECEIPT;
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
        $view = $this->canonicalReader->forDepositReceipt($event);

        $terminalLocationId = $this->resolveTerminalLocationId($event);

        DB::transaction(function () use ($event, $view, $terminalLocationId): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'SELECT pg_advisory_xact_lock(hashtext(?))',
                    [$event->id.':treasury_deposit_bridge'],
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
                    'location_id' => $terminalLocationId ?? $repository->location_id,
                    'amount' => $this->paymentAmount($event, $view),
                    'currency' => $view->payload->currencyCode,
                    'payment_date' => $view->payload->businessDate,
                    'status' => PaymentStatus::Completed,
                    'payment_type' => PaymentType::DocumentPayment,
                    'origin' => PaymentOrigin::BackOffice,
                    'fiscal_event_id' => $event->id,
                    'created_by' => $actorUserId,
                    'reference' => 'Back-Office Deposit '.$view->payload->depositReceiptUuid,
                    'notes' => $this->paymentNotes($view),
                ]);

                $this->allocationService->applyAllocationFromCommand(new ApplyPaymentAllocationCommand(
                    tenantId: $event->tenant_id,
                    companyId: $event->company_id,
                    paymentId: $payment->id,
                    allocationMethod: AllocationMethod::FIFO,
                    actorUserId: $actorUserId,
                    source: 'fiscal_event:DEPOSIT_RECEIPT',
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
            // port. ONE movement per deposit event, keyed on the canonical
            // `payment:0` leg (a deposit has a single tender). Recorded on BOTH
            // the create AND the idempotent-existing path so a partial prior
            // write (Payment + allocation landed, movement did not) is COMPLETED
            // on replay — record() is idempotent on the leg key.
            //
            // Task 24 Fix A guard (belt-and-suspenders, mirrors
            // PaymentRefundService): a FiscalEvent cash movement must carry a
            // journal_entry_id or the daily treasury:reconcile freezes the drawer.
            // After the allocation service posts + links a JE for every cash-moving
            // deposit (advance/419 or AR-clearing), this is UNREACHABLE for a
            // GL-linked repository — it fails LOUD only for a mis-configured
            // repository (no gl_account_id → no GL to post), refusing to record a
            // null-JE movement rather than silently poisoning the reconcile.
            $linkedJournalEntryId = $payment->fresh()?->journal_entry_id;
            if ($linkedJournalEntryId === null) {
                throw new \DomainException(
                    'DEPOSIT_RECEIPT cash movement requires a linked journal entry; payment '.
                        $payment->id.' for fiscal event '.$event->id.' resolved a null journal_entry_id '.
                        '(repository '.$repository->id.'). Refusing to record a null-JE movement the reconcile would freeze on.',
                );
            }

            // allowWhileFrozen is FALSE: DEPOSIT_RECEIPT `isServerOnly()`, so it
            // is a SERVER-authored back-office deposit, NOT offline device
            // replay. A frozen repo must reject it (HIGH-5). Decided from the
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
            ));
        });
    }

    private function handleMaturityLeg(
        FiscalEvent $event,
        DepositReceiptView $view,
        PaymentMethod $method,
        PaymentRepository $repository,
        Partner $partner,
        string $actorUserId,
        ?string $locationId,
    ): MaturityLegResult {
        return $this->maturityLegHandler->handleMaturityLeg(
            $event,
            new PaymentDTO(
                amount: $this->paymentAmount($event, $view),
                foreignCurrencyAmount: null,
                foreignCurrencyCode: null,
                instrumentSerial: null,
                instrumentType: null,
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

    private function resolveCustomer(FiscalEvent $event, DepositReceiptView $view): Partner
    {
        // Server-authored: customer.customer_id == partner_id (payload invariant).
        $partner = Partner::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('company_id', $event->company_id)
            ->whereIn('type', [PartnerType::Customer, PartnerType::Both])
            ->whereKey($view->customer->customerId)
            ->first();

        if (! $partner instanceof Partner) {
            throw $this->invariant($event, 'customer_not_found:customer_id='.$view->customer->customerId);
        }

        return $partner;
    }

    private function resolvePaymentMethod(FiscalEvent $event, DepositReceiptView $view): PaymentMethod
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

    private function resolveRepository(FiscalEvent $event, DepositReceiptView $view): PaymentRepository
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

        // Task 20 (review F14) — canonicalize on `gl_account_id`. The prior
        // `account_id`-ONLY check rejected repositories that carry a
        // `gl_account_id` but no legacy `account_id` (every other bridge —
        // TreasuryReceiptBridge, TreasuryAccountPaymentBridge, MultiPaymentService
        // — resolves the cash GL account via `gl_account_id`). Fall back to the
        // legacy `account_id` for repositories seeded before the gl_account_id
        // column landed, so both shapes resolve consistently.
        $accountId = $repository->gl_account_id ?? $repository->account_id;
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

    /**
     * Resolve the back-office actor — and FAIL LOUD if it isn't an active member
     * of the event's company.
     *
     * Unlike the device `ACCOUNT_PAYMENT` analog (which tolerates a null cashier
     * and records `created_by = null`), a server-authored deposit is recorded by
     * an authenticated company member. A null actor is NOT a benign audit gap
     * here: the shared `PaymentAllocationService` only posts the CustomerAdvance
     * journal entry — and the partner credit-balance refresh it triggers — when
     * the command actor resolves to a `User`. A null actor on the pure-advance
     * path (deposit with no open invoices) would therefore create a completed
     * Payment whose overflow never reaches the customer's credit balance. We
     * reject before creating the Payment so the money math can never silently
     * skip GL.
     */
    private function resolveActorUserId(FiscalEvent $event, DepositReceiptView $view): string
    {
        $user = User::query()
            ->where('tenant_id', $event->tenant_id)
            ->whereKey($view->payload->actorUserId)
            ->first();

        if (! $user instanceof User) {
            throw $this->invariant($event, 'actor_not_found:actor_user_id='.$view->payload->actorUserId);
        }

        $hasActiveCompanyMembership = UserCompanyMembership::query()
            ->where('user_id', $user->id)
            ->where('company_id', $event->company_id)
            ->where('status', MembershipStatus::Active)
            ->exists();

        if (! $hasActiveCompanyMembership) {
            throw $this->invariant(
                $event,
                'actor_not_active_company_member:actor_user_id='.$view->payload->actorUserId.':company_id='.$event->company_id,
            );
        }

        return $user->id;
    }

    private function existingPaymentForEvent(FiscalEvent $event): ?Payment
    {
        $existing = Payment::query()
            ->where('fiscal_event_id', $event->id)
            ->get();

        if ($existing->count() > 1) {
            throw $this->invariant($event, 'idempotency_conflict:multiple_back_office_payments_for_event');
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
        DepositReceiptView $view,
        string $actorUserId,
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
            'origin' => PaymentOrigin::BackOffice->value,
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
    private function paymentAmount(FiscalEvent $event, DepositReceiptView $view): string
    {
        if (! is_numeric($view->payment->amount)) {
            throw $this->invariant($event, 'payment_amount_not_numeric');
        }

        return $view->payment->amount;
    }

    private function paymentNotes(DepositReceiptView $view): string
    {
        return 'DEPOSIT_RECEIPT fiscal bridge; allocation_policy='.$view->payload->treasuryAllocationPolicy;
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
