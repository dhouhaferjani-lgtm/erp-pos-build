<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\InventoryCountingService;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Exceptions\CountingTransitionException;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingEvent;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Session B lane Q-2, gate r1 — the terminal-state probes.
 *
 * The r1 gate ruled that a `lockForUpdate()` which only SERIALIZES is not
 * enough: the lock WAIT is itself the window. A request that snapshotted the
 * counting while it was live, then blocked on the lock while another request
 * finalized (or cancelled) it, resumes holding the lock and writes into a
 * TERMINAL counting — whose variance has already been applied to stock and
 * which has no outgoing edge to repair through.
 *
 * Three executed probes, one per write path:
 *   A. `submitCount()`   wrote a quantity into a FINALIZED counting.
 *   B. `triggerThirdCount()` regressed `finalized -> count_3_in_progress`.
 *   C. `cancel()`        flipped a FINALIZED counting to `cancelled`.
 *
 * Each is now refused under the lock, on the LOCKED instance's status. The
 * tolerant forward-phase no-op in `checkPhaseCompletion()` is untouched: an
 * ordinary "another counter closed this phase first" is still a no-op, never a
 * refusal, so a live count is never rolled back.
 */
final class CountingTerminalStateGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    private InventoryCountingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Terminal Guard Tenant',
            'slug' => 'terminal-guard-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Terminal Guard Company',
            'legal_name' => 'Terminal Guard Company LLC',
            'tax_id' => 'TGD-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Terminal Guard User',
            'email' => 'terminal-guard-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['inventory.view', 'inventory.adjust']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-TGD-'.uniqid(),
            'name' => 'Terminal Guard Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'TGD-'.uniqid(),
            'name' => 'Terminal Guard Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $this->service = app(InventoryCountingService::class);
    }

    /**
     * PROBE A (BLOCKER-1) — the counter double-taps; between the two taps the
     * supervisor finalizes. The second tap blocked on the lock, so its
     * pre-transaction phase guard passed on a snapshot that is now obsolete.
     * It must be refused under the lock, before it writes anything.
     */
    public function test_probe_a_submit_count_into_a_finalized_counting_is_refused(): void
    {
        $counting = $this->activeCounting();
        $item = $this->item($counting);

        // The counter's SECOND tap captured its handle here, while the counting
        // was still count_1_in_progress.
        $staleItem = InventoryCountingItem::findOrFail($item->id);
        $staleItem->setRelation('counting', InventoryCounting::findOrFail($counting->id));

        // First tap lands and closes the phase; the supervisor finalizes.
        $this->service->submitCount(
            InventoryCountingItem::findOrFail($item->id),
            1,
            '12.0000',
            null,
            $this->user,
        );
        $this->service->finalize(InventoryCounting::findOrFail($counting->id), $this->user);
        $this->assertSame(CountingStatus::Finalized, $this->freshStatus($counting));

        $submittedEventsBefore = $this->countEvents($counting, InventoryCountingEvent::COUNT_SUBMITTED);

        try {
            $this->service->submitCount($staleItem, 1, '99.0000', null, $this->user);
            $this->fail('Expected the late submission into a FINALIZED counting to be refused.');
        } catch (CountingTransitionException $exception) {
            $this->assertSame(CountingStatus::Finalized, $exception->currentStatus);
        }

        $this->assertSame(
            '12.0000',
            InventoryCountingItem::findOrFail($item->id)->count_1_qty,
            'A finalized counting must not accept a new counted quantity.'
        );

        $this->assertSame(
            $submittedEventsBefore,
            $this->countEvents($counting, InventoryCountingEvent::COUNT_SUBMITTED),
            'The refused submission must not have written an audit row.'
        );

        $this->assertSame(CountingStatus::Finalized, $this->freshStatus($counting));
    }

    /**
     * A live phase race must STILL be tolerated — the terminal guard must not
     * become a blanket "re-assert the phase under the lock", which is the
     * rollback this lane exists to remove. Regression sentinel for the ruling
     * "policy right, implementation wrong".
     */
    public function test_a_forward_phase_race_is_still_a_no_op_and_keeps_the_count(): void
    {
        $counting = $this->activeCounting(requiresCount2: true);
        $itemA = $this->item($counting);
        $itemB = $this->item($counting, $this->secondProduct());

        $staleItemB = InventoryCountingItem::findOrFail($itemB->id);
        $staleItemB->setRelation('counting', InventoryCounting::findOrFail($counting->id));

        $this->service->submitCount(InventoryCountingItem::findOrFail($itemA->id), 1, '5.0000', null, $this->user);
        $this->service->submitCount(InventoryCountingItem::findOrFail($itemB->id), 1, '7.0000', null, $this->user);
        $this->assertSame(CountingStatus::Count2InProgress, $this->freshStatus($counting));

        // Non-terminal: accepted, quantity kept, no status write.
        $this->service->submitCount($staleItemB, 1, '9.0000', null, $this->user);

        $this->assertSame('9.0000', InventoryCountingItem::findOrFail($itemB->id)->count_1_qty);
        $this->assertSame(CountingStatus::Count2InProgress, $this->freshStatus($counting));
    }

    /**
     * PROBE B (BLOCKER-2) — `triggerThirdCount()` tested the STALE instance's
     * status (`:1006`) and had no lock at all. It regressed
     * `finalized -> count_3_in_progress`; the re-finalize that follows
     * double-fires InventoryCountingCompleted, the listener's applied-markers
     * skip every item, and the new unique index makes posting the corrected
     * quantities impossible — a stock correction silently lost.
     */
    public function test_probe_b_trigger_third_count_on_a_finalized_counting_is_refused(): void
    {
        $counting = $this->activeCounting(requiresCount3: true);
        $item = $this->item($counting);

        $this->service->submitCount(
            InventoryCountingItem::findOrFail($item->id),
            1,
            '12.0000',
            null,
            $this->user,
        );

        // The reviewer's handle, captured while the counting was pending_review.
        $staleHandle = InventoryCounting::findOrFail($counting->id);
        $this->assertSame(CountingStatus::PendingReview, $staleHandle->status);

        $this->service->finalize(InventoryCounting::findOrFail($counting->id), $this->user);
        $this->assertSame(CountingStatus::Finalized, $this->freshStatus($counting));

        try {
            $this->service->triggerThirdCount($staleHandle, [$item->id], $this->user);
            $this->fail('Expected trigger-third-count on a FINALIZED counting to be refused.');
        } catch (CountingTransitionException $exception) {
            $this->assertSame(CountingStatus::Finalized, $exception->currentStatus);
            $this->assertSame(CountingStatus::Count3InProgress, $exception->attemptedStatus);
        }

        $this->assertSame(
            CountingStatus::Finalized,
            $this->freshStatus($counting),
            'A finalized counting must not be regressed into a third count.'
        );

        $this->assertSame(
            ItemResolutionMethod::AutoAllMatch,
            InventoryCountingItem::findOrFail($item->id)->resolution_method,
            'The refused trigger must not have reset the resolved item back to pending.'
        );

        $this->assertSame(
            0,
            $this->countEvents($counting, InventoryCountingEvent::THIRD_COUNT_TRIGGERED),
            'The refused trigger must not have written an audit row.'
        );
    }

    /**
     * PROBE C (IMPORTANT-3) — `cancel()`'s terminal guard read the caller's
     * snapshot (`:1200`), so a stale handle cancelled a FINALIZED counting:
     * the stock moved, but the document says cancelled.
     */
    public function test_probe_c_cancel_of_a_finalized_counting_is_refused(): void
    {
        $counting = $this->activeCounting();
        $item = $this->item($counting);

        $this->service->submitCount(
            InventoryCountingItem::findOrFail($item->id),
            1,
            '12.0000',
            null,
            $this->user,
        );

        $staleHandle = InventoryCounting::findOrFail($counting->id);
        $this->assertSame(CountingStatus::PendingReview, $staleHandle->status);

        $this->service->finalize(InventoryCounting::findOrFail($counting->id), $this->user);
        $this->assertSame(CountingStatus::Finalized, $this->freshStatus($counting));

        try {
            $this->service->cancel($staleHandle, 'stale cancel', $this->user);
            $this->fail('Expected cancel of a FINALIZED counting to be refused.');
        } catch (CountingTransitionException $exception) {
            $this->assertSame(CountingStatus::Finalized, $exception->currentStatus);
            $this->assertSame(CountingStatus::Cancelled, $exception->attemptedStatus);
        }

        $this->assertSame(
            CountingStatus::Finalized,
            $this->freshStatus($counting),
            'Stock has already moved — the document must not read cancelled.'
        );

        $this->assertNull(
            ($counting->fresh() ?? $counting)->cancellation_reason,
            'The refused cancel must not have stamped a cancellation reason.'
        );

        $this->assertSame(
            0,
            $this->countEvents($counting, InventoryCountingEvent::COUNTING_CANCELLED),
            'The refused cancel must not have written an audit row.'
        );
    }

    /**
     * A live counting is still cancellable — the terminal guard must not have
     * turned cancel() into a no-op for the states it exists to serve.
     */
    public function test_a_live_counting_is_still_cancellable(): void
    {
        $counting = $this->activeCounting();
        $this->item($counting);

        $this->service->cancel($counting, 'no longer needed', $this->user);

        $this->assertSame(CountingStatus::Cancelled, $this->freshStatus($counting));
        $this->assertSame(1, $this->countEvents($counting, InventoryCountingEvent::COUNTING_CANCELLED));
    }

    /**
     * Structural sentinel (gate r1, item 6). A full two-connection proof that
     * PostgreSQL actually BLOCKS a second session is not available here:
     * RefreshDatabase wraps each test in a transaction, so the fixture rows are
     * invisible to any second connection, and every workaround (committed
     * fixtures with FK triggers disabled, or a truncation-based scratch schema)
     * is test infrastructure larger than the thing it proves. See the lane
     * report — serialization itself still rests on PostgreSQL's `FOR UPDATE`
     * semantics.
     *
     * What IS worth pinning, and what this does pin, is that all four mutating
     * paths still EMIT the row lock against `inventory_countings` inside their
     * transaction. That is the part a future refactor can silently drop, and
     * dropping it is exactly how this lane's defects were introduced.
     *
     * PostgreSQL only, and not as a convenience: `SQLiteGrammar::compileLock()`
     * returns `''` (vendor `Query/Grammars/SQLiteGrammar.php:31`), so on the
     * SQLite leg `lockForUpdate()` emits no SQL at all and the sentinel can
     * never observe the clause — it would fail on correct code. The rest of
     * this file is driver-agnostic and runs on both legs; only the SQL-text
     * assertion needs the real grammar.
     */
    public function test_every_mutating_counting_path_emits_the_header_row_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'FOR UPDATE is compiled away by SQLiteGrammar::compileLock(); this sentinel needs the PostgreSQL grammar.'
            );
        }

        // Each path is an ARRANGE (untraced) + ACT (traced) pair. Tracing only
        // the act matters: three of these four fixtures have to drive a real
        // submitCount() first, and submitCount() takes the lock itself — a
        // single traced closure would let its lock vouch for the path under
        // test. Proven against the pre-fix service (HEAD 1ec42fc43, which
        // locked submitCount/finalize only): the merged form got as far as
        // cancel() before failing — triggerThirdCount() passed on its
        // fixture's submitCount lock — while this form reds on
        // triggerThirdCount(), the first genuinely unlocked path.
        /** @var array<string, array{0: \Closure(): InventoryCounting, 1: \Closure(InventoryCounting): void}> $paths */
        $paths = [
            'submitCount' => [
                fn (): InventoryCounting => $this->activeCounting(),
                function (InventoryCounting $counting): void {
                    $item = $this->item($counting, $this->secondProduct());
                    $this->service->submitCount(
                        InventoryCountingItem::findOrFail($item->id),
                        1,
                        '12.0000',
                        null,
                        $this->user,
                    );
                },
            ],
            'finalize' => [
                function (): InventoryCounting {
                    $counting = $this->activeCounting();
                    $item = $this->item($counting, $this->secondProduct());
                    $this->service->submitCount(
                        InventoryCountingItem::findOrFail($item->id),
                        1,
                        '12.0000',
                        null,
                        $this->user,
                    );

                    return $counting;
                },
                function (InventoryCounting $counting): void {
                    $this->service->finalize(InventoryCounting::findOrFail($counting->id), $this->user);
                },
            ],
            'triggerThirdCount' => [
                function (): InventoryCounting {
                    $counting = $this->activeCounting(requiresCount3: true);
                    $item = $this->item($counting, $this->secondProduct());
                    $this->service->submitCount(
                        InventoryCountingItem::findOrFail($item->id),
                        1,
                        '12.0000',
                        null,
                        $this->user,
                    );
                    $counting->setRelation('items', $counting->items()->get());

                    return $counting;
                },
                function (InventoryCounting $counting): void {
                    $itemIds = $counting->items->pluck('id')->all();
                    $this->service->triggerThirdCount(
                        InventoryCounting::findOrFail($counting->id),
                        $itemIds,
                        $this->user,
                    );
                },
            ],
            'cancel' => [
                function (): InventoryCounting {
                    $counting = $this->activeCounting();
                    $this->item($counting, $this->secondProduct());

                    return $counting;
                },
                function (InventoryCounting $counting): void {
                    $this->service->cancel(
                        InventoryCounting::findOrFail($counting->id),
                        'lock sentinel',
                        $this->user,
                    );
                },
            ],
        ];

        foreach ($paths as $name => [$arrange, $act]) {
            $counting = $arrange();

            /** @var list<string> $statements */
            $statements = [];
            DB::listen(function (QueryExecuted $query) use (&$statements): void {
                $statements[] = strtolower($query->sql);
            });

            $act($counting);

            $locked = false;
            foreach ($statements as $sql) {
                if (str_contains($sql, 'from "inventory_countings"') && str_contains($sql, 'for update')) {
                    $locked = true;
                    break;
                }
            }

            $this->assertTrue(
                $locked,
                "{$name}() must re-read the counting header FOR UPDATE before it writes."
            );
        }
    }

    // --- Helpers ---

    private function freshStatus(InventoryCounting $counting): CountingStatus
    {
        return ($counting->fresh() ?? $counting)->status;
    }

    private function countEvents(InventoryCounting $counting, string $eventType): int
    {
        return InventoryCountingEvent::query()
            ->where('counting_id', $counting->id)
            ->where('event_type', $eventType)
            ->count();
    }

    private function secondProduct(): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'TGD2-'.uniqid(),
            'name' => 'Terminal Guard Product Two',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '1.000000',
        ]);
    }

    private function activeCounting(bool $requiresCount2 = false, bool $requiresCount3 = false): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'counting_number' => 'CNT-'.substr(uniqid(), -8),
            'status' => CountingStatus::Count1InProgress,
            'requires_count_2' => $requiresCount2,
            'requires_count_3' => $requiresCount3,
            'allow_unexpected_items' => true,
            'count_1_user_id' => $this->user->id,
            'count_2_user_id' => $requiresCount2 ? $this->user->id : null,
            'count_3_user_id' => $requiresCount3 ? $this->user->id : null,
        ]);
    }

    private function item(InventoryCounting $counting, ?Product $product = null): InventoryCountingItem
    {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => ($product ?? $this->product)->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'theoretical_qty' => '12.0000',
            'resolution_method' => ItemResolutionMethod::Pending,
        ]);
    }
}
