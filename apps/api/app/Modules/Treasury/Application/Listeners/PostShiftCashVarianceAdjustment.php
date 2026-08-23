<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Listeners;

use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryPostException;
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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
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
 * `treasury.shift_variance_gl_enabled` defaults to FALSE. POS count semantics
 * are settled as whole-drawer, but Treasury does not yet book the opening float
 * or mid-shift drawer operations that form the expected balance (SV-3/SV-4).
 * Enabling this variance leg first would create a cash/GL mismatch. The flag is
 * the kill switch: no code deploy is needed to stop it, and nothing at all runs
 * while it is off.
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
 * cash account. On the live device path, the receipt term in the whole-drawer
 * expected balance is `cashTendered − change_due`: the cash that physically
 * entered the drawer. The tolerance is therefore already netted out of
 * "expected", and an honest count of a shift that wrote one off is BALANCED.
 * The retired schema-v2 server helper reached the same tolerance conclusion,
 * but it is not a live expected-cash basis and must not define policy.
 *
 * That is the whole substantive answer on the live basis. The device's
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
 * A refusal never reaches the caller: a shift close must not fail because the GL
 * leg could not be booked, and the service's single transaction means a failure
 * writes nothing rather than a document without its entry.
 *
 * ── QUEUED: retry + dead letter (R-8) ───────────────────────────────────────
 * This listener used to be plain and synchronous, which cost it the only two
 * dispositions a fiscal-integrity fault actually wants: a RETRY, and a DEAD
 * LETTER when the retries run out. enforcement-P3 M1 closed the swallow (the
 * unbalanced post got its own queryable reason) and reported the missing retry
 * story as R-8 (`docs/handoff/reviews/enforcement-p3/M1-census.md` §6). This is
 * that story.
 *
 * `ShouldQueue` is the right shape here and does NOT double-queue: BOTH
 * producers of `CashCountRecorded` raise it from an ordinary HTTP request and
 * never from inside a job — the live one from a `DB::afterCommit` callback
 * (`ReportGenerationService.php:415`) and the offline one plainly after its
 * transaction returns (`ZReportSyncController.php:269`). No enclosing job means
 * no enclosing retry semantics to collide with.
 *
 * What changed, precisely:
 *   - the two EXCEPTION-shaped arms (`unbalanced_journal_entry` and the generic
 *     crash bucket) now RE-THROW so the worker counts the attempt, applies the
 *     backoff and re-runs `handle()`. The POLICY refusals are untouched: a
 *     shortfall larger than the till, a frozen till, a non-cash repository, an
 *     aggregate/breakdown disagreement — retrying those is guaranteed to produce
 *     the same answer, so they stay terminal, durably-audited refusals.
 *   - {@see failed()} writes a DEAD LETTER: the pre-R-8 refusal row (unchanged
 *     event type and reason, so existing queries and alerts keep working) plus a
 *     `treasury.shift_variance_gl_dead_lettered` row carrying every field needed
 *     to re-book the variance by hand once the event itself is gone.
 *   - on a connection that CANNOT retry (`sync`, or a direct call with no job)
 *     nothing is thrown at all — the fault dead-letters immediately. Throwing
 *     under `sync` would put the exception back on the shift-close request
 *     stack, which is the exact 500-on-a-successful-close this file has always
 *     refused. Production runs redis + Horizon (`config/queue.php:16`,
 *     `.env QUEUE_CONNECTION=redis`), so there the retries are real.
 *   - the flag is read at BOTH ends (see {@see ShouldQueue()} and the belt at
 *     the top of {@see handle()}), and a disagreement between them is AUDITED
 *     rather than silent — gate round 1 P2-2.
 *
 * ── What this class does NOT cover: the enqueue leg ─────────────────────────
 * Everything above starts once the job is RUNNING. The push itself happens in
 * the producer's frame, where `Dispatcher::dispatch()` has no `try/catch`, so a
 * Redis outage or a serialization fault at push time propagates straight out of
 * `event()` — onto a Z report that is already committed and hash-chained. Do not
 * read the "never a spurious 500" promise as covering that; it never could.
 * Gate round 1 P2-1 closes it one frame up, at the only place that can: both
 * producers raise the event through
 * `App\Modules\POS\Application\Services\CashCountDispatcher` (named, not
 * imported — gate round 2 F-6: a `use` here would create a real Treasury→POS
 * static edge for a documentation link), which `report()`s the fault and
 * degrades it to a durable `pos.cash_count_consumers_failed` audit row. Note
 * that guard is all-or-nothing: a PUSH failure aborts the fraud alert and the
 * stored-event write too — see that class's docblock. Queue reachability at
 * Z-close is a pre-enable item on the G-5 checklist
 * (`docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md`).
 *
 * Tenancy across the queue boundary is carried the way every other queued
 * listener in this codebase carries it — by `QueueTenancyBootstrapper`
 * (`config/tenancy.php:42`), which stamps `tenant_id` into the job payload and
 * re-initializes tenancy on `JobProcessing`. Nothing is passed by hand, exactly
 * as `Loyalty\…\EarnPointsOnReceiptCompleted` and
 * `Inventory\…\ApplyStockAdjustmentsOnCountingCompleted` do. Every query below
 * is additionally scoped by the tenant/company ids carried ON the event, so it
 * is correct even with no tenancy bound at all.
 *
 * ── Retry safety (rule 19 + idempotency) ────────────────────────────────────
 * A worker has NO CompanyContext, so every scale resolution in this path already
 * passes an explicit currency: this listener's own
 * `getScale($repository->currency)`, RepositoryAdjustmentService
 * at :98, and `createRepositoryAdjustmentJournalEntry` forwards `$currencyCode`
 * into `postEntryNow` rather than falling back to the bare no-arg
 * `GeneralLedgerService::scale()`. A retry cannot double-post: the document id is
 * UUIDv5-derived from the shift id and taken with `firstOrCreate`
 * (RepositoryAdjustmentService.php:131), a replay reuses that document's existing
 * journal entry instead of posting a second one (:156), the movement port is
 * keyed `adjustment:{documentId}:shift:{shiftId}`, and two partial unique indexes
 * back all of it in the database — `repository_adjustments.pos_shift_id` and
 * `journal_entries (source_type, source_id) WHERE source_type =
 * 'repository_adjustment' AND status = 'posted'`. That last one matters
 * specifically here: `journal_entries` has no GLOBAL (source_type, source_id)
 * uniqueness, so the guard has to be — and is — explicit per source type.
 *
 * @tenancy-via-queue-payload Queued listener — tenancy crosses the queue boundary via Stancl QueueTenancyBootstrapper (config/tenancy.php:42), which stamps tenant_id into the payload with a global payload generator and re-initializes on JobProcessing/JobRetryRequested; every query here is additionally scoped by the tenant_id/company_id carried ON the event, so the handler is correct even with no tenancy bound.
 */
