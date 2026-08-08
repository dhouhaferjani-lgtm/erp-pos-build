<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Listeners;

use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentIntent;
use App\Modules\Treasury\Application\Services\TenderRepositoryResolver;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
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
 * ── DOUBLE-COUNT GUARD (the lane's load-bearing correctness question) ────────
 * Per-receipt payment tolerance ALREADY posts to these same 658/758 accounts
 * (`GeneralLedgerService::createPOSPaymentToleranceEntry`). It is NOT double
 * counted here, and the reason is structural rather than defensive:
 *
 *   - `createPOSPaymentToleranceEntry` books Dr 658 / **Cr ProductRevenue** —
 *     it never touches a cash account. The tolerance is a REVENUE-side gap
 *     (the customer paid less than the receipt total, within tolerance), not a
 *     cash-side one.
 *   - The variance basis is `actual − expected`, and expected comes from
 *     `ReportGenerationService::buildExpectedPerMethod()` =
 *     `SUM(pos_receipt_payments.amount) − change_due`. `amount` is the
 *     **TENDERED** amount ("Per the v1.1 contract, pos_receipt_payments.amount
 *     continues to store the tendered amount" —
 *     `POS/Application/Services/ReceiptPaymentService.php`), i.e. the cash that
 *     physically entered the drawer.
 *
 * So the tolerance is already NETTED OUT of the expected-cash basis: a receipt
 * that wrote off a tolerance leaves expected cash exactly equal to the cash the
 * cashier actually holds, and a correct count therefore yields variance ZERO —
 * no second 658 posting. Worked example in the lane report.
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
 * ── Never block, never half-write ───────────────────────────────────────────
 * Every refusal path (no resolvable repository, an ambiguous multi-repository
 * count, a currency disagreement, a frozen till, a chart missing 658/758) logs
 * and returns. A shift close must not fail because the GL leg could not be
 * booked, and the service's single transaction means a failure writes nothing
 * rather than a document without its entry.
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
     * The per-tender variance strings are scale-4 (CashCountValidationService);
     * compare and sum at their own scale before normalizing to the repository's.
     */
    private const VARIANCE_SCALE = 4;

    public function __construct(
        private RepositoryAdjustmentServiceInterface $adjustmentService,
        private TenderRepositoryResolver $repositoryResolver,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function handle(CashCountRecorded $event): void
    {
        try {
            $this->post($event);
        } catch (Throwable $e) {
            // Log-never-block: the shift is already closed and the Z report
            // already sealed by the time this runs (both trigger paths dispatch
            // after commit). Failing here would surface as a 500 on a close that
            // actually succeeded.
            Log::error('Shift-close cash variance could not be booked to the GL', [
                'shift_id' => $event->shiftId,
                'z_report_id' => $event->zReportId,
                'company_id' => $event->companyId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function post(CashCountRecorded $event): void
    {
        // ── Zero-variance rule (V3 gate) ─────────────────────────────────────
        // `CHECK (amount > 0)` rejects a zero-amount document, so a balanced
        // count must produce NO document, NO journal entry and NO movement —
        // not a zero-amount one. Only tenders that actually moved are
        // considered, which also keeps a balanced tender from dragging its
        // repository into the ambiguity check below.
        $moved = array_values(array_filter(
            $event->tenderBreakdown,
            fn (CashCountBreakdownDTO $b): bool => bccomp($this->varianceOf($b), '0', self::VARIANCE_SCALE) !== 0,
        ));

        if ($moved === []) {
            return;
        }

        $repository = $this->resolveSingleRepository($event, $moved);

        if (! $repository instanceof PaymentRepository) {
            return;
        }

        if ($repository->currency !== $event->currencyCode) {
            // The counted amounts are denominated in the shift/company currency;
            // booking them against a repository held in another currency would
            // silently mis-state the till. The movement port would refuse this
            // anyway (CurrencyMismatchException) — refuse it here, with a
            // diagnosable message and no attempted write.
            Log::warning('Shift-close cash variance not booked: repository currency differs from the counted currency', [
                'shift_id' => $event->shiftId,
                'repository_id' => $repository->id,
                'repository_currency' => $repository->currency,
                'counted_currency' => $event->currencyCode,
            ]);

            return;
        }

        /** @var numeric-string $signedVariance */
        $signedVariance = array_reduce(
            $moved,
            fn (string $carry, CashCountBreakdownDTO $b): string => bcadd($carry, $this->varianceOf($b), self::VARIANCE_SCALE),
            '0',
        );

        // Normalize ONCE, to the REPOSITORY's own currency scale (rule 19 —
        // explicit currency, never a bare no-arg getScale(): both trigger paths
        // can reach here with no CompanyContext bound).
        $scale = $this->scaleResolver->getScale($repository->currency);
        $magnitude = CurrencyScale::bcformatStrict(ltrim($signedVariance, '-'), $scale);

        if (bccomp($magnitude, '0', $scale) <= 0) {
            // A variance below the currency's smallest unit (the counts are
            // scale-4, the money columns are not). Same disposition as an exact
            // zero: no document, no entry, no movement.
            return;
        }

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
                'Shift-close cash count variance (%s, severity %s) — shift %s, Z report %s, terminal %s.',
                $event->varianceDirection->value,
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

        if (! $result->wasIdempotentHit) {
            Log::info('Shift-close cash variance booked to the GL', [
                'shift_id' => $event->shiftId,
                'adjustment_id' => $result->adjustmentId,
                'journal_entry_id' => $result->journalEntryId,
                'movement_id' => $result->movementId,
                'direction' => $direction->value,
                'amount' => $result->normalizedAmount,
                'currency' => $repository->currency,
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
            $repository = $this->repositoryResolver->resolveByMethodId(
                $event->tenantId,
                $event->companyId,
                $breakdown->paymentMethodId,
            );

            if (! $repository instanceof PaymentRepository) {
                Log::warning('Shift-close cash variance not booked: no GL-linked repository resolves for a counted tender', [
                    'shift_id' => $event->shiftId,
                    'payment_method_id' => $breakdown->paymentMethodId,
                    'company_id' => $event->companyId,
                ]);

                return null;
            }

            $resolved[$repository->id] = $repository;
        }

        if (count($resolved) > 1) {
            Log::warning('Shift-close cash variance not booked: the counted tenders resolve to more than one repository', [
                'shift_id' => $event->shiftId,
                'repository_ids' => array_keys($resolved),
                'company_id' => $event->companyId,
            ]);

            return null;
        }

        return array_values($resolved)[0] ?? null;
    }

    /**
     * `CashCountBreakdownDTO::$varianceAmount` is a plain `string`, and on the
     * OFFLINE path it originates in a device payload. A non-numeric value would
     * make bcmath throw inside a listener that must never blow up a shift close,
     * so it is treated as "this tender did not move" — the same disposition as an
     * exact zero.
     *
     * @return numeric-string
     */
    private function varianceOf(CashCountBreakdownDTO $breakdown): string
    {
        return is_numeric($breakdown->varianceAmount) ? $breakdown->varianceAmount : '0';
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
