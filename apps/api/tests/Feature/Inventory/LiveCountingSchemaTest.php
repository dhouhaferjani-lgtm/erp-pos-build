<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTO\ReplayAuditDto;
use App\Modules\Inventory\Domain\Enums\CountingExecutionMode;
use App\Modules\Inventory\Domain\Enums\CountingItemFlagReason;
use App\Modules\Inventory\Domain\Enums\CountingScopeType;
use App\Modules\Inventory\Domain\Enums\CountingStatus;
use App\Modules\Inventory\Domain\InventoryCounting;
use App\Modules\Inventory\Domain\InventoryCountingItem;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A3: schema additions for live inventory counting (block-sales,
 * ambiguity-window, replay-audit, flag-reasons columns) + the new
 * `zone` CountingScopeType case.
 */
final class LiveCountingSchemaTest extends TestCase
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

        $this->tenant = Tenant::create([
            'name' => 'Live Counting Tenant',
            'slug' => 'live-counting-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Live Counting Company',
            'legal_name' => 'Live Counting Company LLC',
            'tax_id' => 'LC-TAX-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Live Counting User',
            'email' => 'lc-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-LC-01',
            'name' => 'Live Counting Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'LC-001',
            'name' => 'Live Counting Product',
            'type' => ProductType::Part,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function new_columns_exist_on_both_counting_tables(): void
    {
        $this->assertTrue(Schema::hasColumns('inventory_countings', [
            'block_sales',
            'ambiguity_window_minutes',
            'late_sales_flags',
            'includes_zero_stock',
        ]));

        $this->assertTrue(Schema::hasColumns('inventory_counting_items', [
            'count_1_device_at',
            'count_1_at_estimate',
            'count_2_device_at',
            'count_2_at_estimate',
            'count_3_device_at',
            'count_3_at_estimate',
            'final_qty_as_of',
            'expected_qty_at_apply',
            'opening_unit_cost',
            'replay_audit',
            'flag_reasons',
        ]));
    }

    #[Test]
    public function counting_scope_type_has_a_zone_case_with_a_label(): void
    {
        $this->assertSame('zone', CountingScopeType::Zone->value);
        $this->assertSame('Zone / Shelf', CountingScopeType::Zone->label());
        $this->assertFalse(CountingScopeType::Zone->allowsUnexpectedItems());

        // Every case must resolve through the closed match in label().
        foreach (CountingScopeType::cases() as $case) {
            $this->assertIsString($case->label());
        }
    }

    #[Test]
    public function a_counting_session_can_be_created_with_the_zone_scope_type(): void
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::Zone,
            'scope_filters' => ['zone_ids' => ['zone-1']],
            'execution_mode' => CountingExecutionMode::Sequential,
            'status' => CountingStatus::Draft,
            'requires_count_2' => false,
            'requires_count_3' => false,
        ]);

        $this->assertDatabaseHas('inventory_countings', [
            'id' => $counting->id,
            'scope_type' => CountingScopeType::Zone->value,
        ]);

        $counting->refresh();
        $this->assertSame(CountingScopeType::Zone, $counting->scope_type);
    }

    #[Test]
    public function new_counting_columns_are_writable_and_default_correctly(): void
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'execution_mode' => CountingExecutionMode::Parallel,
            'status' => CountingStatus::Draft,
            'requires_count_2' => false,
            'requires_count_3' => false,
        ]);
        $counting->refresh();

        // Defaults.
        $this->assertFalse($counting->block_sales);
        $this->assertSame(15, $counting->ambiguity_window_minutes);
        $this->assertNull($counting->late_sales_flags);
        $this->assertFalse($counting->includes_zero_stock);

        $lateSalesFlags = [
            ['receipt_id' => 'r-1', 'occurred_at' => '2026-07-06T10:00:00Z'],
        ];

        $counting->update([
            'block_sales' => true,
            'ambiguity_window_minutes' => 30,
            'late_sales_flags' => $lateSalesFlags,
            'includes_zero_stock' => true,
        ]);

        $counting->refresh();
        $this->assertTrue($counting->block_sales);
        $this->assertSame(30, $counting->ambiguity_window_minutes);
        $this->assertSame($lateSalesFlags, $counting->late_sales_flags);
        $this->assertTrue($counting->includes_zero_stock);
    }

    #[Test]
    public function new_counting_item_columns_are_writable(): void
    {
        $counting = InventoryCounting::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'created_by_user_id' => $this->user->id,
            'scope_type' => CountingScopeType::FullInventory,
            'scope_filters' => [],
            'execution_mode' => CountingExecutionMode::Parallel,
            'status' => CountingStatus::Draft,
            'requires_count_2' => false,
            'requires_count_3' => false,
        ]);

        $item = InventoryCountingItem::create([
            'counting_id' => $counting->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'theoretical_qty' => '10.0000',
        ]);

        $this->assertNull($item->count_1_device_at);
        $this->assertNull($item->final_qty_as_of);
        $this->assertNull($item->replay_audit);
        $this->assertNull($item->flag_reasons);

        $replayAudit = new ReplayAuditDto(
            windowFrom: '2026-07-06T09:00:00Z',
            windowTo: '2026-07-06T10:00:00Z',
            replayedDelta: '-2.0000',
            onHandAtApply: '8.0000',
            expectedAtApply: '8.0000',
        );

        $flagReasons = [
            CountingItemFlagReason::BasketWindow->value,
            CountingItemFlagReason::NormalizedAgreement->value,
        ];

        $item->update([
            'count_1_device_at' => '2026-07-06 09:58:00',
            'count_1_at_estimate' => '2026-07-06 09:59:00',
            'final_qty_as_of' => '2026-07-06 10:00:00',
            'expected_qty_at_apply' => '8.0000',
            'opening_unit_cost' => '12.500000',
            'replay_audit' => $replayAudit->toArray(),
            'flag_reasons' => $flagReasons,
            'is_flagged' => true,
        ]);

        $item->refresh();

        $this->assertNotNull($item->count_1_device_at);
        $this->assertNotNull($item->count_1_at_estimate);
        $this->assertNotNull($item->final_qty_as_of);
        $this->assertSame('8.0000', $item->expected_qty_at_apply);
        $this->assertSame('12.500000', $item->opening_unit_cost);
        $this->assertSame($flagReasons, $item->flag_reasons);
        $this->assertSame(
            $replayAudit->toArray(),
            $item->replay_audit,
        );
        $this->assertTrue($item->is_flagged);
    }

    #[Test]
    public function counting_item_flag_reason_enum_has_correct_blocking_semantics(): void
    {
        $this->assertSame('basket_window', CountingItemFlagReason::BasketWindow->value);
        $this->assertSame('negative_at_apply', CountingItemFlagReason::NegativeAtApply->value);
        $this->assertSame('clock_skew', CountingItemFlagReason::ClockSkew->value);
        $this->assertSame('normalized_agreement', CountingItemFlagReason::NormalizedAgreement->value);

        $this->assertTrue(CountingItemFlagReason::BasketWindow->isBlocking());
        $this->assertTrue(CountingItemFlagReason::NegativeAtApply->isBlocking());
        $this->assertTrue(CountingItemFlagReason::ClockSkew->isBlocking());
        $this->assertFalse(CountingItemFlagReason::NormalizedAgreement->isBlocking());
    }

    #[Test]
    public function replay_audit_dto_exposes_the_contracted_shape(): void
    {
        $dto = new ReplayAuditDto(
            windowFrom: '2026-07-06T09:00:00Z',
            windowTo: '2026-07-06T10:00:00Z',
            replayedDelta: '-2.0000',
            onHandAtApply: '8.0000',
            expectedAtApply: '8.0000',
        );

        $this->assertSame('2026-07-06T09:00:00Z', $dto->windowFrom);
        $this->assertSame('2026-07-06T10:00:00Z', $dto->windowTo);
        $this->assertSame('-2.0000', $dto->replayedDelta);
        $this->assertSame('8.0000', $dto->onHandAtApply);
        $this->assertSame('8.0000', $dto->expectedAtApply);
    }

    #[Test]
    public function the_migration_drops_and_readds_the_scope_type_check_with_zone(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/tenant/2026_07_06_200003_add_live_counting_columns.php'
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString('DROP CONSTRAINT IF EXISTS chk_valid_scope_type', $migration);
        $this->assertStringContainsString('ADD CONSTRAINT chk_valid_scope_type', $migration);
        $this->assertStringContainsString("'zone'", $migration);
        $this->assertStringContainsString("'product_location'", $migration);
        $this->assertStringContainsString("'full_inventory'", $migration);
    }

    #[Test]
    public function model_casts_cover_every_new_column(): void
    {
        $countingCasts = (new InventoryCounting)->getCasts();
        $this->assertSame('boolean', $countingCasts['block_sales']);
        $this->assertSame('integer', $countingCasts['ambiguity_window_minutes']);
        $this->assertSame('array', $countingCasts['late_sales_flags']);
        $this->assertSame('boolean', $countingCasts['includes_zero_stock']);

        $itemCasts = (new InventoryCountingItem)->getCasts();
        $this->assertSame('datetime', $itemCasts['count_1_device_at']);
        $this->assertSame('datetime', $itemCasts['count_1_at_estimate']);
        $this->assertSame('datetime', $itemCasts['count_2_device_at']);
        $this->assertSame('datetime', $itemCasts['count_2_at_estimate']);
        $this->assertSame('datetime', $itemCasts['count_3_device_at']);
        $this->assertSame('datetime', $itemCasts['count_3_at_estimate']);
        $this->assertSame('datetime', $itemCasts['final_qty_as_of']);
        $this->assertSame('decimal:4', $itemCasts['expected_qty_at_apply']);
        $this->assertSame('decimal:6', $itemCasts['opening_unit_cost']);
        $this->assertSame('array', $itemCasts['replay_audit']);
        $this->assertSame('array', $itemCasts['flag_reasons']);
    }

    #[Test]
    public function sqlite_column_types_match_the_precision_contract(): void
    {
        $columns = DB::select("PRAGMA table_info('inventory_counting_items')");
        $byName = [];
        foreach ($columns as $column) {
            $byName[$column->name] = $column;
        }

        $this->assertStringContainsStringIgnoringCase('numeric', (string) $byName['expected_qty_at_apply']->type);
        $this->assertStringContainsStringIgnoringCase('numeric', (string) $byName['opening_unit_cost']->type);
        $this->assertSame(0, (int) $byName['expected_qty_at_apply']->notnull);
        $this->assertSame(0, (int) $byName['opening_unit_cost']->notnull);
    }
}
