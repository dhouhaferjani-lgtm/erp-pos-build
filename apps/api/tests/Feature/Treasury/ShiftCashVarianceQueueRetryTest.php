<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryPostException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Listeners\PostShiftCashVarianceAdjustment;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * R-8 — the shift-variance GL leg gets a RETRY and a DEAD LETTER.
 *
 * enforcement-P3 M1 made the swallowed unbalanced post loud
 * (`PostShiftCashVarianceAdjustment:210`) but could not give it a retry, because
 * a plain synchronous listener has nowhere to retry TO: the shift is already
 * closed and the Z report already sealed, so a throw would have surfaced as a
 * 500 on a close that succeeded. The residual was reported as R-8 in
 * `docs/handoff/reviews/enforcement-p3/M1-census.md` §6.
 *
 * This file pins the whole story:
 *   - the listener is QUEUED, on a queue Horizon actually consumes, with an
 *     explicit retry budget;
 *   - the queued path books correctly with NO CompanyContext bound (rule 19/20 —
 *     a worker has none);
 *   - an exception-shaped fault is handed BACK to the worker instead of being
 *     swallowed, so the attempt is really retried;
 *   - neither a retry after a failed attempt nor a retry after a COMMITTED one
 *     can double-post the GL entry;
 *   - when the attempts are spent the variance lands in a durable dead letter
 *     carrying everything needed to re-book it by hand;
 *   - and on a connection that cannot retry (`sync` — the whole test suite, and
 *     the shift-close request stack) nothing is thrown at all: the fault
 *     dead-letters immediately, so the never-block invariant is intact.
 *
 * The imbalance is produced with real production machinery, never a mock: an
 * Eloquent `created` hook adds a third leg to the adjustment entry while it is
 * still an unchained Draft, which `JournalLineObserver` permits, and the GL
 * posting chokepoint then refuses it.
 */
final class ShiftCashVarianceQueueRetryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frozen in the listener — a change here would make every already-booked
     * shift replay as a NEW document, so the test states it independently.
     */
    private const DOCUMENT_ID_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    private Account $cashAccount;

    private PaymentRepository $till;

    private PaymentMethod $cashMethod;

    /**
     * When true, the next adjustment Draft gets an extra unbalancing leg. Held
     * as state rather than closed over so a test can switch it off between two
     * attempts and model "attempt 1 failed, attempt 2 succeeds".
     */
    private bool $unbalanceNextEntry = false;

    /** Re-entrancy guard for the hook's own INSERT. */
    private bool $injectingLine = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->cashAccount = $this->accountFor(SystemAccountPurpose::Cash);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->till = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'balance' => '400.000',
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->cashAccount->id,
            'is_active' => true,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => true,
            'is_active' => true,
            'default_repository_id' => $this->till->id,
        ]);

        config()->set('treasury.shift_variance_gl_enabled', true);

        $this->registerUnbalancingHook();
    }

    // -------------------------------------------------------------------------
    // (a) the listener is queued, on a queue that is actually consumed
    // -------------------------------------------------------------------------

    public function test_the_variance_listener_is_pushed_to_a_horizon_consumed_queue_with_a_retry_budget(): void
    {
        Queue::fake();

        app(CompanyContext::class)->clear();
        event($this->cashCountEvent((string) Str::uuid()));

        $job = $this->pushedListenerJob();

        $this->assertSame('handle', $job->method);

        // The retry budget is not just declared on the listener — it has to be
        // PROPAGATED onto the job, which is the only copy the worker reads.
        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 15], $job->backoff);

        Queue::assertPushedOn('default', CallQueuedListener::class);

        // CLAUDE.md rule 20: an unlisted queue is silently never consumed.
        $consumed = [];
        /** @var array<string, mixed> $supervisors */
        $supervisors = config('horizon.defaults');
        foreach ($supervisors as $supervisor) {
            if (is_array($supervisor) && is_array($supervisor['queue'] ?? null)) {
                foreach ($supervisor['queue'] as $queue) {
                    $consumed[] = $queue;
                }
            }
        }

        $this->assertContains(
            'default',
            $consumed,
            'The shift-variance listener is pushed to a queue no Horizon supervisor consumes.',
        );
    }

    /**
     * The kill switch's contract is "nothing at all runs while it is off" (gate
     * finding I1). Queueing must not erode that into "a no-op job is enqueued,
     * carried through Redis and discarded" once per shift close per tenant.
     */
    public function test_the_disabled_lane_enqueues_nothing_at_all(): void
    {
        config()->set('treasury.shift_variance_gl_enabled', false);

        Queue::fake();

        app(CompanyContext::class)->clear();
        event($this->cashCountEvent((string) Str::uuid()));

        Queue::assertNotPushed(CallQueuedListener::class);
    }

    /**
     * Gate round 1 P2-2 — the flag is now read in TWO processes: `shouldQueue()`
     * in the web process, the belt at the top of `handle()` in the worker. Both
     * read a per-process env var, so an API/Horizon env skew — or a flip that
     * lands between enqueue and execution — puts a job on the queue that the
     * worker then declines.
     *
     * That must not be silent. A job existing at all is proof the flag was ON at
     * dispatch, so the worker disagreeing is a misconfiguration worth an
     * operator's attention, not a no-op.
     */
    public function test_a_worker_that_disagrees_with_the_dispatch_flag_audits_instead_of_dropping_the_variance(): void
    {
        $shiftId = (string) Str::uuid();
        $event = $this->cashCountEvent($shiftId);

        // Enqueued while enabled…
        Queue::fake();
        event($event);
        $job = $this->pushedListenerJob();

        // …then the worker's config says otherwise.
        config()->set('treasury.shift_variance_gl_enabled', false);

        app(CompanyContext::class)->clear();
        $job->job = new FakeJob;
        $job->handle(app());

        $this->assertSame(1, $this->refusalCount($shiftId));
        $refusal = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_skipped')
            ->where('aggregate_id', $shiftId)
            ->firstOrFail();
        $this->assertStringContainsString('feature_disabled_after_enqueue', (string) $refusal->payload);

        // Nothing booked — the refusal is a record, not a fallback.
        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    /**
     * The companion to the test above: with NO job, the flag being off is the
     * ordinary disabled-lane path and must stay completely silent. A row here
     * would fire once per shift close on every tenant while the lane is off.
     */
    public function test_the_disabled_lane_writes_no_row_when_nothing_was_ever_enqueued(): void
    {
        config()->set('treasury.shift_variance_gl_enabled', false);

        $shiftId = (string) Str::uuid();

        app(CompanyContext::class)->clear();

        /** @var PostShiftCashVarianceAdjustment $listener */
        $listener = app(PostShiftCashVarianceAdjustment::class);
        $listener->handle($this->cashCountEvent($shiftId));

        $this->assertSame(0, $this->refusalCount($shiftId));
        $this->assertSame(0, $this->deadLetterCount($shiftId));
        $this->assertSame(0, RepositoryAdjustment::query()->count());
    }

    /**
     * The worker reality: no CompanyContext, no request, no authenticated user.
     * Driven through the REAL queued job object rather than by calling handle()
     * on a hand-built listener, so the container resolution and the job binding
     * are part of what is asserted.
     *
     * Gate round 1 P3-5 — this docblock used to claim it covered "the event's
     * serialization shape". It did not: `Queue::fake()` records the job object in
     * memory without serializing it. The claim is made true rather than deleted:
     * the job is now round-tripped through `serialize()`/`unserialize()` before
     * it runs, which is what a real connection does to it. (The `sync` test
     * below independently exercises the framework's own serialize path.)
     */
    public function test_the_queued_listener_books_the_variance_with_no_company_context_bound(): void
    {
        Queue::fake();

        $shiftId = (string) Str::uuid();
        event($this->cashCountEvent($shiftId));

        $pushed = $this->pushedListenerJob();

        // The worker never sees the in-memory object — it sees whatever survived
        // the payload. Anything unserializable on the event would die here.
        $revived = unserialize(serialize($pushed));
        $this->assertInstanceOf(CallQueuedListener::class, $revived);
        $job = $revived;

        app(CompanyContext::class)->clear();
        $job->job = new FakeJob;
        $job->handle(app());

        $document = RepositoryAdjustment::query()->where('pos_shift_id', $shiftId)->firstOrFail();
        $this->assertSame($this->derivedDocumentId($shiftId), $document->id);
        $this->assertSame(0, bccomp((string) $document->amount, '5.000', 3));

        $entry = JournalEntry::query()->whereKey($document->journal_entry_id)->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame('395.000', $this->till->fresh()?->balance);
        $this->assertSame(0, $this->deadLetterCount($shiftId));
    }

    // -------------------------------------------------------------------------
    // (b) a fault is retried, and a retry never double-posts
    // -------------------------------------------------------------------------

    public function test_a_retryable_fault_is_returned_to_the_worker_instead_of_being_swallowed(): void
    {
        $shiftId = (string) Str::uuid();
        $this->unbalanceNextEntry = true;

        try {
            $this->runOnWorker($this->cashCountEvent($shiftId));
            $this->fail('The GL refusal must reach the worker so the attempt can be retried.');
        } catch (UnbalancedJournalEntryPostException $e) {
            $this->assertStringContainsString('Cannot post unbalanced journal entry', $e->getMessage());
        }

        // Nothing booked, nothing given up on yet: a retry is still pending, so
        // writing the dead letter here would be a false alarm.
        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame(0, $this->deadLetterCount($shiftId));
        $this->assertSame(0, $this->refusalCount($shiftId));
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    public function test_a_retry_after_a_failed_attempt_books_exactly_one_of_each_artifact(): void
    {
        $shiftId = (string) Str::uuid();
        $event = $this->cashCountEvent($shiftId);

        // Attempt 1 — the GL refuses; the whole adjustment transaction rolls back.
        $this->unbalanceNextEntry = true;
        try {
            $this->runOnWorker($event);
            $this->fail('Attempt 1 was expected to fail.');
        } catch (UnbalancedJournalEntryPostException) {
            // handed back to the worker
        }

        // Attempt 2 — the worker re-runs the same event.
        $this->unbalanceNextEntry = false;
        $this->runOnWorker($event);

        $this->assertSame(1, RepositoryAdjustment::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->count());
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', 'repository_adjustment')
                ->where('status', JournalEntryStatus::Posted->value)
                ->count(),
        );
        $this->assertSame(1, $this->bookedCount($shiftId));
        $this->assertSame(0, $this->deadLetterCount($shiftId));
        $this->assertSame('395.000', $this->till->fresh()?->balance);
    }

    /**
     * The nastier retry: the attempt COMMITTED and then the worker died before
     * acknowledging the job, so the same event is delivered again over a ledger
     * that already holds the booking. `journal_entries` has no global
     * (source_type, source_id) uniqueness, so this is guarded explicitly — by
     * the UUIDv5-derived document id + firstOrCreate, and by the partial unique
     * index scoped to `source_type = 'repository_adjustment' AND status =
     * 'posted'`.
     */
    public function test_a_retry_after_a_committed_attempt_does_not_double_post(): void
    {
        $shiftId = (string) Str::uuid();
        $event = $this->cashCountEvent($shiftId);

        $this->runOnWorker($event);
        $this->runOnWorker($event);

        $this->assertSame(1, RepositoryAdjustment::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->count());
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', 'repository_adjustment')
                ->where('status', JournalEntryStatus::Posted->value)
                ->count(),
        );

        // Exactly ONE booked audit event, and the till moved exactly once.
        $this->assertSame(1, $this->bookedCount($shiftId));
        $this->assertSame('395.000', $this->till->fresh()?->balance);
        $this->assertSame(0, $this->deadLetterCount($shiftId));
        $this->assertSame(0, $this->refusalCount($shiftId));
    }

    // -------------------------------------------------------------------------
    // (c) the dead letter
    // -------------------------------------------------------------------------

    public function test_an_exhausted_job_dead_letters_the_variance_with_recoverable_details(): void
    {
        $shiftId = (string) Str::uuid();
        $event = $this->cashCountEvent($shiftId);

        app(CompanyContext::class)->clear();

        /** @var PostShiftCashVarianceAdjustment $listener */
        $listener = app(PostShiftCashVarianceAdjustment::class);
        $listener->failed(
            $event,
            UnbalancedJournalEntryPostException::forChokepoint('8.000', '5.000'),
        );

        $deadLetter = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_dead_lettered')
            ->where('aggregate_id', $shiftId)
            ->first();

        $this->assertNotNull($deadLetter, 'An exhausted variance job must leave a durable dead-letter record.');

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $deadLetter->payload, true);

        $this->assertSame('unbalanced_journal_entry', $payload['reason']);
        $this->assertSame(UnbalancedJournalEntryPostException::class, $payload['exception']);

        // Gate round 1 P3-1 — `tries` is the BUDGET; it must never be read as
        // the attempt count. On this path the framework rebuilds the listener
        // with no job, so the count is genuinely unknowable here and must be
        // reported as unknown rather than invented.
        $this->assertSame(3, $payload['tries']);
        $this->assertNull($payload['attempts']);
        $this->assertSame('queue_failed_handler', $payload['dead_letter_source']);

        $this->assertSame($shiftId, $payload['shift_id']);
        $this->assertSame($event->zReportId, $payload['z_report_id']);
        $this->assertSame($event->terminalId, $payload['terminal_id']);
        $this->assertSame($this->tenant->id, $payload['tenant_id']);
        $this->assertSame($this->company->id, $payload['company_id']);
        $this->assertSame('TND', $payload['currency']);
        $this->assertSame('-5.0000', $payload['aggregate_variance']);
        $this->assertSame(
            VarianceDirection::fromSignedAmount('-5.0000')->value,
            $payload['variance_direction'],
        );

        // The document a manual re-book MUST address, so an operator, a retry
        // and a later offline replay all land on the same row.
        $this->assertSame($this->derivedDocumentId($shiftId), $payload['adjustment_document_id']);

        // The counted detail survives the death of the event object.
        $this->assertCount(1, $payload['tender_breakdown']);
        $this->assertSame($this->cashMethod->id, $payload['tender_breakdown'][0]['payment_method_id']);
        $this->assertSame('120.0000', $payload['tender_breakdown'][0]['expected_amount']);
        $this->assertSame('115.0000', $payload['tender_breakdown'][0]['actual_amount']);
        $this->assertSame('-5.0000', $payload['tender_breakdown'][0]['variance_amount']);

        // And the pre-R-8 refusal row is still written under its own reason, so
        // every existing query and alert on the skipped-event type keeps working.
        $refusal = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_skipped')
            ->where('aggregate_id', $shiftId)
            ->first();
        $this->assertNotNull($refusal);
        $this->assertStringContainsString('unbalanced_journal_entry', (string) $refusal->payload);
    }

    public function test_a_crash_dead_letters_under_the_generic_reason_not_the_gl_one(): void
    {
        $shiftId = (string) Str::uuid();

        app(CompanyContext::class)->clear();

        /** @var PostShiftCashVarianceAdjustment $listener */
        $listener = app(PostShiftCashVarianceAdjustment::class);
        $listener->failed($this->cashCountEvent($shiftId), new \RuntimeException('worker died'));

        $deadLetter = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_dead_lettered')
            ->where('aggregate_id', $shiftId)
            ->firstOrFail();

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $deadLetter->payload, true);
        $this->assertSame('exception', $payload['reason']);
        $this->assertSame('worker died', $payload['message']);
    }

    // -------------------------------------------------------------------------
    // the never-block invariant on a connection that cannot retry
    // -------------------------------------------------------------------------

    /**
     * `sync` is not a worker — it is the shift-close request stack. Throwing
     * there would be the 500-on-a-successful-close this listener exists to
     * avoid, so the fault must dead-letter immediately instead.
     */
    public function test_a_connection_that_cannot_retry_dead_letters_instead_of_throwing(): void
    {
        $shiftId = (string) Str::uuid();
        $this->unbalanceNextEntry = true;

        app(CompanyContext::class)->clear();

        // No exception escapes the shift close.
        event($this->cashCountEvent($shiftId));

        $this->assertSame(1, $this->deadLetterCount($shiftId));
        $this->assertSame(1, $this->refusalCount($shiftId));

        $deadLetter = DB::table('audit_events')
            ->where('event_type', 'treasury.shift_variance_gl_dead_lettered')
            ->where('aggregate_id', $shiftId)
            ->firstOrFail();
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $deadLetter->payload, true);
        $this->assertSame('unbalanced_journal_entry', $payload['reason']);
        $this->assertSame('-5.0000', $payload['aggregate_variance']);

        // Gate round 1 P3-1 — exactly ONE attempt ran on a connection that
        // cannot retry, and the record must say so instead of implying three.
        $this->assertSame(1, $payload['attempts']);
        $this->assertSame(3, $payload['tries']);
        $this->assertSame('listener', $payload['dead_letter_source']);

        // Fail-CLOSED: nothing half-written.
        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame('400.000', $this->till->fresh()?->balance);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Run the listener the way a worker does: a resolved instance, a real
     * (non-sync) queue job bound to it, and no CompanyContext.
     */
    private function runOnWorker(CashCountRecorded $event): void
    {
        app(CompanyContext::class)->clear();

        /** @var PostShiftCashVarianceAdjustment $listener */
        $listener = app(PostShiftCashVarianceAdjustment::class);
        $listener->setJob(new FakeJob);
        $listener->handle($event);
    }

    private function pushedListenerJob(): CallQueuedListener
    {
        $found = null;

        Queue::assertPushed(
            CallQueuedListener::class,
            function (CallQueuedListener $job) use (&$found): bool {
                if ($job->class === PostShiftCashVarianceAdjustment::class) {
                    $found = $job;

                    return true;
                }

                return false;
            },
        );

        $this->assertInstanceOf(CallQueuedListener::class, $found);

        return $found;
    }

    /**
     * Add a third leg to the shift-variance adjustment Draft so the GL posting
     * chokepoint refuses it. Real machinery — `JournalLineObserver` permits a
     * line on an unchained Draft — so nothing here is mocked.
     */
    private function registerUnbalancingHook(): void
    {
        JournalLine::created(function (JournalLine $line): void {
            if ($this->injectingLine || ! $this->unbalanceNextEntry) {
                return;
            }

            $entry = JournalEntry::find($line->journal_entry_id);
            if ($entry === null
                || $entry->source_type !== 'repository_adjustment'
                || $entry->status !== JournalEntryStatus::Draft) {
                return;
            }

            $this->injectingLine = true;
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line->account_id,
                'debit' => '3.000',
                'credit' => '0',
                'description' => 'Injected unbalancing leg',
                'line_order' => 99,
            ]);
            $this->injectingLine = false;
        });
    }

    private function cashCountEvent(string $shiftId): CashCountRecorded
    {
        $variance = bcsub('115.0000', '120.0000', 4);

        return new CashCountRecorded(
            zReportId: (string) Str::uuid(),
            shiftId: $shiftId,
            terminalId: (string) Str::uuid(),
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            cashierId: $this->cashier->id,
            managerOverrideBy: null,
            blindCountUsed: false,
            currencyCode: 'TND',
            aggregateVariance: new VarianceAmount(amount: $variance, currencyCode: 'TND'),
            varianceDirection: VarianceDirection::fromSignedAmount($variance),
            severity: VarianceSeverity::Warning,
            tenderBreakdown: [new CashCountBreakdownDTO(
                paymentMethodId: $this->cashMethod->id,
                currencyCode: 'TND',
                expectedAmount: '120.0000',
                actualAmount: '115.0000',
                varianceAmount: $variance,
                varianceDirection: VarianceDirection::fromSignedAmount($variance),
                transactionCount: 1,
            )],
            descriptionCode: 'pos.cash_count.warning',
            descriptionParams: [],
            recordedAt: now()->toIso8601String(),
        );
    }

    private function derivedDocumentId(string $shiftId): string
    {
        return Uuid::uuid5(
            self::DOCUMENT_ID_NAMESPACE,
            'urn:autoerp:pos-shift-cash-variance:'.$shiftId,
        )->toString();
    }

    private function deadLetterCount(string $shiftId): int
    {
        return $this->auditCount('treasury.shift_variance_gl_dead_lettered', $shiftId);
    }

    private function refusalCount(string $shiftId): int
    {
        return $this->auditCount('treasury.shift_variance_gl_skipped', $shiftId);
    }

    private function bookedCount(string $shiftId): int
    {
        return $this->auditCount('treasury.shift_variance_gl_booked', $shiftId);
    }

    private function auditCount(string $eventType, string $shiftId): int
    {
        return DB::table('audit_events')
            ->where('event_type', $eventType)
            ->where('aggregate_id', $shiftId)
            ->count();
    }

    private function accountFor(SystemAccountPurpose $purpose): Account
    {
        return Account::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('company_id', $this->company->id)
            ->where('system_purpose', $purpose->value)
            ->firstOrFail();
    }
}