final class PostShiftCashVarianceAdjustment implements ShouldQueue
{
    use InteractsWithQueue;

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
     * The DEAD LETTER (R-8). Written once, when the retry budget is spent (or
     * immediately on a connection that cannot retry), and deliberately its OWN
     * event type rather than a `reason` on the refusal type: "we gave up on this
     * variance" is an operator action item, not one of the six ordinary
     * no-GL-leg outcomes, and it must be alertable on its own.
     */
    private const DEAD_LETTER_EVENT = 'treasury.shift_variance_gl_dead_lettered';

    /**
     * A physical cash count belongs in a cash till, never a bank account. The
     * shared resolver's historical fallback filters on `gl_account_id IS NOT
     * NULL` only (gate finding I7), so the caller asserts the type itself
     * rather than diverging from the rule the fiscal projection shares.
     */
    private const CASH_REPOSITORY_TYPES = [RepositoryType::CashRegister, RepositoryType::Safe];

    /**
     * A GL write, not a network call: retry a small number of times and stop.
     * Three attempts covers the transient faults this path can actually hit (a
     * lock timeout on the company advisory lock, a deadlock, a connection blip)
     * without keeping a fiscal-integrity fault circulating.
     */
    public int $tries = 3;

    /**
     * Seconds before each retry. Short enough that a shift close still
     * reconciles within the same close-of-day, long enough for a contended
     * advisory lock to clear.
     *
     * @var list<int>
     */
    public array $backoff = [5, 15];

    /**
     * The `default` queue — listed in `config/horizon.php` `defaults.*.queue`
     * and therefore actually consumed (CLAUDE.md rule 20). No new named queue is
     * warranted: this is a single small GL write, not a projection stream, and
     * an unlisted queue would silently never run.
     */
    public string $queue = 'default';

