<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Exceptions\RefundCompensationRefusedException;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v3-refund-chain-integration spec §5.2 — server-observable-evidence write-
 * off action.
 *
 * The compensation action NEVER reads device-local `refund_intents.
 * payout_confirmed_at`. Payout evidence is exclusively (a) the signed
 * refund payload's own `shift_id` (already present, chain-immutable) and
 * (b) the operator attestation captured on this request. One idempotent
 * `fiscal_refund_compensations` record per rejected `fiscal_event_id`
 * (universally addressable regardless of whether the rejection came from
 * projection dead-letter or ingress quarantine).
 *
 * **review round-2 CRITICAL 3 — scope + state-guard.** The caller (the
 * controller) resolves `$event` tenant+company-scoped BEFORE calling this
 * service (mirrors `ParseFailureResolutionController`'s established
 * pattern: a cross-tenant/cross-company fiscal_event_id must 404, not leak
 * existence via a 422/403 from inside the service). This service then
 * additionally asserts the event IS a `SALE_RECEIPT` with
 * `invoice_type_code=REFUND`, and that it is currently EITHER
 * dead-lettered OR ingress-quarantined — never a refund that already
 * applied successfully through the normal path, which is exactly the
 * double-cash-out hole: booking a write-off for an event whose own
 * projection already moved cash out of the drawer.
 */
