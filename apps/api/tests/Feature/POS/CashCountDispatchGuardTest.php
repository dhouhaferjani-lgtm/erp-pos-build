<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\CashCountDispatcher;
use App\Modules\POS\Domain\DTOs\CashCountBreakdownDTO;
use App\Modules\POS\Domain\DTOs\VarianceAmount;
use App\Modules\POS\Domain\Events\CashCountRecorded;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * R-8 gate round 1, finding P2-1 — a consumer fault must never 500 a sealed
 * Z-report close.
 *
 * By the time `CashCountRecorded` is raised the Z report is committed and
 * hash-chained and the shift is closed. `Dispatcher::dispatch()` has no
 * try/catch, `DatabaseTransactionsManager::commit()` runs `afterCommit`
 * callbacks bare, and `ReportController::generateZReport()` catches only four POS
 * domain types — so before this guard, anything a consumer threw became an HTTP
 * 500 on a document that had already succeeded, which an offline-first device
 * cannot retry (a re-sync short-circuits at `200 duplicate`).
 *
 * Two distinct throw sources are pinned here:
 *   1. the QUEUE PUSH, which R-8 introduced by making
 *      `PostShiftCashVarianceAdjustment` `ShouldQueue` — the dispatcher calls
 *      `$connection->pushOn()` inline, so an unreachable/misconfigured queue
 *      propagates out of `event()`;
 *   2. a SYNCHRONOUS LISTENER throwing — pre-existing, and reachable today via
 *      `OpenFraudAlertForShiftVariance`, which writes `fraud_alerts` and may
 *      dispatch email with neither wrapped.
 *
 * Both now degrade to a durable `pos.cash_count_consumers_failed` audit row.
 */
final class CashCountDispatchGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

        // The queued treasury consumer is the throw source under test, and it
        // ships disabled (gate finding I1) — `shouldQueue()` would short-circuit
        // before the push and the hazard would be invisible. Opt in explicitly,
        // exactly as the treasury suite does.
        config()->set('treasury.shift_variance_gl_enabled', true);
    }

    public function test_an_unreachable_queue_at_push_time_does_not_reach_the_caller(): void
    {
        // The queue connection the dispatcher would push onto does not resolve.
        // QueueManager::resolve() throws before any SQL runs, so this models the
        // push frame faithfully without poisoning the surrounding transaction the
        // way a failed INSERT would on PostgreSQL.
        config()->set('queue.default', 'r8-unreachable-connection');

        $shiftId = (string) Str::uuid();

        // No exception escapes — this is the whole invariant.
        app(CashCountDispatcher::class)->dispatch($this->cashCountEvent($shiftId));

        $row = DB::table('audit_events')
            ->where('event_type', 'pos.cash_count_consumers_failed')
            ->where('aggregate_id', $shiftId)
            ->first();

        $this->assertNotNull($row, 'A consumer fault on a sealed close must leave a durable record.');

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true);

        // Everything needed to re-drive the variance by hand, because the event
        // object dies with the request.
        $this->assertSame($this->tenant->id, $payload['tenant_id']);
        $this->assertSame($this->company->id, $payload['company_id']);
        $this->assertSame($shiftId, $payload['shift_id']);
        $this->assertSame('TND', $payload['currency']);
        $this->assertSame('-5.0000', $payload['aggregate_variance']);
        $this->assertCount(1, $payload['tender_breakdown']);
        $this->assertSame('115.0000', $payload['tender_breakdown'][0]['actual_amount']);
        $this->assertStringContainsString('r8-unreachable-connection', (string) $payload['message']);
    }

    /**
     * The pre-existing half: a synchronous consumer throwing. Closing this is a
     * deliberate, disclosed behaviour change beyond R-8's own defect — the
     * invariant belongs to this seam, not to one listener, and a fraud alert
     * failing must not un-close a shift either.
     */
    public function test_a_throwing_synchronous_consumer_does_not_reach_the_caller(): void
    {
        Event::listen(CashCountRecorded::class, function (): void {
            throw new RuntimeException('fraud alert store is down');
        });

        $shiftId = (string) Str::uuid();

        app(CashCountDispatcher::class)->dispatch($this->cashCountEvent($shiftId));

        $row = DB::table('audit_events')
            ->where('event_type', 'pos.cash_count_consumers_failed')
            ->where('aggregate_id', $shiftId)
            ->firstOrFail();

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true);
        $this->assertSame(RuntimeException::class, $payload['exception']);
        $this->assertSame('fraud alert store is down', $payload['message']);
    }

    public function test_a_healthy_dispatch_writes_no_failure_row(): void
    {
        $shiftId = (string) Str::uuid();

        app(CashCountDispatcher::class)->dispatch($this->cashCountEvent($shiftId));

        $this->assertSame(
            0,
            DB::table('audit_events')
                ->where('event_type', 'pos.cash_count_consumers_failed')
                ->where('aggregate_id', $shiftId)
                ->count(),
        );
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
                paymentMethodId: (string) Str::uuid(),
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
}
