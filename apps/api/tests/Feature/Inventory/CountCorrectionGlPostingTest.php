<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Listeners\ApplyStockAdjustmentsOnCountingCompleted;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\Enums\ItemResolutionMethod;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Events\InventoryCountingCompleted;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DPA Wave 3D — T21: the counting listener's GL leg.
 *
 * Both counting paths (the LEGACY absolute `adjust()` path and the REPLAY
 * `postCountCorrection()` path) must value their movement on the row's own
 * `unit_cost` and post one direction-derived shrinkage/gain entry through the
 * inventory GL buffer, flushed once at the tail of the listener's single root
 * transaction.
 *
 * ## `[PG]` — and why `connectionsToTransact()` is empty
 *
 * `InventoryGlPostingBuffer::flushIfOutermost()` posts only at
 * `DB::transactionLevel() === 1`. RefreshDatabase's wrapping transaction would
 * hold every test at level >= 1 forever, so the listener's root frame would sit
 * at level 2 and the buffer would (correctly) refuse to post and raise its leak
 * alarm instead. The seam's own tests (InventoryGlPostingSeamTest) resolve this
 * the same way: opt out of transactional refresh and require PostgreSQL.
 *
 * ## The dormancy pin
 *
 * `inventory.count_correction_gl_posting_enabled` ships FALSE (OQ-12/H-5: the
 * expert-comptable must ratify the Option A presentation before the flag is
 * flipped for any tenant). Every posting test therefore turns the flag ON
 * explicitly, and `test_the_flag_is_off_by_default_and_the_dormant_listener_posts_nothing`
 * pins the shipped default.
 */
