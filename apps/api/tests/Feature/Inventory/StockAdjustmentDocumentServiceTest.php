<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\CreateStockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentLineInput;
use App\Modules\Inventory\Application\DTOs\UpdateStockAdjustmentData;
use App\Modules\Inventory\Application\Services\StockAdjustmentDocumentService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Domain\Exceptions\AdjustmentAlreadyCorrectedException;
use App\Modules\Inventory\Domain\Exceptions\CannotCorrectACorrectionException;
use App\Modules\Inventory\Domain\Exceptions\LineTenantMismatchException;
use App\Modules\Inventory\Domain\Exceptions\StockAdjustmentStateException;
use App\Modules\Inventory\Domain\Exceptions\StockMovedSinceAuthoringException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * DPA V7 / T6 — the `stock_adjustments` document lifecycle.
 *
 * Every assertion lands on the persisted movement / stock_level / line
 * back-link, because a document that returns the right object while writing the
 * wrong ledger is exactly the failure mode this lane exists to close.
 */
final class StockAdjustmentDocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Product $productA;

    private Product $productB;

    private StockAdjustmentDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Doc Tenant',
            'slug' => 'doc-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Doc Co',
            'legal_name' => 'Doc Co LLC',
            'tax_id' => 'TAX-DOC',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Doc User',
            'email' => 'doc@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'DOC-01',
            'name' => 'Doc Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->productA = $this->product('DOC-A');
        $this->productB = $this->product('DOC-B');

        $this->service = app(StockAdjustmentDocumentService::class);
    }

    // ------------------------------------------------------------------ draft

    public function test_a_draft_writes_no_movement_and_has_no_number(): void
    {
        $this->seedStock($this->productA, '10.0000');

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);

        $this->assertSame(StockAdjustmentStatus::Draft, $adjustment->status);
        $this->assertNull($adjustment->adjustment_number);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('10.0000', (string) $this->level($this->productA)->quantity);
        $this->assertCount(1, $adjustment->lines);
        $this->assertNull($adjustment->lines->first()?->movement_id);
    }

    public function test_a_draft_refuses_a_reason_sign_mismatch_derived_from_the_enum(): void
    {
        $this->seedStock($this->productA, '10.0000');

        $this->expectException(InvalidArgumentException::class);
        $this->draft([
            // `damage` is `out` per MovementReason::getMovementType().
            $this->line($this->productA, MovementReason::Damage, '2.0000', '10.0000'),
        ]);
    }

    public function test_an_idempotent_replay_returns_the_existing_document(): void
    {
        $this->seedStock($this->productA, '10.0000');

        $first = $this->draft(
            [$this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000')],
            idempotencyKey: 'ADJ-IDEM-1',
        );
        $second = $this->draft(
            [$this->line($this->productA, MovementReason::AdjustmentPositive, '9.0000', '10.0000')],
            idempotencyKey: 'ADJ-IDEM-1',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StockAdjustment::count());
        $this->assertCount(1, $second->refresh()->lines);
        $this->assertSame('2.0000', (string) $second->lines->first()?->delta_quantity);
    }

    // ------------------------------------------------------------------- post

    public function test_posting_writes_one_movement_per_line_and_back_links_them(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $this->seedStock($this->productB, '20.0000');

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
            $this->line($this->productB, MovementReason::AdjustmentNegative, '-5.0000', '20.0000'),
        ]);

        $posted = $this->service->post($adjustment->id, $this->user->id);

        $this->assertSame(StockAdjustmentStatus::Posted, $posted->status);
        $this->assertMatchesRegularExpression('/^ADJ-\d{4}-\d{4}$/', (string) $posted->adjustment_number);
        $this->assertSame($this->user->id, $posted->posted_by_user_id);
        $this->assertNotNull($posted->posted_at);
        $this->assertNull($posted->stale_acknowledged_at);
        $this->assertNull($posted->reservations_ignored_at);

        $this->assertSame('12.0000', (string) $this->level($this->productA)->quantity);
        $this->assertSame('15.0000', (string) $this->level($this->productB)->quantity);

        $posted->load('lines');
        $this->assertCount(2, $posted->lines);

        foreach ($posted->lines as $line) {
            $this->assertNotNull($line->movement_id);
            $this->assertNotNull($line->quantity_before);
            $this->assertNotNull($line->quantity_after);

            $movement = StockMovement::findOrFail($line->movement_id);
            $this->assertSame('stock_adjustment', $movement->reference_type);
            $this->assertSame($posted->id, $movement->reference_id);
            $this->assertSame($posted->adjustment_number, $movement->reference);
            $this->assertSame((string) $line->delta_quantity, (string) $movement->quantity);
        }

        $this->assertSame(2, StockMovement::count());
    }

    public function test_a_second_post_is_refused_by_the_state_machine(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);

        $this->service->post($adjustment->id, $this->user->id);

        try {
            $this->service->post($adjustment->id, $this->user->id);
            $this->fail('Expected StockAdjustmentStateException.');
        } catch (StockAdjustmentStateException $e) {
            $this->assertSame(StockAdjustmentStatus::Posted, $e->currentStatus);
            $this->assertSame('post', $e->attemptedAction);
            $this->assertSame([], $e->allowedValues());
        }

        // Exactly one movement — the aggregate was not applied twice.
        $this->assertSame(1, StockMovement::count());
        $this->assertSame('12.0000', (string) $this->level($this->productA)->quantity);
    }

    public function test_the_line_movement_id_partial_unique_refuses_a_second_back_link(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);
        $posted = $this->service->post($adjustment->id, $this->user->id);
        $movementId = $posted->refresh()->lines->first()?->movement_id;
        $this->assertNotNull($movementId);

        // The DB-level "posted at most once per line" guard that survives a
        // retried job, independent of the state machine (D10).
        $second = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '1.0000', '12.0000'),
        ]);

        $this->expectException(QueryException::class);
        $second->lines()->first()?->forceFill(['movement_id' => $movementId])->save();
    }

    public function test_a_staleness_refusal_on_a_later_line_leaves_nothing_written(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $this->seedStock($this->productB, '20.0000');

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
            // Authored against 20 but the row will hold 25 by post time.
            $this->line($this->productB, MovementReason::AdjustmentNegative, '-5.0000', '20.0000'),
        ]);

        app(StockAdjustmentService::class)->receive(
            productId: $this->productB->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'INTERLEAVED',
            userId: $this->user->id,
        );

        $this->expectException(StockMovedSinceAuthoringException::class);

        try {
            $this->service->post($adjustment->id, $this->user->id);
        } finally {
            // WHOLE-DOCUMENT refusal: line A must not have posted either. A
            // half-posted document has no representable state (D15).
            $this->assertSame('10.0000', (string) $this->level($this->productA)->quantity);
            $this->assertSame(StockAdjustmentStatus::Draft, $adjustment->refresh()->status);
            $this->assertNull($adjustment->adjustment_number);
            $this->assertNull($adjustment->refresh()->lines->first()?->movement_id);
            $this->assertSame(
                0,
                StockMovement::where('reference_type', 'stock_adjustment')->count(),
                'No adjustment movement may survive a refused post.'
            );
        }
    }

    public function test_acknowledging_staleness_stamps_the_audit_columns(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);

        app(StockAdjustmentService::class)->receive(
            productId: $this->productA->id,
            locationId: $this->warehouse->id,
            quantity: '5.0000',
            reference: 'INTERLEAVED',
            userId: $this->user->id,
        );

        $posted = $this->service->post($adjustment->id, $this->user->id, acknowledgeStale: true);

        $this->assertNotNull($posted->stale_acknowledged_at);
        $this->assertSame($this->user->id, $posted->stale_acknowledged_by_user_id);
        // The reservation override was neither sent nor needed.
        $this->assertNull($posted->reservations_ignored_at);
        // The DELTA is applied to the fresh baseline; the LINE keeps the permanent
        // evidence of what was actually posted.
        $this->assertSame('17.0000', (string) $this->level($this->productA)->quantity);
        $line = $posted->refresh()->lines->first();
        $this->assertSame('15.0000', (string) $line?->quantity_before);
        $this->assertSame('17.0000', (string) $line?->quantity_after);
        $this->assertSame('10.0000', (string) $line?->observed_before);
    }

    public function test_ignoring_reservations_stamps_its_own_audit_columns(): void
    {
        $level = $this->seedStock($this->productA, '5.0000');
        $level->update(['reserved' => '3.0000']);

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentNegative, '-4.0000', '5.0000'),
        ]);

        $posted = $this->service->post($adjustment->id, $this->user->id, ignoreReservations: true);

        $this->assertNotNull($posted->reservations_ignored_at);
        $this->assertSame($this->user->id, $posted->reservations_ignored_by_user_id);
        $this->assertSame('1.0000', (string) $this->level($this->productA)->quantity);
        $this->assertSame('-2.0000', $this->level($this->productA)->getAvailableQuantity());
        // The OTHER override must not be claimed.
        $this->assertNull($posted->stale_acknowledged_at);
    }

    /**
     * GATE M-5. The audit columns record an override that was RELIED ON, not a
     * flag that was merely sent — a header claiming the operator overrode the
     * reservation guard when they never met it is a false record.
     */
    public function test_an_override_flag_that_was_never_needed_is_not_stamped(): void
    {
        $this->seedStock($this->productA, '20.0000');

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentNegative, '-2.0000', '20.0000'),
        ]);

        // Both flags sent, but nothing moved and nothing is reserved, so neither
        // guard had anything to refuse.
        $posted = $this->service->post(
            $adjustment->id,
            $this->user->id,
            acknowledgeStale: true,
            ignoreReservations: true,
        );

        $this->assertNull($posted->stale_acknowledged_at);
        $this->assertNull($posted->stale_acknowledged_by_user_id);
        $this->assertNull($posted->reservations_ignored_at);
        $this->assertNull($posted->reservations_ignored_by_user_id);
        $this->assertSame('18.0000', (string) $this->level($this->productA)->quantity);
    }

    /**
     * And a multi-lot document must not stamp the staleness override just because
     * its own arithmetic offset a later line's anchor — the rebase is compared
     * against, not around.
     */
    public function test_a_multi_line_document_does_not_stamp_staleness_for_its_own_arithmetic(): void
    {
        $this->seedStock($this->productA, '20.0000');
        $this->seedStock($this->productB, '20.0000');

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentNegative, '-2.0000', '20.0000'),
            $this->line($this->productB, MovementReason::AdjustmentPositive, '3.0000', '20.0000'),
        ]);

        $posted = $this->service->post($adjustment->id, $this->user->id, acknowledgeStale: true);

        $this->assertNull($posted->stale_acknowledged_at);
    }

    public function test_line_tenant_mismatch_is_refused_before_any_lock_is_taken(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);

        $other = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        DB::table('products')->where('id', $this->productA->id)->update(['tenant_id' => $other->id]);

        try {
            $this->service->post($adjustment->id, $this->user->id);
            $this->fail('Expected LineTenantMismatchException.');
        } catch (LineTenantMismatchException $e) {
            $this->assertSame($this->productA->id, $e->productId);
            $this->assertSame($this->tenant->id, $e->expectedTenantId);
            $this->assertSame($other->id, $e->foundTenantId);
        }

        $this->assertSame(0, StockMovement::count());
    }

    // --------------------------------------------- D14 / D14a: the lock tuple

    /**
     * D14a's `[PG]` assertion: exactly ONE advisory lock per product per post,
     * with no SECOND key minted by the nested per-line acquires.
     *
     * The `pid = pg_backend_pid()` predicate is required — `pg_locks` is
     * cluster-wide, so a parallel test process or another tenant's connection
     * would otherwise make this flaky. The COUNT is the assertion, not the key:
     * ProductCostLock hashes its pre-hash string through `hashtext()`, so the
     * objid cannot be asserted by name.
     *
     * Advisory locks taken with `pg_advisory_xact_lock` are held to the end of
     * the OUTERMOST transaction — which, under RefreshDatabase, is the test's own
     * wrapper — so they are still observable after post() returns.
     */
    public function test_a_multi_product_post_takes_exactly_one_advisory_lock_per_product(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('ProductCostLock no-ops on non-pgsql drivers.');
        }

        $this->seedStock($this->productA, '10.0000');
        $this->seedStock($this->productB, '20.0000');

        $before = $this->advisoryLockCount();

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
            $this->line($this->productB, MovementReason::AdjustmentNegative, '-5.0000', '20.0000'),
        ]);
        $this->service->post($adjustment->id, $this->user->id);

        $this->assertSame(
            2,
            $this->advisoryLockCount() - $before,
            'One advisory lock per DISTINCT line product — a second key would mean post() and the '
            .'writer built different pre-hash strings, silently disarming the deadlock defence.'
        );
    }

    /**
     * The nested per-line acquires must be RE-ENTRANT on the already-held xact
     * locks, which is what makes the up-front sorted acquire a sufficient
     * deadlock defence.
     *
     * Reduced fidelity, stated: a true AB-BA deadlock needs two connections, and
     * RefreshDatabase's wrapper transaction makes a second connection unable to
     * see this test's fixtures. What IS asserted is the property the defence
     * rests on — posting [A,B] and then [B,A] inside one transaction mints no
     * additional advisory keys, so no unsorted accumulation is possible.
     */
    public function test_overlapping_posts_in_opposite_orders_mint_no_additional_advisory_keys(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('ProductCostLock no-ops on non-pgsql drivers.');
        }

        $this->seedStock($this->productA, '50.0000');
        $this->seedStock($this->productB, '50.0000');

        $before = $this->advisoryLockCount();

        $first = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '1.0000', '50.0000'),
            $this->line($this->productB, MovementReason::AdjustmentPositive, '1.0000', '50.0000'),
        ]);
        $this->service->post($first->id, $this->user->id);

        $second = $this->draft([
            $this->line($this->productB, MovementReason::AdjustmentPositive, '1.0000', '51.0000'),
            $this->line($this->productA, MovementReason::AdjustmentPositive, '1.0000', '51.0000'),
        ]);
        $this->service->post($second->id, $this->user->id);

        $this->assertSame(2, $this->advisoryLockCount() - $before);
        $this->assertSame('52.0000', (string) $this->level($this->productA)->quantity);
        $this->assertSame('52.0000', (string) $this->level($this->productB)->quantity);
    }

    private function advisoryLockCount(): int
    {
        return (int) DB::scalar(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"
        );
    }

    // ----------------------------------------------------------------- cancel

    public function test_cancelling_a_draft_writes_nothing_and_cancelling_a_posted_document_is_refused(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $draft = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);

        $cancelled = $this->service->cancel($draft->id, $this->user->id, 'miscounted');

        $this->assertSame(StockAdjustmentStatus::Cancelled, $cancelled->status);
        $this->assertSame('miscounted', $cancelled->cancellation_reason);
        $this->assertSame($this->user->id, $cancelled->cancelled_by_user_id);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('10.0000', (string) $this->level($this->productA)->quantity);

        $posted = $this->service->post(
            $this->draft([$this->line($this->productA, MovementReason::AdjustmentPositive, '1.0000', '10.0000')])->id,
            $this->user->id,
        );

        $this->expectException(StockAdjustmentStateException::class);
        $this->service->cancel($posted->id, $this->user->id);
    }

    public function test_posting_a_cancelled_document_is_refused(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $draft = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);
        $this->service->cancel($draft->id, $this->user->id);

        $this->expectException(StockAdjustmentStateException::class);
        $this->service->post($draft->id, $this->user->id);
    }

    // ---------------------------------------------------------------- correct

    public function test_correcting_builds_a_negated_draft_with_remapped_reasons(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $this->seedStock($this->productB, '20.0000');

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
            $this->line($this->productB, MovementReason::Damage, '-5.0000', '20.0000'),
        ]);
        $posted = $this->service->post($adjustment->id, $this->user->id);

        $contra = $this->service->correct($posted->id, $this->user->id);

        $this->assertSame(StockAdjustmentStatus::Draft, $contra->status);
        $this->assertSame($posted->id, $contra->corrects_adjustment_id);
        $this->assertNull($contra->adjustment_number);

        $contra->load('lines');
        $byProduct = $contra->lines->keyBy('product_id');

        $this->assertSame('-2.0000', (string) $byProduct[$this->productA->id]->delta_quantity);
        $this->assertSame(MovementReason::AdjustmentNegative, $byProduct[$this->productA->id]->reason_code);

        // The FORCED remap: a positive `damage` is unrepresentable under the sign
        // CHECK, so `damage` and `write_off` both invert to `adjustment_positive`.
        $this->assertSame('5.0000', (string) $byProduct[$this->productB->id]->delta_quantity);
        $this->assertSame(MovementReason::AdjustmentPositive, $byProduct[$this->productB->id]->reason_code);

        // The contra is authored against what the ORIGINAL posted.
        $this->assertSame('12.0000', (string) $byProduct[$this->productA->id]->observed_before);
        $this->assertSame('15.0000', (string) $byProduct[$this->productB->id]->observed_before);
    }

    public function test_posting_a_correction_threads_reverses_movement_id_through_the_seam(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);
        $posted = $this->service->post($adjustment->id, $this->user->id);
        $originalMovementId = $posted->refresh()->lines->first()?->movement_id;

        $contra = $this->service->correct($posted->id, $this->user->id);
        $postedContra = $this->service->post($contra->id, $this->user->id);

        $contraMovement = StockMovement::findOrFail($postedContra->refresh()->lines->first()?->movement_id);
        $this->assertSame($originalMovementId, $contraMovement->reverses_movement_id);
        $this->assertSame('10.0000', (string) $this->level($this->productA)->quantity);
    }

    public function test_double_correct_and_correcting_a_correction_are_both_refused(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $posted = $this->service->post(
            $this->draft([$this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000')])->id,
            $this->user->id,
        );

        $contra = $this->service->correct($posted->id, $this->user->id);

        try {
            $this->service->correct($posted->id, $this->user->id);
            $this->fail('Expected AdjustmentAlreadyCorrectedException.');
        } catch (AdjustmentAlreadyCorrectedException $e) {
            $this->assertSame($contra->id, $e->correctionId);
        }

        $postedContra = $this->service->post($contra->id, $this->user->id);

        try {
            $this->service->correct($postedContra->id, $this->user->id);
            $this->fail('Expected CannotCorrectACorrectionException.');
        } catch (CannotCorrectACorrectionException $e) {
            $this->assertSame($posted->id, $e->correctsAdjustmentId);
        }
    }

    public function test_correcting_a_draft_is_refused_by_the_state_machine(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $draft = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
        ]);

        $this->expectException(StockAdjustmentStateException::class);
        $this->service->correct($draft->id, $this->user->id);
    }

    // ------------------------------------------------------------ updateDraft

    public function test_update_draft_replaces_the_line_set_and_re_anchors_observed_before(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $this->seedStock($this->productB, '20.0000');

        $adjustment = $this->draft([
            $this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000'),
            $this->line($this->productB, MovementReason::AdjustmentNegative, '-5.0000', '20.0000'),
        ]);

        $updated = $this->service->updateDraft($adjustment->id, new UpdateStockAdjustmentData(
            note: 're-anchored',
            lines: [$this->line($this->productA, MovementReason::AdjustmentPositive, '7.0000', '15.0000')],
            noteProvided: true,
        ));

        $updated->load('lines');
        $this->assertCount(1, $updated->lines);
        $this->assertSame('re-anchored', $updated->note);
        $this->assertSame('7.0000', (string) $updated->lines->first()?->delta_quantity);
        $this->assertSame('15.0000', (string) $updated->lines->first()?->observed_before);
        $this->assertSame(StockAdjustmentStatus::Draft, $updated->status);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_update_draft_on_a_posted_document_is_refused(): void
    {
        $this->seedStock($this->productA, '10.0000');
        $posted = $this->service->post(
            $this->draft([$this->line($this->productA, MovementReason::AdjustmentPositive, '2.0000', '10.0000')])->id,
            $this->user->id,
        );

        try {
            $this->service->updateDraft($posted->id, new UpdateStockAdjustmentData(
                lines: [$this->line($this->productA, MovementReason::AdjustmentPositive, '9.0000', '12.0000')],
            ));
            $this->fail('Expected StockAdjustmentStateException.');
        } catch (StockAdjustmentStateException $e) {
            $this->assertSame('update', $e->attemptedAction);
        }

        $this->assertSame('2.0000', (string) $posted->refresh()->lines->first()?->delta_quantity);
    }

    // ----------------------------------------------------------- fixtures

    private function product(string $sku): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => false,
        ]);
    }

    /**
     * @param  list<StockAdjustmentLineInput>  $lines
     */
    private function draft(array $lines, ?string $idempotencyKey = null): StockAdjustment
    {
        return DB::transaction(fn (): StockAdjustment => $this->service->createDraft(new CreateStockAdjustmentData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->warehouse->id,
            createdByUserId: $this->user->id,
            lines: $lines,
            note: null,
            idempotencyKey: $idempotencyKey,
        )));
    }

    private function line(
        Product $product,
        MovementReason $reason,
        string $delta,
        string $observedBefore,
        ?string $batchUuid = null,
    ): StockAdjustmentLineInput {
        return new StockAdjustmentLineInput(
            productId: $product->id,
            variantId: null,
            batchUuid: $batchUuid,
            reasonCode: $reason,
            deltaQuantity: $delta,
            observedBefore: $observedBefore,
        );
    }

    private function seedStock(Product $product, string $quantity): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function level(Product $product): StockLevel
    {
        return StockLevel::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->firstOrFail();
    }
}