final class RefundCompensationService
{
    public function __construct(
        private readonly GeneralLedgerService $generalLedgerService,
        private readonly TreasuryMovementServiceInterface $movementService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /**
     * @return array{compensation: array<string, mixed>, wasIdempotentHit: bool}
     */
    public function compensate(
        FiscalEvent $event,
        string $compensationClass,
        string $operatorId,
        string $operatorAttestation,
    ): array {
        if (! in_array($compensationClass, ['invalid_refund', 'valid_unbooked'], true)) {
            throw new \InvalidArgumentException(
                'Unknown compensation_class '.$compensationClass.'; expected invalid_refund or valid_unbooked.'
            );
        }

        $tenantId = (string) $event->tenant_id;
        $companyId = (string) $event->company_id;
        $fiscalEventId = (string) $event->id;

        // Idempotent-hit short-circuit — a repeated POST for the same
        // fiscal_event_id returns the existing record rather than
        // duplicating the GL entry and drawer adjustment. This is the
        // COMMON-CASE path; the unique-violation catch below the
        // transaction is the race-condition backstop for two genuinely
        // concurrent first-time requests (review round-2 IMPORTANT 11).
        $existing = DB::table('fiscal_refund_compensations')
            ->where('fiscal_event_id', $fiscalEventId)
            ->first();
        if ($existing !== null) {
            return [
                'compensation' => (array) $existing,
                'wasIdempotentHit' => true,
            ];
        }

        // review round-2 CRITICAL 3 — event_type=SALE_RECEIPT with
        // invoice_type_code=REFUND.
        //
        // treasury re-verification MINOR/NOTE — an UNRESOLVED ingress-
        // quarantine event has payload=null (it never parsed), so it hits
        // THIS branch (not_a_refund) rather than a dedicated
        // "still-quarantined, unreadable" reason. This is deliberate, not
        // a bug: with no parsed payload there is no invoice_type_code,
        // amount, or shift_id to safely act on, so refusing here — before
        // ever reaching the state-guard below — is the correct fail-closed
        // outcome for that case. Documented explicitly rather than
        // inventing a new reason code for a case that is already refused
        // for the right underlying cause (no readable payload).
        $payload = $event->payload;
        if (
            $event->event_type !== FiscalEventType::SALE_RECEIPT
            || ! is_array($payload)
            || ($payload['invoice_type_code'] ?? null) !== 'REFUND'
        ) {
            throw new RefundCompensationRefusedException(
                reason: 'not_a_refund',
                message: "RefundCompensationService: fiscal_event {$fiscalEventId} is not a SALE_RECEIPT/REFUND event; the write-off action only applies to rejected refunds.",
            );
        }

        // review round-2 CRITICAL 3 — state-guard: dead-lettered OR
        // ingress-quarantined, the SAME two partitions
        // DeadLetteredProjectionsController discriminates. An event that
        // is NEITHER has already applied successfully through the normal
        // projection path — writing off a "rejected" compensation for it
        // would double-cash-out the drawer.
        $isDeadLettered = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $fiscalEventId)
            ->where('projection_status', ProjectionStatus::DeadLettered->value)
            ->exists();
        $isIngressQuarantined = $event->integrity_exception_class === 'canonical_parse_failure';

        if (! $isDeadLettered && ! $isIngressQuarantined) {
            throw new RefundCompensationRefusedException(
                reason: 'not_rejected',
                message: "RefundCompensationService: fiscal_event {$fiscalEventId} is neither dead-lettered nor ingress-quarantined -- refusing to write off an event that already applied (or was never attempted).",
            );
        }

        // treasury re-verification CRITICAL — `integrity_exception_class`
        // is a WRITE-ONCE column (Task-8 immutability trigger): once an
        // event is ingress-quarantined it STAYS `canonical_parse_failure`
        // FOREVER, even after `ParseFailureResolutionService` resolves the
        // parse failure and its projections DISPATCH and APPLY
        // (`payload_parse_status` transitions to `parsed`, but this column
        // is never cleared). That makes `$isIngressQuarantined` alone an
        // UNRELIABLE "still rejected" signal — reachable double-cash-out:
        // quarantine -> resolve -> projections apply (cash moves via the
        // NORMAL path) -> the state-guard above still passes (the stale
        // quarantine flag is still true) -> write-off would ALSO move
        // cash for the same event. A positive, independent check closes
        // this: refuse unconditionally whenever ANY fiscal_event_projections
        // row for this event is Applied, regardless of which arm admitted
        // it above.
        $hasAppliedProjection = DB::table('fiscal_event_projections')
            ->where('fiscal_event_id', $fiscalEventId)
            ->where('projection_status', ProjectionStatus::Applied->value)
            ->exists();

        if ($hasAppliedProjection) {
            throw new RefundCompensationRefusedException(
                reason: 'already_applied',
                message: "RefundCompensationService: fiscal_event {$fiscalEventId} has at least one Applied projection -- its own normal path already moved cash; refusing to double-cash-out with a write-off compensation.",
            );
        }

        // review round-2 IMPORTANT 7 — §5.3 precheck for BOTH account
        // purposes BEFORE opening the transaction (422, not a 500 from a
        // bare Account::findByPurposeOrFail() deep inside the GL call).
        foreach ([SystemAccountPurpose::RefundWriteOff, SystemAccountPurpose::SalesReturn] as $purpose) {
            if (! $this->generalLedgerService->hasAccountForPurpose($companyId, $purpose)) {
                throw new RefundCompensationRefusedException(
                    reason: 'missing_account_purpose',
                    message: "RefundCompensationService: company {$companyId} is missing a chart-of-accounts entry for purpose {$purpose->value}. Run accounting:backfill-refund-compensation-accounts first.",
                );
            }
        }

        // review round-2 IMPORTANT 5 — deterministic repository selection:
        // active + GL-linked + stable UUID ordering (mirrors
        // TreasuryReceiptBridge::resolveRepositoryForTender()'s own
        // fallback stance).
        $repository = PaymentRepository::query()
            ->forCompany($companyId)
            ->ofType(RepositoryType::CashRegister)
            ->active()
            ->whereNotNull('gl_account_id')
            ->orderBy('id')
            ->first();
        if ($repository === null) {
            throw new RefundCompensationRefusedException(
                reason: 'missing_cash_repository',
                message: "RefundCompensationService: no active, GL-linked cash repository found for company {$companyId}.",
            );
        }

        // review round-2 IMPORTANT 6 (rule 19) — never a bare `?? 2` /
        // `?? 'EUR'` fallback: currency comes from the refund's OWN
        // signed payload (fail loud if absent, same discipline as
        // payments[0].amount below), not the repository.
        //
        // treasury re-verification MINOR — reading `$repository->currency`
        // instead (an earlier version of this fix did exactly that) makes
        // the movement port's F12 CurrencyMismatchException guard
        // trivially always-equal (it compares `$repo->currency !==
        // $intent->currency`), silently disarming it — the exact TENDER-
        // currency-not-repository-currency precedent already established
        // at `TreasuryReceiptBridge::recordPaymentLeg()` (Task 20 review
        // Fix 2, :1407-1414). The tender/receipt currency is the payload's
        // own `currency_code`.
        $currencyCode = $payload['currency_code'] ?? null;
        if (! is_string($currencyCode) || $currencyCode === '') {
            throw new RuntimeException(
                "RefundCompensationService: fiscal_event {$fiscalEventId} has no payload.currency_code to compensate."
            );
        }
        $currency = $currencyCode;
        $scale = $this->scaleResolver->getScale($currency);

        // review round-2 IMPORTANT 9 — amount is the CASH TENDER LEG
        // (payments[0].amount), not payload.total, which diverges from
        // the tender by cash_rounding_adjustment once rounding enables.
        $payments = $payload['payments'] ?? null;
        if (! is_array($payments) || ! isset($payments[0]) || ! is_array($payments[0]) || ! isset($payments[0]['amount'])) {
            throw new RuntimeException(
                "RefundCompensationService: fiscal_event {$fiscalEventId} has no payments[0].amount to compensate."
            );
        }
        /** @var numeric-string $amount */
        $amount = (string) $payments[0]['amount'];
        $amount = CurrencyScale::bcformatStrict($amount, $scale);

        // review round-2 IMPORTANT 14 (§5.2 evidence (a)) — persist the
        // refund's OWN signed shift_id.
        $shiftId = is_string($payload['shift_id'] ?? null) ? $payload['shift_id'] : null;

        $compensationId = Str::uuid()->toString();

        try {
            return DB::transaction(function () use (
                $event,
                $compensationClass,
                $amount,
                $scale,
                $currency,
                $repository,
                $operatorId,
                $operatorAttestation,
                $compensationId,
                $tenantId,
                $companyId,
                $shiftId,
            ): array {
                $entry = $this->generalLedgerService->createRefundCompensationEntry(
                    tenantId: $tenantId,
                    companyId: $companyId,
                    fiscalEventId: (string) $event->id,
                    compensationClass: $compensationClass,
                    amount: $amount,
                    scale: $scale,
                    // review round-2 IMPORTANT 8 — entry date = now(), never
                    // the device event time: backdating into a closed
                    // fiscal period can leave the entry permanently
                    // un-postable. The device event time is already
                    // preserved elsewhere (fiscal_events.event_time_device,
                    // this entry's own description/source_id).
                    entryDate: now(),
                    repository: $repository,
                );
                $this->generalLedgerService->postEntryNow($entry, null, $currency);

                // §5.2 — the movement port's own derived idempotency key,
                // structurally distinct from TreasuryReceiptBridge's per-
                // payment-leg keys (fiscal_event:{id}:payment:{i}): the
                // 'refund_writeoff' leg discriminator can never equal
                // 'payment:{i}' for any integer i, so this can never collide
                // with that same event's own payment-leg movement.
                $movementResult = $this->movementService->record(new MovementIntent(
                    repositoryId: (string) $repository->id,
                    tenantId: $tenantId,
                    companyId: $companyId,
                    direction: MovementDirection::Out,
                    amount: $amount,
                    currency: $currency,
                    sourceType: MovementSourceType::FiscalEvent,
                    sourceId: (string) $event->id,
                    idempotencyLeg: 'refund_writeoff',
                    journalEntryId: (string) $entry->id,
                    occurredAt: null,
                    reasonCode: MovementReasonCode::Correction,
                    reversesMovementId: null,
                    createdBy: $operatorId,
                    notes: "Refund compensation ({$compensationClass}): {$operatorAttestation}",
                ));

                DB::table('fiscal_refund_compensations')->insert([
                    'id' => $compensationId,
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'fiscal_event_id' => (string) $event->id,
                    'compensation_class' => $compensationClass,
                    'shift_id' => $shiftId,
                    'journal_entry_id' => (string) $entry->id,
                    'repository_movement_id' => $movementResult->movementId,
                    'operator_id' => $operatorId,
                    'operator_attestation' => $operatorAttestation,
                    'created_at' => now(),
                ]);

                $row = DB::table('fiscal_refund_compensations')->where('id', $compensationId)->first();

                return ['compensation' => (array) $row, 'wasIdempotentHit' => false];
            });
        } catch (QueryException $exception) {
            // review round-2 IMPORTANT 11 — race-condition backstop: two
            // genuinely concurrent first-time requests for the SAME
            // fiscal_event_id both pass the pre-transaction idempotent-hit
            // check above before either commits; the loser's INSERT hits
            // fiscal_refund_compensations_event_unique. Replay the winner's
            // committed row as a 200 instead of bubbling a 500. The
            // transaction has already rolled back (no orphaned GL entry /
            // movement from the loser).
            if ($this->isFiscalEventIdUniqueViolation($exception)) {
                $existing = DB::table('fiscal_refund_compensations')
                    ->where('fiscal_event_id', $fiscalEventId)
                    ->first();
                if ($existing !== null) {
                    return [
                        'compensation' => (array) $existing,
                        'wasIdempotentHit' => true,
                    ];
                }
            }

            throw $exception;
        }
    }

    /**
     * Whether a QueryException is a unique-key violation on
     * `fiscal_refund_compensations_event_unique` — i.e. a concurrent
     * request committed the same idempotency key first. Mirrors
     * `ReceiptReturnService::isRefundRequestIdUniqueViolation()`'s exact
     * SQLSTATE-plus-message-substring discipline.
     */
    private function isFiscalEventIdUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        if ($sqlState !== '23505' && $sqlState !== '23000') {
            return false;
        }

        return str_contains($exception->getMessage(), 'fiscal_refund_compensations_event_unique')
            || str_contains($exception->getMessage(), 'fiscal_event_id');
    }
}