final class CountCorrectionGlPostingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                '[PG] T21 count-correction GL posting requires PostgreSQL and real root commits; '
                .'in-memory SQLite cannot persist the schema when connectionsToTransact() is empty.',
            );
        }

        $this->tenant = Tenant::create([
            'name' => 'Count GL Tenant',
            'slug' => 'countgl-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Count GL Company',
            'legal_name' => 'Count GL Company LLC',
            'tax_id' => 'CGL-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Count GL User',
            'email' => 'countgl-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'W'.uniqid(),
            'name' => 'Count GL Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
            'onboarding_mode' => false,
        ]);

        $this->product = $this->product('4.250000');
    }

    /**
     * The listener commits for real; RefreshDatabase's wrapping transaction
     * would otherwise pin every posting at level 2, where the buffer refuses.
     *
     * ⚠ ORDERING, on the SQLite lane only: opting out of transactional refresh
     * tears the shared in-memory schema down for any class scheduled AFTER this
     * one in the same process, which surfaces as "no such table: tenants" in an
     * unrelated file. This is a PRE-EXISTING property of the seam's `[PG]` test
     * pattern, not something this class introduces —
     * `InventoryGlPostingSeamTest` behaves identically (verified at this tip:
     * running it before ReplayFinalizeTest produces the same 11 errors). When
     * invoking a mixed set by path under `phpunit.xml`, put the `[PG]` classes
     * LAST. Under the `[PG]` recipe (phpunit-pgsql.xml) the question does not
     * arise.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    /**
     * Clean up the rows this class deliberately COMMITS.
     *
     * Because `connectionsToTransact()` is empty nothing rolls back, so every
     * movement and journal entry written here survives into the rest of the run
     * — and `accounting:check-cogs-coverage` scans the WHOLE database, every
     * tenant, every active company. Two of these cases end with a costed
     * count-correction movement that deliberately has NO entry (the fail-soft
     * chart and the dormant flag), which is exactly the shape D-e reports once
     * T21's posting flag is live. Left behind, they made
     * `CheckCogsCoverageCommandTest` fail in a file that never touched them.
     *
     * Deleting the movements and entries removes the finding surface; closing
     * the company removes the fixture from any other whole-database scanner
     * keyed on `CompanyStatus::Active`.
     */
    protected function tearDown(): void
    {
        if (isset($this->company)) {
            $entryIds = DB::table('journal_entries')->where('company_id', $this->company->id)->pluck('id');
            DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->delete();
            DB::table('journal_entries')->where('company_id', $this->company->id)->delete();
            DB::table('stock_movements')->where('company_id', $this->company->id)->delete();
            DB::table('companies')->where('id', $this->company->id)->update(['status' => CompanyStatus::Closed->value]);
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------- fixtures

    /** @param  numeric-string  $costPrice */
    private function product(string $costPrice): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'CGL-'.uniqid(),
            'name' => 'Count GL Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => $costPrice,
        ]);
    }

    private function account(SystemAccountPurpose $purpose, AccountType $type): Account
    {
        return Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => $type,
            'system_purpose' => $purpose,
        ]);
    }

    /**
     * The Option A chart: 6586 shrinkage expense, 7586 gain revenue, plus the
     * inventory asset both legs pair with.
     *
     * @return array{inventory: Account, shrinkage: Account, gain: Account}
     */
    private function optionAChart(): array
    {
        return [
            'inventory' => $this->account(SystemAccountPurpose::Inventory, AccountType::Asset),
            'shrinkage' => $this->account(SystemAccountPurpose::InventoryShrinkageExpense, AccountType::Expense),
            'gain' => $this->account(SystemAccountPurpose::InventoryGainIncome, AccountType::Revenue),
        ];
    }

    /** @param  numeric-string  $quantity */
    private function setOnHand(string $quantity, ?Product $product = null): StockLevel
    {
        return StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => ($product ?? $this->product)->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved' => '0.0000',
        ]);
    }

    private function counting(int $windowMinutes = 15): InventoryCounting
    {
        return InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'scope_type' => CountingScopeType::Location,
            'scope_filters' => ['location_id' => $this->location->id],
            'counting_number' => 'C'.uniqid(),
            'status' => CountingStatus::Finalized,
            'ambiguity_window_minutes' => $windowMinutes,
            'created_by_user_id' => $this->user->id,
        ]);
    }

    /**
     * @param  numeric-string  $finalQty
     * @param  numeric-string  $theoretical
     */
    private function item(
        InventoryCounting $counting,
        string $finalQty,
        ?CarbonImmutable $finalQtyAsOf,
        string $theoretical = '0.0000',
        ?Product $product = null,
    ): InventoryCountingItem {
        return InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => ($product ?? $this->product)->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => $theoretical,
            'final_qty' => $finalQty,
            'final_qty_as_of' => $finalQtyAsOf,
            'resolution_method' => ItemResolutionMethod::AutoAllMatch,
        ]);
    }

    private function fire(InventoryCounting $counting): void
    {
        app(ApplyStockAdjustmentsOnCountingCompleted::class)->handle(new InventoryCountingCompleted(
            countingId: $counting->id,
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            countingNumber: (string) $counting->counting_number,
            itemsCount: $counting->items()->count(),
            totalVariance: '0.0000',
            completedBy: $this->user->id,
            completedAt: now()->toIso8601String(),
        ));
    }

    private function enableFlag(): void
    {
        config(['inventory.count_correction_gl_posting_enabled' => true]);
    }

    private function countCorrectionMovement(?Product $product = null): StockMovement
    {
        return StockMovement::query()
            ->where('product_id', ($product ?? $this->product)->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->sole();
    }

    /**
     * Every count is scoped to THIS test's company.
     *
     * `connectionsToTransact()` is empty, so rows survive between the cases in
     * this class; a bare table count would silently read the previous test's
     * postings. Each test builds its own tenant/company in setUp().
     */
    private function countCorrectionMovements(): int
    {
        return StockMovement::query()
            ->where('company_id', $this->company->id)
            ->where('reason', MovementReason::CountCorrection->value)
            ->count();
    }

    private function shrinkageEntries(): int
    {
        return JournalEntry::query()
            ->where('company_id', $this->company->id)
            ->where('source_type', 'inventory_shrinkage')
            ->count();
    }

    private function entryFor(StockMovement $movement): JournalEntry
    {
        return JournalEntry::query()
            ->where('source_id', $movement->id)
            ->where('source_type', 'inventory_shrinkage')
            ->with('lines')
            ->sole();
    }

    // ------------------------------------------------------------- replay path

    /**
     * REPLAY path, shortage. The movement under assertion MUST be the one
     * `postCountCorrection()` writes — a `MovementType::Opening` /
     * `MovementReason::OpeningBalance` row from `postCountOpening()` is outside
     * the seam, so asserting on it would be green-by-vacuum (inv N-2).
     */
    public function test_replay_shortage_debits_shrinkage_on_the_rows_own_cost(): void
    {
        $this->enableFlag();
        $chart = $this->optionAChart();
        $asOf = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        // Counted 6 as of T, nothing moved since => adjustment −4 from on-hand 10.
        $this->item($counting, '6.0000', $asOf);

        $this->fire($counting);

        $movement = $this->countCorrectionMovement();
        self::assertSame(MovementType::Adjustment, $movement->movement_type);
        self::assertSame(MovementReason::CountCorrection, $movement->reason);
        self::assertSame(
            StockMovementReferenceType::InventoryCounting->value,
            $movement->reference_type,
            'The asserted movement must come from postCountCorrection(), not postCountOpening().',
        );
        self::assertNotNull($movement->unit_cost, 'T21 threads the row cost onto the count movement.');
        self::assertSame('4.250000', (string) $movement->unit_cost);

        $entry = $this->entryFor($movement);
        // 4.250000 x |6 − 10| = 17.000 at the TND scale of 3.
        self::assertSame('17.000', (string) $entry->lines[0]->debit);
        self::assertSame($chart['shrinkage']->id, $entry->lines[0]->account_id);
        self::assertSame('17.000', (string) $entry->lines[1]->credit);
        self::assertSame($chart['inventory']->id, $entry->lines[1]->account_id);

        $this->assertEntryAmountEqualsRowCostTimesAbsoluteDelta($movement, $entry);
    }

    /**
     * Campaign W4-6 — the shape the campaign actually ran: a goods receipt 13
     * minutes before the count instant (inside ±15) used to raise a blocking
     * `basket_window` flag, so the shrinkage reached neither stock nor the
     * ledger. The annotation must not cost the tenant its journal entry.
     */
    public function test_a_basket_window_line_still_posts_its_balanced_entry(): void
    {
        $this->enableFlag();
        $chart = $this->optionAChart();
        $asOf = CarbonImmutable::now()->subHours(2);
        $this->setOnHand('70.0000');

        // The near movement: a receipt 13 minutes BEFORE the count instant.
        StockMovement::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => MovementType::Receipt,
            'quantity' => '20.0000',
            'quantity_before' => '50.0000',
            'quantity_after' => '70.0000',
            'occurred_at' => $asOf->subMinutes(13),
        ]);

        $counting = $this->counting(15);
        $item = $this->item($counting, '68.0000', $asOf, '70.0000');

        $this->fire($counting);

        self::assertSame('68.0000', StockLevel::query()->where('product_id', $this->product->id)->value('quantity'));

        $movement = $this->countCorrectionMovement();
        self::assertSame('-2.0000', (string) $movement->quantity);

        $entry = $this->entryFor($movement);
        // 4.250000 x |68 − 70| = 8.500 — Dr shrinkage / Cr inventory, balanced.
        self::assertSame('8.500', (string) $entry->lines[0]->debit);
        self::assertSame($chart['shrinkage']->id, $entry->lines[0]->account_id);
        self::assertSame('8.500', (string) $entry->lines[1]->credit);
        self::assertSame($chart['inventory']->id, $entry->lines[1]->account_id);
        $this->assertEntryAmountEqualsRowCostTimesAbsoluteDelta($movement, $entry);

        // The reviewer still sees the ambiguity that used to veto the posting.
        $item->refresh();
        self::assertContains('basket_window', $item->flag_reasons ?? []);
    }

    /**
     * An AGREEING line posts neither a movement nor an entry (W4-6 /
     * document-per-action): the campaign found `qty 0.0000, 25 -> 25` no-ops as
     * the only rows a whole count produced.
     */
    public function test_an_agreeing_line_posts_no_movement_and_no_entry(): void
    {
        $this->enableFlag();
        $this->optionAChart();
        $asOf = CarbonImmutable::now()->subHours(2);
        $this->setOnHand('25.0000');

        $counting = $this->counting();
        $this->item($counting, '25.0000', $asOf, '25.0000');

        $this->fire($counting);

        self::assertSame(0, $this->countCorrectionMovements());
        self::assertSame(0, $this->shrinkageEntries());
        self::assertSame('25.0000', StockLevel::query()->where('product_id', $this->product->id)->value('quantity'));
    }

    /** REPLAY path, overage: Dr Inventory / Cr Gain. */
    public function test_replay_overage_credits_the_gain_account(): void
    {
        $this->enableFlag();
        $chart = $this->optionAChart();
        $asOf = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $this->item($counting, '12.0000', $asOf);

        $this->fire($counting);

        $movement = $this->countCorrectionMovement();
        $entry = $this->entryFor($movement);

        // 4.250000 x |12 − 10| = 8.500
        self::assertSame('8.500', (string) $entry->lines[0]->debit);
        self::assertSame($chart['inventory']->id, $entry->lines[0]->account_id);
        self::assertSame('8.500', (string) $entry->lines[1]->credit);
        self::assertSame($chart['gain']->id, $entry->lines[1]->account_id);

        $this->assertEntryAmountEqualsRowCostTimesAbsoluteDelta($movement, $entry);
    }

    // ------------------------------------------------------------- legacy path

    /**
     * LEGACY path (`final_qty_as_of IS NULL` => `adjust()`), same one basis.
     */
    public function test_legacy_path_posts_on_the_same_row_cost_basis(): void
    {
        $this->enableFlag();
        $chart = $this->optionAChart();
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        // theoretical 10, final 7 => delta −3 applied on top of on-hand 10 => 7.
        $this->item($counting, '7.0000', null, '10.0000');

        $this->fire($counting);

        self::assertSame('7.0000', StockLevel::query()->where('product_id', $this->product->id)->value('quantity'));

        $movement = $this->countCorrectionMovement();
        self::assertSame('4.250000', (string) $movement->unit_cost);

        $entry = $this->entryFor($movement);
        // 4.250000 x |7 − 10| = 12.750
        self::assertSame('12.750', (string) $entry->lines[0]->debit);
        self::assertSame($chart['shrinkage']->id, $entry->lines[0]->account_id);

        $this->assertEntryAmountEqualsRowCostTimesAbsoluteDelta($movement, $entry);
    }

    // ----------------------------------------------------------- idempotency

    /** A queue retry after the replay marker is stamped posts nothing new. */
    public function test_queue_retry_after_the_marker_posts_no_second_entry(): void
    {
        $this->enableFlag();
        $this->optionAChart();
        $asOf = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $item = $this->item($counting, '6.0000', $asOf);

        $this->fire($counting);
        self::assertNotNull($item->refresh()->replay_audit);

        $this->fire($counting);

        self::assertSame(1, $this->countCorrectionMovements());
        self::assertSame(1, $this->shrinkageEntries());
    }

    // --------------------------------------------------------- fail-soft chart

    /**
     * A company whose chart has no shrinkage/gain accounts still gets its stock
     * corrected — the GL leg fail-softs, the physical correction does not.
     */
    public function test_counting_on_a_company_with_no_shrinkage_account_still_applies_the_correction(): void
    {
        $this->enableFlag();
        $asOf = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $this->item($counting, '6.0000', $asOf);

        $this->fire($counting);

        self::assertSame('6.0000', StockLevel::query()->where('product_id', $this->product->id)->value('quantity'));
        self::assertSame(0, $this->shrinkageEntries());
    }

    // ------------------------------------------------------------- dormancy

    /**
     * OQ-12/H-5 deploy gate: the shipped default is OFF, and with it OFF the
     * listener corrects stock and posts NOTHING — the count movement still
     * carries its cost, so the ledger can be reconstructed once the flag flips.
     */
    public function test_the_flag_is_off_by_default_and_the_dormant_listener_posts_nothing(): void
    {
        self::assertFalse(
            (bool) config('inventory.count_correction_gl_posting_enabled'),
            'Count-correction GL posting must ship OFF until the expert-comptable ratifies Option A.',
        );

        $this->optionAChart();
        $asOf = CarbonImmutable::now()->subHours(3);
        $this->setOnHand('10.0000');

        $counting = $this->counting();
        $this->item($counting, '6.0000', $asOf);

        $this->fire($counting);

        self::assertSame('6.0000', StockLevel::query()->where('product_id', $this->product->id)->value('quantity'));
        self::assertSame('4.250000', (string) $this->countCorrectionMovement()->unit_cost);
        self::assertSame(0, $this->shrinkageEntries());
    }

    // ------------------------------------------------------- the root frame

    /**
     * The RULING's consequence, pinned: one root transaction around the whole
     * item loop makes the per-item boundaries savepoints, so an item-3 failure
     * leaves ZERO movements and ZERO entries — and the retry (the listener is
     * ShouldQueue with $tries = 3) produces exactly one of each.
     */
    public function test_an_item_that_throws_leaves_zero_movements_and_zero_entries_and_the_retry_posts_one_of_each(): void
    {
        $this->enableFlag();
        $this->optionAChart();

        $second = $this->product('2.000000');
        $third = $this->product('3.000000');
        $this->setOnHand('10.0000');
        $this->setOnHand('10.0000', $second);
        $this->setOnHand('10.0000', $third);

        $counting = $this->counting();
        $first = $this->item($counting, '7.0000', null, '10.0000');
        $secondItem = $this->item($counting, '7.0000', null, '10.0000', $second);
        $thirdItem = $this->item($counting, '7.0000', null, '10.0000', $third);

        // The pin only proves the rollback of items 1..N-1 if item 3 is reached
        // LAST. Assert the listener's own load order rather than assume it.
        self::assertSame(
            [$first->id, $secondItem->id, $thirdItem->id],
            InventoryCounting::with('items')->findOrFail($counting->id)->items->pluck('id')->all(),
            'The throwing item must be the last one the listener reaches.',
        );

        // A soft-deleted product makes the third item throw inside the loop:
        // StockAdjustmentService::resolveTenantId() uses findOrFail, which
        // excludes trashed rows. Restoring it clears the fault for the retry.
        $third->delete();

        try {
            $this->fire($counting);
            self::fail('The third item was expected to abort the listener.');
        } catch (ModelNotFoundException) {
            // expected
        }

        self::assertSame(0, $this->countCorrectionMovements());
        self::assertSame(0, $this->shrinkageEntries());
        self::assertSame('10.0000', StockLevel::query()->where('product_id', $this->product->id)->value('quantity'));
        self::assertSame('10.0000', StockLevel::query()->where('product_id', $second->id)->value('quantity'));

        $third->restore();
        $this->fire($counting);

        self::assertSame(3, $this->countCorrectionMovements());
        self::assertSame(3, $this->shrinkageEntries());
        foreach ([$this->product, $second, $third] as $product) {
            self::assertSame(1, StockMovement::query()
                ->where('product_id', $product->id)
                ->where('reason', MovementReason::CountCorrection->value)
                ->count());
        }
    }

    // --------------------------------------------------------- shared assertion

    /**
     * THE assertion that proves the two paths share one basis: the posted
     * amount is exactly `row.unit_cost x abs(delta)`, rounded once at the
     * currency scale — never re-derived from a since-changed WAC.
     */
    private function assertEntryAmountEqualsRowCostTimesAbsoluteDelta(StockMovement $movement, JournalEntry $entry): void
    {
        /** @var numeric-string $unitCost */
        $unitCost = (string) $movement->unit_cost;
        $expected = bcadd(bcmul($unitCost, $movement->absoluteDeltaForRow(), 9), '0', 3);

        $posted = (string) ($entry->lines[0]->debit > 0 ? $entry->lines[0]->debit : $entry->lines[0]->credit);

        self::assertSame(0, bccomp($expected, $posted, 3), "entry.amount must equal unit_cost x |delta| ({$expected} vs {$posted}).");
        self::assertSame(0, bccomp((string) $entry->lines[0]->debit, (string) $entry->lines[1]->credit, 3));
    }
}