    public function __construct(
        private readonly RepositoryAdjustmentServiceInterface $adjustmentService,
        private readonly TenderRepositoryResolver $repositoryResolver,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly PaymentToleranceQueryService $toleranceQuery,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Gate finding I1, preserved across the ShouldQueue conversion.
     *
     * The kill switch's contract is "nothing at all runs while it is off". Left
     * only in handle(), queueing would have eroded that into "a no-op job is
     * enqueued, carried through Redis and then discarded" for every single shift
     * close on every tenant. `Dispatcher::handlerWantsToBeQueued`
     * (Dispatcher.php:634) consults this BEFORE pushing, so a disabled lane
     * enqueues nothing again.
     *
     * The identical check stays in handle() as the belt: it keeps the flag
     * runtime-evaluable for a job already in flight when the switch is thrown,
     * and it is the check the existing gate tests pin. Same flag, same
     * condition, same default — this only moves the decision earlier.
     */
    public function shouldQueue(CashCountRecorded $event): bool
    {
        return config('treasury.shift_variance_gl_enabled') === true;
    }

    public function handle(CashCountRecorded $event): void
    {
        // Gate finding I1 — ships disabled; nothing runs, not even a query,
        // until the owner rules on count semantics.
        if (config('treasury.shift_variance_gl_enabled') !== true) {
            // Gate round 1 P2-2 — "never enqueued" and "enqueued, then found
            // disabled" are NOT the same outcome and must not look the same.
            //
            // `shouldQueue()` runs in the web process and this belt runs in the
            // worker process, and both read a PER-PROCESS env var
            // (`config/treasury.php:28`). A job existing here is proof that
            // `shouldQueue()` saw the flag ENABLED at dispatch, so reaching this
            // line means the two processes disagree — an API/Horizon env skew,
            // or a flip that landed mid-flight. Returning silently would drop
            // the variance with no booking, no skipped row and no dead letter:
            // byte-for-byte the "missing GL leg goes unnoticed for months"
            // failure this whole lane exists because of, re-created by splitting
            // one check across two processes.
            //
            // With no job (the flag was simply off at dispatch) there is nothing
            // to report: that path is pinned by
            // test_the_disabled_lane_enqueues_nothing_at_all and must stay
            // completely silent.
            if ($this->job !== null) {
                $this->refuse($event, 'feature_disabled_after_enqueue', [
                    'detail' => 'A job was enqueued while treasury.shift_variance_gl_enabled was true, '
                        .'but the worker sees it false — the API and worker environments disagree, '
                        .'or the flag was flipped mid-flight. The variance is NOT booked.',
                ], level: 'error');
            }

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
        } catch (UnbalancedJournalEntryPostException $e) {
            // enforcement-P3 M1 (round 1, finding 1) — the GL posting chokepoint
            // refused this adjustment because Sigma(debits) != Sigma(credits).
            //
            // This one is NOT a policy outcome like the two above, and not an
            // ordinary crash either: it is a fiscal-integrity fault. It reaches
            // this frame SYNCHRONOUSLY — `createRepositoryAdjustmentJournalEntry`
            // posts through `postEntryNow` (GeneralLedgerService.php:1308) with no
            // `afterCommit` deferral — so before it had its own reason it was
            // swallowed into the generic `exception` bucket below, where it was
            // indistinguishable from a crash and nothing could alert on it.
            //
            // R-8 — this is now RETRYABLE. P3 recorded the reason it could not
            // be: "this listener is a plain synchronous listener
            // (TreasuryServiceProvider, not ShouldQueue) … so a throw
            // would surface as a 500 on a close that actually succeeded and
            // would still not retry anything". Both halves of that objection
            // died with the ShouldQueue conversion above — the throw now lands
            // on a worker, and it buys real attempts. The distinct, queryable,
            // alertable reason (gate re-review N4) survives: it is what the
            // dead letter is labelled with once the attempts are spent.
            $this->retryOrDeadLetter($event, 'unbalanced_journal_entry', $e);
        } catch (Throwable $e) {
            // The generic crash bucket — a deadlock, a lock timeout on the
            // company advisory lock, a connection blip. This is the arm retries
            // exist for: today a transient fault burned a shift's GL leg
            // permanently and left an alarming `exception` refusal behind it.
            $this->retryOrDeadLetter($event, 'exception', $e);
        }
    }

    /**
     * The R-8 disposition for an exception-shaped fault: hand it back to the
     * worker while attempts remain, otherwise dead-letter it.
     *
     * The attempt COUNTING is the queue's job, not this method's — the worker
     * compares `attempts()` against `$tries`, applies `$backoff`, and calls
     * {@see failed()} on the last one. All this method decides is whether there
     * is a worker at all.
     */
    private function retryOrDeadLetter(CashCountRecorded $event, string $reason, Throwable $e): void
    {
        if (! $this->connectionCanRetry()) {
            $this->deadLetter($event, $reason, $e);

            return;
        }

        Log::warning('Shift-close cash variance GL leg failed; returning it to the queue for retry', [
            'shift_id' => $event->shiftId,
            'z_report_id' => $event->zReportId,
            'company_id' => $event->companyId,
            'reason' => $reason,
            'attempt' => $this->attempts(),
            'tries' => $this->tries,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        throw $e;
    }

    /**
     * Is there a worker behind this invocation that will actually retry, and
     * then fail, the job?
     *
     * `sync` is not one — there the "queue" IS the shift-close request stack, so
     * a throw would resurface as a 500 on a close that succeeded, the failure
     * mode this whole file is organised around. A null `$this->job` (a direct
     * call, never the registered path) is not one either. On both, an
     * exception-shaped fault goes straight to the dead letter, which is strictly
     * more than the pre-R-8 behaviour, never less.
     */
    private function connectionCanRetry(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }

    /**
     * The queue has spent every attempt on this variance.
     *
     * Invoked by the framework through `CallQueuedListener::failed()` with the
     * original event and the last exception — so this is the ONLY place that
     * needs to know how to turn a dead job back into an actionable record.
     */
    public function failed(CashCountRecorded $event, Throwable $e): void
    {
        $this->deadLetter($event, $this->reasonFor($e), $e);
    }

    /**
     * Write the dead letter. Two rows, deliberately:
     *
     *  1. the pre-R-8 refusal row — SAME event type, SAME machine-readable
     *     reason, same `error` level. Every dashboard, query and alert built on
     *     `treasury.shift_variance_gl_skipped` keeps working unchanged, and a
     *     shift whose GL leg is missing is still findable by the one query the
     *     lane was designed around.
     *  2. the dead-letter row — the recovery record. The event object dies with
     *     the job, so everything needed to re-book this variance by hand is
     *     copied out of it verbatim, including the DERIVED document id, so an
     *     operator can check whether a later replay already landed it.
     *
     * Never throws. A failing audit write must not turn a dead-lettered GL leg
     * into a dead-lettered job that also crashes its own failure handler — under
     * `sync` that exception would travel straight back to the shift close.
     */
    private function deadLetter(CashCountRecorded $event, string $reason, Throwable $e): void
    {
        $this->refuse($event, $reason, [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'dead_lettered' => true,
        ], level: 'error');

        try {
            $this->auditService->record(
                companyId: $event->companyId,
                userId: $event->cashierId,
                eventType: self::DEAD_LETTER_EVENT,
                aggregateType: 'pos_shift',
                aggregateId: $event->shiftId,
                payload: [
                    'reason' => $reason,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    // Gate round 1 P3-1 — `tries` is the BUDGET, never the
                    // count, and reporting it as though it were the count made
                    // the sync path (exactly one attempt) claim three.
                    //
                    // `attempts` is EXACT whenever this instance still holds its
                    // job — the cannot-retry path, where it is 1. It is NULL on
                    // the framework's failed() path, because
                    // CallQueuedListener::failed() resolves a FRESH listener
                    // from the container with no job attached: the worker knows
                    // the count there, this frame does not, and inventing one is
                    // what P3-1 caught. `dead_letter_source` is the
                    // discriminator: `queue_failed_handler` means the queue
                    // exhausted the budget, `listener` means this connection
                    // could not retry at all and `attempts` is exact.
                    'attempts' => $this->job?->attempts(),
                    'tries' => $this->tries,
                    'dead_letter_source' => $this->job !== null ? 'listener' : 'queue_failed_handler',
                    'tenant_id' => $event->tenantId,
                    'company_id' => $event->companyId,
                    'shift_id' => $event->shiftId,
                    'z_report_id' => $event->zReportId,
                    'terminal_id' => $event->terminalId,
                    'cashier_id' => $event->cashierId,
                    'currency' => $event->currencyCode,
                    'aggregate_variance' => $event->aggregateVariance->amount,
                    'variance_direction' => $event->varianceDirection->value,
                    'severity' => $event->severity->value,
                    // The document a manual re-book MUST use, so a retry, a
                    // later offline replay and an operator all address the same
                    // `repository_adjustments` row.
                    'adjustment_document_id' => $this->documentIdFor($event->shiftId),
                    'tender_breakdown' => array_map(
                        fn (CashCountBreakdownDTO $b): array => [
                            'payment_method_id' => $b->paymentMethodId,
                            'currency_code' => $b->currencyCode,
                            'expected_amount' => $b->expectedAmount,
                            'actual_amount' => $b->actualAmount,
                            'variance_amount' => $b->varianceAmount,
                        ],
                        $event->tenderBreakdown,
                    ),
                    'recorded_at' => $event->recordedAt,
                ],
                metadata: [
                    'z_report_id' => $event->zReportId,
                    'terminal_id' => $event->terminalId,
                    'severity' => 'error',
                ],
            );
        } catch (Throwable $auditFailure) {
            Log::critical('Shift-close cash variance was DEAD-LETTERED and its dead-letter record could not be written', [
                'shift_id' => $event->shiftId,
                'z_report_id' => $event->zReportId,
                'company_id' => $event->companyId,
                'reason' => $reason,
                'aggregate_variance' => $event->aggregateVariance->amount,
                'original_exception' => $e::class,
                'original_message' => $e->getMessage(),
                'audit_exception' => $auditFailure::class,
            ]);
        }
    }

    /**
     * Recover the machine-readable refusal reason from the exception alone.
     *
     * {@see failed()} is handed only the exception, and the framework may also
     * call it for a fault that never passed through handle()'s catch arms at all
     * (a timeout, a `maxExceptions` trip). One discriminator, in one place.
     */
    private function reasonFor(Throwable $e): string
    {
        return $e instanceof UnbalancedJournalEntryPostException
            ? 'unbalanced_journal_entry'
            : 'exception';
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
     * EVERY path that legitimately produces no GL leg comes through here — the
     * count was stated as "six" from the original lane and was already stale at
     * base (gate round 3, M-2: eleven non-dead-letter reason codes, plus
     * {@see deadLetter()}, which is a caller too). The lane exists because a
     * missing GL leg went unnoticed for months, so each one is written to
     * `audit_events` under a single queryable event type with a machine-readable
     * `reason`, in addition to the log line. Never throws — an audit-write
     * failure must not turn a skipped GL leg into a failed shift close.
     *
     * ── Why there is no report() here (gate round 3, M-1 — settled) ──────────
     * Do not add one. Exception-shaped faults ALREADY reach Sentry without it:
     * {@see retryOrDeadLetter()} re-throws them, and `Worker::runJob()`
     * (`vendor/laravel/framework/src/Illuminate/Queue/Worker.php`) reports every
     * exception that escapes a job on the configured redis connection. Calling
     * `report()` in here would therefore DOUBLE-report genuine crashes while
     * spamming the reporter with eleven legitimate, deliberate refusals — a
     * frozen till, an unresolved repository, an aggregate/breakdown
     * disagreement. That is the opposite of alerting reach.
     *
     * Contrast `App\Modules\POS\Application\Services\CashCountDispatcher` (named,
     * not imported — F-6; and `{@see}` with an FQCN is not an option either,
     * because Pint's `fully_qualified_strict_types` would re-add that very
     * import), which DOES call `report()`: there the exception is swallowed and
     * never escapes to a worker, so nothing else would report it (round 2, F-1).
     *
     * The one gap is recorded rather than closed: on `sync`, or with a null
     * `$this->job`, an exception-shaped fault dead-letters WITHOUT re-throwing,
     * so no worker reports it. That is byte-identical to the pre-R-8 behaviour
     * and is not the configured connection (`QUEUE_CONNECTION=redis`); it is a
     * line on the G-5 pre-enable checklist (gate round 3, M-5).
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
