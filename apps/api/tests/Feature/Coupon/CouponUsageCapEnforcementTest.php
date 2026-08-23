<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Coupon\Application\Services\CouponApplicationService;
use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Entities\CouponUsage;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Enums\CouponType;
use App\Modules\Coupon\Domain\Exceptions\CouponInvalidException;
use App\Modules\Identity\Domain\User;
use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lane Q-4 — coupon cap enforcement.
 *
 * Guards the three holes in the POS-till sub-report HIGH
 * ("Coupon usage recording has no lock, no transaction and no unique index"):
 *   1. `coupon_usages` / `promotion_usages` carried no unique key on
 *      (parent_id, receipt_id), so a POS sync retry of one receipt inserted a
 *      second usage row and permanently inflated the usage counter.
 *   2. `CouponApplicationService::recordUsage()` ran unlocked and outside a
 *      transaction, so two concurrent checkouts both honoured a `max_uses = 1`
 *      coupon and the auto-exhaust fired only after both discounts were sealed.
 *   3. `CouponValidationService::validateAndCalculate()` never refused a coupon
 *      that had reached its global cap with the typed "exhausted" refusal —
 *      the cap fell through `Coupon::isValid()` and surfaced as "expired".
 */
final class CouponUsageCapEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        /** @var CompanyContext $context */
        $context = app(CompanyContext::class);
        $context->setCompanyId($this->company->id);
    }

    // ──────────────────────────────────────────────────────────────────
    // (a) DB-level uniqueness — the last line of defence against a
    //     duplicated POS sync replay.
    // ──────────────────────────────────────────────────────────────────

    public function test_duplicate_coupon_usage_for_same_receipt_is_rejected_by_unique_index(): void
    {
        $this->requirePostgres();

        $coupon = $this->makeCoupon(['max_uses' => 5]);
        $receiptId = Str::uuid()->toString();

        DB::table('coupon_usages')->insert($this->usageRow('coupon_id', $coupon->id, $receiptId));

        $this->expectException(QueryException::class);

        DB::table('coupon_usages')->insert($this->usageRow('coupon_id', $coupon->id, $receiptId));
    }

    public function test_duplicate_promotion_usage_for_same_receipt_is_rejected_by_unique_index(): void
    {
        $this->requirePostgres();

        $promotion = $this->makePromotion();
        $receiptId = Str::uuid()->toString();

        DB::table('promotion_usages')->insert($this->usageRow('promotion_id', $promotion->id, $receiptId));

        $this->expectException(QueryException::class);

        DB::table('promotion_usages')->insert($this->usageRow('promotion_id', $promotion->id, $receiptId));
    }

    public function test_unique_index_still_allows_the_same_receipt_to_use_two_different_coupons(): void
    {
        $this->requirePostgres();

        $first = $this->makeCoupon(['code' => 'CAPONE', 'max_uses' => 5]);
        $second = $this->makeCoupon(['code' => 'CAPTWO', 'max_uses' => 5]);
        $receiptId = Str::uuid()->toString();

        DB::table('coupon_usages')->insert($this->usageRow('coupon_id', $first->id, $receiptId));
        DB::table('coupon_usages')->insert($this->usageRow('coupon_id', $second->id, $receiptId));

        $this->assertSame(2, CouponUsage::where('receipt_id', $receiptId)->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // (b) recordUsage — transaction + row lock + idempotent retry.
    // ──────────────────────────────────────────────────────────────────

    public function test_record_usage_retry_for_the_same_receipt_is_idempotent(): void
    {
        $coupon = $this->makeCoupon(['max_uses' => 5]);
        $receiptId = Str::uuid()->toString();
        $service = $this->service();

        $service->recordUsage($coupon->id, $receiptId, null, '5.00');
        // The POS replays the same sealed receipt after a sync timeout.
        $service->recordUsage($coupon->id, $receiptId, null, '5.00');

        $coupon->refresh();

        $this->assertSame(
            1,
            CouponUsage::where('coupon_id', $coupon->id)->count(),
            'A replayed receipt must not create a second coupon_usages row.',
        );
        $this->assertSame(
            1,
            $coupon->use_count,
            'A replayed receipt must not inflate use_count.',
        );
        $this->assertSame(
            CouponStatus::Active,
            $coupon->status,
            'A replayed receipt must not push an under-cap coupon to Exhausted.',
        );
    }

    public function test_record_usage_takes_a_row_lock_inside_a_transaction(): void
    {
        $this->requirePostgres();

        $coupon = $this->makeCoupon(['max_uses' => 5]);
        $service = $this->service();

        DB::enableQueryLog();
        $service->recordUsage($coupon->id, Str::uuid()->toString(), null, '5.00');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = array_map(static fn (array $entry): string => strtolower((string) $entry['query']), $log);

        $lockQuery = null;
        foreach ($sql as $query) {
            if (str_contains($query, 'from "coupons"') && str_contains($query, 'for update')) {
                $lockQuery = $query;
                break;
            }
        }

        $this->assertNotNull(
            $lockQuery,
            'recordUsage must claim the coupon row with lockForUpdate. Log: '.json_encode($sql),
        );
        $this->assertStringContainsString('"tenant_id"', $lockQuery);
        $this->assertStringContainsString('"company_id"', $lockQuery);
    }

    /**
     * Gate r1 F-3: this is a SEQUENTIAL pair, not a race, and the second call is
     * refused by the *status* gate (`recordUsage` auto-exhausted the coupon on
     * the first call), not by the counter re-check. Named accordingly. The
     * counter branch is covered by
     * test_record_usage_refuses_and_seals_a_counter_at_cap_with_a_stale_active_status.
     */
    public function test_record_usage_refuses_a_sequential_second_checkout_via_the_status_gate(): void
    {
        // Interleave simulation: two concurrent carts both validated against a
        // max_uses = 1 coupon while it still read Active / use_count = 0. Both
        // hold that stale snapshot; both now try to seal. Only the first may win.
        $coupon = $this->makeCoupon(['max_uses' => 1]);
        $service = $this->service();

        $racerOneSnapshot = Coupon::findOrFail($coupon->id);
        $racerTwoSnapshot = Coupon::findOrFail($coupon->id);
        $this->assertSame(0, $racerOneSnapshot->use_count);
        $this->assertSame(0, $racerTwoSnapshot->use_count);
        $this->assertSame(CouponStatus::Active, $racerTwoSnapshot->status);

        $service->recordUsage($racerOneSnapshot->id, Str::uuid()->toString(), null, '5.00');

        $thrown = null;
        try {
            $service->recordUsage($racerTwoSnapshot->id, Str::uuid()->toString(), null, '5.00');
        } catch (CouponInvalidException $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            'The second racer must be refused once the global cap is consumed.',
        );
        $this->assertStringContainsString('fully redeemed', $thrown->getMessage());

        $coupon->refresh();
        $this->assertSame(1, $coupon->use_count, 'A max_uses = 1 coupon must never exceed one use.');
        $this->assertSame(CouponStatus::Exhausted, $coupon->status);
        $this->assertSame(1, CouponUsage::where('coupon_id', $coupon->id)->count());
    }

    /**
     * Gate r1 F-1: the counter branch — `use_count` has reached `max_uses` while
     * `status` still reads Active (legacy row, a data fix, or a writer that lost
     * a race and never got to auto-exhaust). The refusal must fire AND the seal
     * it writes must SURVIVE the refusal: a seal that lives inside the
     * transaction the throw unwinds is always rolled back, so the next reader
     * re-derives the same conclusion from the counter forever.
     */
    public function test_record_usage_refuses_and_seals_a_counter_at_cap_with_a_stale_active_status(): void
    {
        $coupon = $this->makeCoupon([
            'max_uses' => 1,
            'use_count' => 1,
            'status' => CouponStatus::Active,
        ]);
        $service = $this->service();

        $thrown = null;
        try {
            $service->recordUsage($coupon->id, Str::uuid()->toString(), null, '5.00');
        } catch (CouponInvalidException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A counter already at the cap must be refused.');
        $this->assertStringContainsString('fully redeemed', $thrown->getMessage());

        $coupon->refresh();
        $this->assertSame(
            CouponStatus::Exhausted,
            $coupon->status,
            'The Exhausted seal must persist after the refusal — a seal written inside '
            .'the transaction the throw unwinds is silently rolled back.',
        );
        $this->assertSame(1, $coupon->use_count, 'The refusal must not move the counter.');
        $this->assertSame(
            0,
            CouponUsage::where('coupon_id', $coupon->id)->count(),
            'The refusal must not write a usage row.',
        );
    }

    /**
     * Gate r1 F-2: the savepoint / 23505 branch.
     *
     * A racing writer commits the usage row for this exact receipt in the window
     * between our `exists()` probe and our insert. The insert then raises 23505.
     * That must NOT poison an ENCLOSING checkout transaction: the insert lives in
     * a nested transaction (SAVEPOINT), the exception escapes that closure so
     * PostgreSQL rolls back to the savepoint, and the enclosing transaction goes
     * on to commit its own work.
     *
     * The race is injected with a QueryExecuted listener that fires on the
     * `exists()` probe itself, so the interleave is exact rather than hopeful.
     */
    public function test_a_racing_duplicate_rolls_back_to_the_savepoint_and_leaves_the_enclosing_transaction_alive(): void
    {
        $this->requirePostgres();

        $coupon = $this->makeCoupon(['max_uses' => 5]);
        $receiptId = Str::uuid()->toString();
        $service = $this->service();

        $injected = false;
        Event::listen(function (QueryExecuted $event) use (&$injected, $coupon, $receiptId): void {
            if ($injected) {
                return;
            }
            $sql = strtolower($event->sql);
            if (! str_contains($sql, 'from "coupon_usages"') || ! str_contains($sql, 'exists')) {
                return;
            }

            $injected = true;

            // The racer: same (coupon_id, receipt_id), and it did its own
            // increment before committing — exactly what a concurrent sync
            // worker would have left behind.
            DB::table('coupon_usages')->insert($this->usageRow('coupon_id', $coupon->id, $receiptId));
            DB::table('coupons')->where('id', $coupon->id)->update(['use_count' => 1]);
        });

        DB::transaction(function () use ($service, $coupon, $receiptId): void {
            $service->recordUsage($coupon->id, $receiptId, null, '5.00');

            // If the 23505 had poisoned the enclosing transaction, PostgreSQL
            // would refuse this statement with 25P02 and the test would error.
            DB::table('coupons')->where('id', $coupon->id)->update(['name' => 'Enclosing txn committed']);
        });

        $this->assertTrue($injected, 'The racing duplicate must actually have been injected.');

        $coupon->refresh();
        $this->assertSame(
            'Enclosing txn committed',
            $coupon->name,
            'The enclosing transaction must commit its own work after the swallowed 23505.',
        );
        $this->assertSame(
            1,
            CouponUsage::where('coupon_id', $coupon->id)->count(),
            'Exactly one usage row may exist for the receipt — the racer won, we stood down.',
        );
        $this->assertSame(
            1,
            $coupon->use_count,
            'use_count must not be double-incremented on top of the racer’s increment.',
        );
    }

    public function test_record_usage_refuses_a_revoked_coupon_under_the_lock(): void
    {
        $coupon = $this->makeCoupon(['max_uses' => 5, 'status' => CouponStatus::Revoked]);
        $service = $this->service();

        $thrown = null;
        try {
            $service->recordUsage($coupon->id, Str::uuid()->toString(), null, '5.00');
        } catch (CouponInvalidException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A revoked coupon must not be recordable.');
        $this->assertSame(0, CouponUsage::where('coupon_id', $coupon->id)->count());
        $this->assertSame(0, $coupon->refresh()->use_count);
    }

    public function test_record_usage_auto_exhausts_a_single_use_coupon_but_stays_idempotent_on_replay(): void
    {
        $coupon = $this->makeCoupon(['is_single_use' => true]);
        $receiptId = Str::uuid()->toString();
        $service = $this->service();

        $service->recordUsage($coupon->id, $receiptId, null, '5.00');
        $this->assertSame(CouponStatus::Exhausted, $coupon->refresh()->status);

        // The replay must be swallowed even though the coupon is now Exhausted:
        // the receipt is already recorded, so this is the same fiscal event.
        $service->recordUsage($coupon->id, $receiptId, null, '5.00');

        $coupon->refresh();
        $this->assertSame(1, $coupon->use_count);
        $this->assertSame(1, CouponUsage::where('coupon_id', $coupon->id)->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // (c) Happy-path regression.
    // ──────────────────────────────────────────────────────────────────

    public function test_record_usage_under_the_cap_still_applies(): void
    {
        $coupon = $this->makeCoupon(['max_uses' => 3]);
        $service = $this->service();

        $service->recordUsage($coupon->id, Str::uuid()->toString(), null, '5.00');
        $service->recordUsage($coupon->id, Str::uuid()->toString(), null, '7.50');

        $coupon->refresh();
        $this->assertSame(2, $coupon->use_count);
        $this->assertSame(CouponStatus::Active, $coupon->status);
        $this->assertSame(2, CouponUsage::where('coupon_id', $coupon->id)->count());
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function service(): CouponApplicationService
    {
        /** @var CouponApplicationService $service */
        $service = app(CouponApplicationService::class);

        return $service;
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial/unique index enforcement is PostgreSQL-only.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCoupon(array $overrides = []): Coupon
    {
        /** @var Coupon $coupon */
        $coupon = Coupon::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cap Coupon',
            'code' => 'CAPTEST',
            'type' => CouponType::Standard,
            'status' => CouponStatus::Active,
            'discount_type' => 'percentage',
            'discount_value' => '10.00',
            'use_count' => 0,
        ], $overrides));

        return $coupon;
    }

    private function makePromotion(): Promotion
    {
        /** @var Promotion $promotion */
        $promotion = Promotion::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cap Promotion',
            'type' => PromotionType::CategoryDiscount,
            'status' => PromotionStatus::Active,
            'conditions' => [],
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '10.00',
            'applies_to' => DiscountAppliesTo::Transaction,
            'usage_count' => 0,
        ]);

        return $promotion;
    }

    /**
     * @return array<string, string>
     */
    private function usageRow(string $parentColumn, string $parentId, string $receiptId): array
    {
        return [
            'id' => Str::uuid()->toString(),
            $parentColumn => $parentId,
            'receipt_id' => $receiptId,
            'discount_amount' => '5.00',
            'used_at' => now()->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }
}
