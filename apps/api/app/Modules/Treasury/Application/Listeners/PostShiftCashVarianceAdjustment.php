<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Listeners;

use App\Modules\Compliance\Services\AuditService;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentIntent;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use App\Modules\Treasury\Application\Services\TenderRepositoryResolver;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\InsufficientRepositoryBalanceException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\RepositoryAdjustmentServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Book the shift-close cash-count variance to the GL — document-per-action
 * remediation lane G3 (register gap G3).
 *
 * Closing a POS shift computed a variance, wrote it onto `pos_shifts`, raised a
 * fraud alert… and booked NOTHING. The 658/758 accounts, the journal-entry
 * method (`GeneralLedgerService::createRepositoryAdjustmentJournalEntry`) and,
 * since lane V3, the justifying `repository_adjustments` document all existed
 * and were documented for exactly this purpose. They were unwired, not
 * unmodelled. This listener is the wire.
 *
 * TREASURY-side by construction: the POS module owns no treasury write-port
 * usage (policed by tests/Architecture/TreasuryBalanceWritePortTest.php), so the
 * consumer of the POS domain event lives here and reaches the ledger through
 * {@see RepositoryAdjustmentServiceInterface} — one document + one posted
 * journal entry + one movement, cross-linked, in one transaction.
 *
 * ── SHIPS DISABLED (gate finding I1) ─────────────────────────────────────────
 * `treasury.shift_variance_gl_enabled` defaults to FALSE. The lane's own report
 * asks for an owner ruling on POS count semantics (does a cashier count the
 * takings, or the whole drawer including the opening float?) before this books
 * real money — `ReportGenerationService::buildExpectedPerMethod()` sums receipt
 * payments only, so a whole-drawer count would post the float to 658/758 on
 * every close, forever. Shipping enabled while asking that question would have
 * contradicted the report. The flag is the kill switch: no code deploy needed
 * to stop it, and nothing at all runs while it is off.
 *
 * ── ONE NUMBER (gate finding C1/I2) ──────────────────────────────────────────
 * The amount booked is `CashCountRecorded::$aggregateVariance` — byte-for-byte
 * the figure `ZReportSyncController`/`ReportGenerationService` stamp on
 * `pos_shifts.variance` and the figure `OpenFraudAlertForShiftVariance` tests
 * with `isZero()`. It is NOT re-derived from the per-tender breakdown: doing so
 * used to post a journal entry for a shortfall that `pos_shifts.variance` (NULL)
 * and the fraud alert (0.000 → short-circuit) never saw, because the shipping
 * device sends no `shift_fields.variance_amount`. The breakdown is still read,
 * for two narrower jobs — deciding WHICH repository the variance belongs to, and
 * cross-checking that its sum agrees with the aggregate (a disagreement refuses
 * the booking rather than picking a side).
 *
 * ── DOUBLE-COUNT GUARD (the lane's load-bearing correctness question) ────────
 * Per-receipt payment tolerance ALREADY posts to these same 658/758 accounts
 * (`GeneralLedgerService::createPOSPaymentToleranceEntry` and
 * `createPosToleranceWriteoffEntry`). It is NOT double counted here, and the
 * reason is structural rather than defensive: every 658/758 writer books
 * Dr 658 / **Cr ProductRevenue** (or the AR-side B2B mirror) and NONE touches a
 * cash account, while both expected-cash bases — the server's
 * `SUM(pos_receipt_payments.amount) − change_due` and the device's own
 * `cashTendered − change_due` term — are TENDERED-based, i.e. the cash that
 * physically entered the drawer. The tolerance is therefore already netted out
 * of "expected", and an honest count of a shift that wrote one off is BALANCED.
 *
 * That is the whole substantive answer, and it holds on both bases. The device's
 * LEGACY fallback — which attributes `receipt.total` when a receipt carries no
 * per-payment breakdown, inflating expected by exactly the shortfall — is
 * covered by an additional BELT:
 * {@see PaymentToleranceQueryService::hasUnattributableToleranceForShift()}
 * refuses the booking when a shift contains a tolerance-bearing receipt with no
 * `pos_receipt_payments` rows.
 *
 * Be precise about what that belt is (gate re-review N2): NEITHER current writer
 * of `tolerance_writeoff` can produce that shape, so it is a fail-safe against
 * legacy/foreign data, NOT a detector for a live defect — and it refuses the
 * WHOLE shift's GL leg, not the offending receipt's share. See the lane report
 * §A.2 correction for the evidence and the refusal-breadth caveat.
 *
 * ── Idempotency ─────────────────────────────────────────────────────────────
 * `CashCountRecorded` fires from BOTH the live path
 * (`ReportGenerationService::generateZReport`) and the offline replay path
 * (`ZReportSyncController`), so this listener must be idempotent per shift. It
 * is, at three independent layers:
 *   1. the document id is DERIVED (UUIDv5) from the shift id, so a replay
 *      resolves to the same `repository_adjustments` row via the service's
 *      `firstOrCreate` — V3's discriminator pattern;
 *   2. the movement-port key is `adjustment:{documentId}:shift:{shiftId}`;
 *   3. a partial unique index on `repository_adjustments.pos_shift_id` is the
 *      database-level backstop.
 *
 * ── Never block, never half-write, never silent (gate finding I4) ───────────
 * Every refusal path logs AND writes a durable `audit_events` row, so a missing
 * GL leg is queryable and alertable instead of living in a log file — the lane
 * exists precisely because a missing GL leg went unnoticed for months, and
 * re-creating that failure mode behind a `Log::` line would be the same defect.
 * Nothing is ever rethrown: a shift close must not fail because the GL leg could
 * not be booked, and the service's single transaction means a failure writes
 * nothing rather than a document without its entry.
 */
final readonly class PostShiftCashVarianceAdjustment
{
    /**
     * Namespace for the derived, per-shift adjustment document id. Frozen — a
     * change here would make every already-booked shift replay as a NEW
     * document.
     */
    private const DOCUMENT_ID_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8'; // Uuid::NAMESPACE_URL

    /**
     * The per-tender variance strings and `pos_shifts.variance` are scale-4;
     * compare and sum at that scale before normalizing to the repository's.
     */
    private const VARIANCE_SCALE = 4;

    /**
     * Audit event type for a refusal. One type, with a machine-readable
     * `reason` in the payload, so the whole class is one query.
     */
    private const REFUSAL_EVENT = 'treasury.shift_variance_gl_skipped';

    private const BOOKED_EVENT = 'treasury.shift_variance_gl_booked';

    /**
     * A physical cash count belongs in a cash till, never a bank account. The
     * shared resolver's historical fallback filters on `gl_account_id IS NOT
     * NULL` only (gate finding I7), so the caller asserts the type itself
     * rather than diverging from the rule the fiscal projection shares.
     */
    private const CASH_REPOSITORY_TYPES = [RepositoryType::CashRegister, RepositoryType::Safe];

    public function __construct(
        private RepositoryAdjustmentServiceInterface $adjustmentService,
        private TenderRepositoryResolver $repositoryResolver,
        private CurrencyScaleResolverInterface $scaleResolver,
        private PaymentToleranceQueryService $toleranceQuery,
        private AuditService $auditService,
    ) {}

    public function handle(CashCountRecorded $event): void
    {
        // Gate finding I1 — ships disabled; nothing runs, not even a query,
        // until the owner rules on count semantics.
        if (config('treasury.shift_variance_gl_enabled') !== true) {
            return;
        }

        try {
            $this->post($event);
        } catch (InsufficientRepositoryBalanceException $e) {
            // Gate re-review N4 — this is the disposition of the single riskiest
            // input (a large unexplained shortfall against a till whose cached
            // balance has already been swept by a close-of-day deposit), and it
            // is the exact case the 658 account exists for. Bucketing it under
            // the generic `exception` reason made it indistinguishable from a
            // crash without string-matching a class name. It gets its own
            // queryable reason, and stays a warning rather than an error: the
            // refusal is a deliberate policy outcome (`allowNegative: false`,
            // parity with the manual endpoint), not a fault.
            $this->refuse($event, 'insufficient_repository_balance', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } catch (RepositoryFrozenException $e) {
            // Same reasoning: a frozen till is a policy refusal
            // (`allowWhileFrozen: false` — this is server-computed, never an
            // offline device replay), not a crash.
            $this->refuse($event, 'repository_frozen', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // Log-never-block: the shift is already closed and the Z report
            // already sealed by the time this runs (the live path dispatches
            // from a DB::afterCommit callback; the offline path dispatches
            // after its transaction returns). Failing here would surface as a
            // 500 on a close that actually succeeded.
            $this->refuse($event, 'exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ], level: 'error');
        }
    }

    private function post(CashCountRecorded $event): void
    {
        // ── THE number (gate C1/I2) ──────────────────────────────────────────
        // Exactly what `pos_shifts.variance` carries and what the fraud alert
        // tests with isZero(). Never re-derived.
        $signedVariance = $this->numeric($event->aggregateVariance->amount);

        // ── Zero-variance rule (V3 gate) ─────────────────────────────────────
        // `CHECK (amount > 0)` rejects a zero-amount document, so a balanced
        // count must produce NO document, NO entry and NO movement — not a
        // zero-amount one. Returning here also keeps this listener's silence
        // exactly aligned with the fraud alert's own isZero() short-circuit.
        if (bccomp($signedVariance, '0', self::VARIANCE_SCALE) === 0) {
            return;
        }

        // Tenders that actually moved: the attribution set. A balanced tender
        // must not drag its repository into the ambiguity check below.
        $moved = array_values(array_filter(
            $event->tenderBreakdown,
            fn (CashCountBreakdownDTO $b): bool => bccomp($this->varianceOf($b), '0', self::VARIANCE_SCALE) !== 0,
        ));

        if ($moved === []) {
            // A non-zero aggregate with nothing to attribute it to. Booking it
            // would mean inventing a repository.
            $this->refuse($event, 'aggregate_not_attributable', [
                'aggregate_variance' => $signedVariance,
                'tender_count' => count($event->tenderBreakdown),
            ]);

            return;
        }

        // ── Aggregate vs breakdown cross-check (gate C1/I2) ──────────────────
        // The server validates each `cash_counts` row internally but never ties
        // their SUM to the declared aggregate. If the two disagree, the
        // attribution set this listener is about to use does not describe the
        // amount it is about to book — refuse rather than pick a side.
        $breakdownSum = array_reduce(
            $moved,
            fn (string $carry, CashCountBreakdownDTO $b): string => bcadd($carry, $this->varianceOf($b), self::VARIANCE_SCALE),
            '0',
        );

        if (bccomp($breakdownSum, $signedVariance, self::VARIANCE_SCALE) !== 0) {
            $this->refuse($event, 'aggregate_breakdown_mismatch', [
                'aggregate_variance' => $signedVariance,
                'breakdown_sum' => $breakdownSum,
            ]);

            return;
        }

        // ── Double-count guard, device-basis branch (gate C2) ────────────────
        if ($this->toleranceQuery->hasUnattributableToleranceForShift($event->shiftId)) {
            $this->refuse($event, 'unattributable_tolerance_writeoff', [
                'aggregate_variance' => $signedVariance,
                'detail' => 'A receipt in this shift carries a tolerance write-off with no payment breakdown; '
                    .'the device expected-cash basis may be inflated by the shortfall, which would re-book it to 658.',
            ]);

            return;
        }

        $repository = $this->resolveSingleRepository($event, $moved);

        if (! $repository instanceof PaymentRepository) {
            return; // already audited inside
        }

        if ($repository->currency !== $event->currencyCode) {
            // The counted amounts are denominated in the shift/company currency;
            // booking them against a repository held in another currency would
            // silently mis-state the till. The movement port would refuse this
            // anyway (CurrencyMismatchException) — refuse it here, with a
            // diagnosable record and no attempted write.
            $this->refuse($event, 'currency_mismatch', [
                'repository_id' => $repository->id,
                'repository_currency' => $repository->currency,
                'counted_currency' => $event->currencyCode,
            ]);

            return;
        }

        // Normalize ONCE, to the REPOSITORY's own currency scale (rule 19 —
        // explicit currency, never a bare no-arg getScale(): both trigger paths
        // can reach here with no CompanyContext bound).
        $scale = $this->scaleResolver->getScale($repository->currency);
        $absVariance = ltrim($signedVariance, '-');
        $magnitude = CurrencyScale::bcformatStrict($absVariance, $scale);

        if (bccomp($magnitude, '0', $scale) <= 0) {
            // A variance below the currency's smallest unit (the counts are
            // scale-4, the money columns are not). Same disposition as an exact
            // zero: no document, no entry, no movement.
            return;
        }

        // Gate finding M1 — `bcformatStrict` TRUNCATES (normalize-once contract,
        // V3 gate C1). Counted variances are scale-4 and money is scale-3, so up
        // to one scale-4 tick can fall off. Truncation is kept (a second
        // rounding policy here would break the V3 contract), but the residual is
        // no longer invisible: it is reported so the ledger's disagreement with
        // `pos_shifts.variance` is explainable to the last digit.
        $residual = bcsub($absVariance, $magnitude, self::VARIANCE_SCALE);

        // OVER (actual > expected) = cash IN: Dr cash / Cr 758 tolerance income.
        // SHORT (actual < expected) = cash OUT: Dr 658 tolerance expense / Cr cash.
        $direction = bccomp($signedVariance, '0', self::VARIANCE_SCALE) > 0
            ? MovementDirection::In
            : MovementDirection::Out;

        $result = $this->adjustmentService->post(new RepositoryAdjustmentIntent(
            repositoryId: $repository->id,
            tenantId: $event->tenantId,
            companyId: $event->companyId,
            direction: $direction,
            amount: $magnitude,
            reasonCode: MovementReasonCode::CountVariance,
            reasonText: sprintf(
                // Gate finding M2: the DIRECTION stated here is the one this
                // listener computed from the aggregate, not the device-supplied
                // `variance_direction`, which could contradict it inside a
                // fiscal document's narrative. The severity is device-reported
                // and labelled as such.
                'Shift-close cash count variance (%s; reported severity %s) — shift %s, Z report %s, terminal %s.',
                $direction === MovementDirection::In ? 'over' : 'short',
                $event->severity->value,
                $event->shiftId,
                $event->zReportId,
                $event->terminalId,
            ),
            userId: $event->cashierId,
            adjustmentId: $this->documentIdFor($event->shiftId),
            posShiftId: $event->shiftId,
            idempotencyLeg: 'shift:'.$event->shiftId,
        ));

        if ($result->wasIdempotentHit) {
            return;
        }

        // Gate re-review N3 — the booking is COMMITTED by the time we get here
        // (`post()` is transactional). These are post-commit side effects, so a
        // throw from either of them must NOT reach handle()'s catch, which would
        // record `treasury.shift_variance_gl_skipped` reason `exception` for a
        // document + posted journal entry + movement that exist — the exact
        // opposite signal from the one this audit trail was added to give.
        try {
            $this->auditService->record(
                companyId: $event->companyId,
                userId: $event->cashierId,
                eventType: self::BOOKED_EVENT,
                aggregateType: 'pos_shift',
                aggregateId: $event->shiftId,
                payload: [
                    'adjustment_id' => $result->adjustmentId,
                    'journal_entry_id' => $result->journalEntryId,
                    'movement_id' => $result->movementId,
                    'direction' => $direction->value,
                    'amount' => $result->normalizedAmount,
                    'currency' => $repository->currency,
                    'aggregate_variance' => $signedVariance,
                    'truncated_residual' => $residual,
                ],
                metadata: [
                    'z_report_id' => $event->zReportId,
                    'terminal_id' => $event->terminalId,
                    'repository_id' => $repository->id,
                ],
            );

            if (bccomp($residual, '0', self::VARIANCE_SCALE) !== 0) {
                Log::warning('Shift-close cash variance truncated to the currency scale; residual not booked', [
                    'shift_id' => $event->shiftId,
                    'aggregate_variance' => $signedVariance,
                    'booked_amount' => $result->normalizedAmount,
                    'residual' => $residual,
                    'currency' => $repository->currency,
                ]);
            }
        } catch (Throwable $e) {
            Log::error('Shift-close cash variance WAS booked but its audit trail could not be written', [
                'shift_id' => $event->shiftId,
                'adjustment_id' => $result->adjustmentId,
                'journal_entry_id' => $result->journalEntryId,
                'movement_id' => $result->movementId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the ONE repository this shift's variance belongs to.
     *
     * Requirement 4's "ambiguous → log-never-block, no partial writes": each
     * moved tender resolves through the SAME rule the fiscal projection bridge
     * uses ({@see TenderRepositoryResolver}). If any of them fails to resolve,
     * or they disagree, no adjustment is written at all — an aggregate booked
     * against an arbitrarily chosen till is worse than no entry, and a partial
     * per-tender booking would leave the shift's own `variance` column
     * unreconcilable against the ledger.
     *
     * @param  list<CashCountBreakdownDTO>  $moved
     */
    private function resolveSingleRepository(CashCountRecorded $event, array $moved): ?PaymentRepository
    {
        /** @var array<string, PaymentRepository> $resolved */
        $resolved = [];

        foreach ($moved as $breakdown) {
            // Gate finding I3 — the OFFLINE sync endpoint validates only
            // `uuid|distinct` on `payment_method_id`, so unlike the live path
            // (CashCountValidationService rejects `method_not_physical`) a card
            // tender's "variance" can arrive in the payload. Summing it into a
            // CASH adjustment would book card money against a till. The live
            // path can never reach this branch; the offline one now cannot
            // either.
            $method = PaymentMethod::query()
                ->where('tenant_id', $event->tenantId)
                ->where('company_id', $event->companyId)
                ->find($breakdown->paymentMethodId);

            if (! $method instanceof PaymentMethod || $method->is_physical !== true) {
                $this->refuse($event, 'tender_not_physical_or_unknown', [
                    'payment_method_id' => $breakdown->paymentMethodId,
                    'found' => $method instanceof PaymentMethod,
                ]);

                return null;
            }

            $repository = $this->repositoryResolver->resolveByMethodId(
                $event->tenantId,
                $event->companyId,
                $breakdown->paymentMethodId,
            );

            if (! $repository instanceof PaymentRepository) {
                $this->refuse($event, 'no_repository_resolved', [
                    'payment_method_id' => $breakdown->paymentMethodId,
                ]);

                return null;
            }

            $resolved[$repository->id] = $repository;
        }

        if (count($resolved) > 1) {
            $this->refuse($event, 'ambiguous_repositories', [
                'repository_ids' => array_keys($resolved),
            ]);

            return null;
        }

        $repository = array_values($resolved)[0] ?? null;

        if (! $repository instanceof PaymentRepository) {
            return null;
        }

        // Gate finding I7 — the shared fallback filters on `gl_account_id IS NOT
        // NULL` only, so a tenant with no `default_repository_id` mapping and a
        // bank repository sorting first by UUID would have its physical cash
        // variance booked against a BANK GL account. The shared rule is left
        // alone (diverging from the fiscal projection would be worse); the
        // assertion lives at this caller, where "this is a physical cash count"
        // is known.
        if (! in_array($repository->type, self::CASH_REPOSITORY_TYPES, true)) {
            $this->refuse($event, 'resolved_repository_is_not_a_cash_till', [
                'repository_id' => $repository->id,
                'repository_type' => $repository->type->value,
            ]);

            return null;
        }

        return $repository;
    }

    /**
     * A refusal that leaves a DURABLE trace (gate finding I4).
     *
     * Six distinct paths can legitimately produce no GL leg. The lane exists
     * because a missing GL leg went unnoticed for months, so each one is written
     * to `audit_events` under a single queryable event type with a
     * machine-readable `reason`, in addition to the log line. Never throws — an
     * audit-write failure must not turn a skipped GL leg into a failed shift
     * close.
     *
     * @param  array<string, mixed>  $payload
     */
    private function refuse(CashCountRecorded $event, string $reason, array $payload, string $level = 'warning'): void
    {
        $context = $payload + [
            'reason' => $reason,
            'shift_id' => $event->shiftId,
            'z_report_id' => $event->zReportId,
            'company_id' => $event->companyId,
        ];

        $level === 'error'
            ? Log::error('Shift-close cash variance could not be booked to the GL', $context)
            : Log::warning('Shift-close cash variance not booked to the GL', $context);

        try {
            $this->auditService->record(
                companyId: $event->companyId,
                userId: $event->cashierId,
                eventType: self::REFUSAL_EVENT,
                aggregateType: 'pos_shift',
                aggregateId: $event->shiftId,
                payload: $context,
                metadata: [
                    'z_report_id' => $event->zReportId,
                    'terminal_id' => $event->terminalId,
                    'severity' => $level,
                ],
            );
        } catch (Throwable $e) {
            Log::error('Failed to write the shift-variance GL refusal audit event', [
                'shift_id' => $event->shiftId,
                'reason' => $reason,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * `CashCountBreakdownDTO::$varianceAmount` is a plain `string`, and on the
     * OFFLINE path it originates in a device payload. A non-numeric value would
     * make bcmath throw inside a listener that must never blow up a shift close,
     * so it is treated as "this tender did not move" — the same disposition as an
     * exact zero. Gate finding: that silent drop is now logged, and because the
     * booked amount comes from the aggregate (never from this sum), a dropped
     * tender surfaces as an `aggregate_breakdown_mismatch` refusal rather than a
     * quietly wrong journal entry.
     *
     * @return numeric-string
     */
    private function varianceOf(CashCountBreakdownDTO $breakdown): string
    {
        if (is_numeric($breakdown->varianceAmount)) {
            return $breakdown->varianceAmount;
        }

        Log::warning('Non-numeric tender variance in a cash count; treated as zero', [
            'payment_method_id' => $breakdown->paymentMethodId,
            'variance_amount' => $breakdown->varianceAmount,
        ]);

        return '0';
    }

    /**
     * @return numeric-string
     */
    private function numeric(string $value): string
    {
        return is_numeric($value) ? $value : '0';
    }

    /**
     * Derive the adjustment document's UUID from the shift id, deterministically
     * and without a database round-trip, so BOTH trigger paths (and any number
     * of offline re-syncs of the same Z report) address the SAME document.
     */
    private function documentIdFor(string $shiftId): string
    {
        return Uuid::uuid5(
            self::DOCUMENT_ID_NAMESPACE,
            'urn:autoerp:pos-shift-cash-variance:'.$shiftId,
        )->toString();
    }
}
