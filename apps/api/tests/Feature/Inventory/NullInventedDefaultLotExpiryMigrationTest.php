<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Campaign W4-1 backfill — `2026_08_26_100100_null_invented_default_lot_expiries`.
 *
 * The lots already in a live tenant carry the fiction. This pins the predicate
 * that separates an INVENTED expiry from a real one, in both directions: what it
 * clears, and — more important — everything it must leave alone. A predicate
 * that over-reaches here silently deletes real expiry data on a parapharmacy.
 */
final class NullInventedDefaultLotExpiryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/tenant/2026_08_26_100100_null_invented_default_lot_expiries.php';

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'W41 Backfill Tenant',
            'slug' => 'w41-backfill-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'W41 Backfill Company',
            'legal_name' => 'W41 Backfill Company LLC',
            'tax_id' => 'W41B-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
    }

    public function test_it_clears_the_invented_expiry_and_leaves_every_real_one_alone(): void
    {
        // (a) THE FICTION: DEFAULT lot, product has no configured shelf life,
        //     expiry is exactly manufacturing_date + 365. This is what the old
        //     ensureDefaultBatch() wrote for every opening on the launch tenant.
        $noShelfLife = $this->product(shelfLifeDays: null);
        $invented = $this->lot($noShelfLife, 'DEFAULT', '2026-08-25', '2027-08-25');

        // (b) A DEFAULT lot on a product that DOES configure a 365-day shelf life.
        //     Same arithmetic, but the operator supplied the rule — not a fiction.
        $withShelfLife = $this->product(shelfLifeDays: 365);
        $configured = $this->lot($withShelfLife, 'DEFAULT', '2026-08-25', '2027-08-25');

        // (c) A DEFAULT lot an operator has since EDITED: the span is no longer 365.
        $edited = $this->lot($this->product(shelfLifeDays: null), 'DEFAULT', '2026-08-25', '2027-12-31');

        // (d) A REAL, operator-supplied lot that happens to span exactly 365 days.
        //     The batch NUMBER is the tell: this expiry came off a supplier's box.
        $real = $this->lot($this->product(shelfLifeDays: null), 'LOT-SIROP-2026A', '2026-08-25', '2027-08-25');

        // (e) A DEFAULT lot with no manufacturing_date — nothing to measure against.
        $undatable = $this->lot($this->product(shelfLifeDays: null), 'DEFAULT', null, '2027-08-25');

        $this->runMigration();

        $this->assertNull(
            $this->storedExpiry($invented),
            'the invented cutover+365 expiry must be cleared — it is the date FEFO was ranking on',
        );

        $this->assertSame('2027-08-25', $this->storedExpiry($configured), 'a CONFIGURED shelf life is a supplied rule');
        $this->assertSame('2027-12-31', $this->storedExpiry($edited), 'an operator edit must survive');
        $this->assertSame('2027-08-25', $this->storedExpiry($real), 'a real lot number means a real expiry');
        $this->assertSame('2027-08-25', $this->storedExpiry($undatable), 'with no manufacturing_date there is no evidence of invention');
    }

    public function test_it_is_idempotent_and_a_no_op_on_a_tenant_with_nothing_to_fix(): void
    {
        $clean = $this->lot($this->product(shelfLifeDays: null), 'LOT-CLEAN', '2026-08-25', '2027-03-31');

        $this->runMigration();
        $this->runMigration();

        $this->assertSame('2027-03-31', $this->storedExpiry($clean));

        // And a second pass over a tenant that HAS been fixed must not cascade:
        // the cleared rows no longer match (their expiry is NULL), so nothing else
        // is touched.
        $invented = $this->lot($this->product(shelfLifeDays: null), 'DEFAULT', '2026-08-25', '2027-08-25');
        $this->runMigration();
        $this->assertNull($this->storedExpiry($invented));
        $this->runMigration();
        $this->assertSame('2027-03-31', $this->storedExpiry($clean));
    }

    /**
     * The expiry AS STORED, read straight off the table rather than through the
     * model's date cast: this test is about what the migration wrote, and the
     * cast would hide a driver-level difference between NULL and an empty date.
     */
    private function storedExpiry(Batch $batch): ?string
    {
        $value = DB::table('product_batches')->where('id', $batch->id)->value('expiry_date');

        if ($value === null) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    /**
     * Gate r1 [MINOR] — the fingerprint stopped being unique the moment the
     * Products import could SUPPLY a 12-month expiry: an opening posted the same
     * day (`manufacturing_date = asOfDate`) produces byte-identical evidence to
     * the fabricated date. The provenance bound is what keeps them apart — only a
     * lot that existed BEFORE this migration ran can carry the fiction.
     */
    public function test_it_never_touches_a_lot_created_after_it_ran(): void
    {
        $product = $this->product(shelfLifeDays: null);

        // An operator-supplied 365-day expiry on an opening posted today. Same
        // shape as the fiction in every other respect.
        $supplied = $this->lot(
            $product,
            'DEFAULT',
            CarbonImmutable::today()->toDateString(),
            CarbonImmutable::today()->addDays(365)->toDateString(),
            createdAt: CarbonImmutable::now(),
        );

        $this->runMigration();

        $this->assertSame(
            CarbonImmutable::today()->addDays(365)->toDateString(),
            $this->storedExpiry($supplied),
            'a date the operator supplied AFTER this migration ran must survive it — otherwise lifting this '
            .'predicate into a repair command, or replaying it on a restored DB, would delete real expiry data',
        );
    }

    /**
     * Gate r1 [IMPORTANT] — the predicate tests the product's CURRENT
     * `default_shelf_life_days`, but the `?? 365` fallback fired against the value
     * the product held WHEN THE LOT WAS MINTED. A product that had NULL at import
     * and has since been given a shelf life therefore keeps its invented date, and
     * the mutating census reports it as nothing to fix. That drift is invisible
     * from the migrate output, which is the whole problem — so it is CENSUSED, by
     * product code, and deliberately not mutated: the evidence is genuinely
     * ambiguous and an operator decides, not the migration.
     */
    public function test_it_censuses_but_does_not_touch_a_lot_whose_product_now_configures_a_different_shelf_life(): void
    {
        $product = $this->product(shelfLifeDays: 180);
        $drifted = $this->lot($product, 'DEFAULT', '2026-08-25', '2027-08-25');

        ob_start();
        $this->runMigration();
        $output = (string) ob_get_clean();

        $this->assertSame(
            '2027-08-25',
            $this->storedExpiry($drifted),
            'ambiguous evidence must never be resolved by deleting data',
        );
        $this->assertStringContainsString('[W4-1] RESIDUAL (not modified)', $output);
        $this->assertStringContainsString(
            (string) $product->sku,
            $output,
            'the residual line must name the product so an operator can actually review it',
        );
    }

    /**
     * Gate r1 [IMPORTANT] — the deploy note tells the operator to watch the
     * migrate log for `[W4-1]` lines. A census of ZERO must therefore still print:
     * absence of output has to mean "the migration did not run at all", never
     * "ran and found nothing".
     */
    public function test_a_zero_census_still_prints_so_silence_is_unambiguous(): void
    {
        ob_start();
        $this->runMigration();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('[W4-1] invented DEFAULT-lot expiries found: 0', $output);
    }

    private function runMigration(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->up();
    }

    private function product(?int $shelfLifeDays): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'W41B-'.uniqid(),
            'name' => 'W41 Backfill Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '0.000000',
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => $shelfLifeDays,
        ]);
    }

    /**
     * @param  ?CarbonImmutable  $createdAt  When the lot row was written. Defaults to a
     *                                       week ago: the migration only touches lots that
     *                                       PREDATE its own run, so a fixture created in the
     *                                       same instant would fall outside the window by
     *                                       construction rather than by evidence.
     */
    private function lot(
        Product $product,
        string $batchNumber,
        ?string $manufacturingDate,
        ?string $expiryDate,
        ?CarbonImmutable $createdAt = null,
    ): Batch {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'manufacturing_date' => $manufacturingDate,
            'expiry_date' => $expiryDate,
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        $batch->forceFill(['created_at' => $createdAt ?? CarbonImmutable::now()->subWeek()])->save();

        return $batch->refresh();
    }
}
