<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockLevelData;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Inventory\Domain\StockAdjustmentLine;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DPA V7 / T7 + T19 — the DTOs the frontend consumes.
 *
 * Clones GoodsReceiptDataTest's discipline: every quantity serializes as a
 * DECIMAL STRING, never a float (rule 19), enforced by an array_walk_recursive
 * guard rather than by spot assertions.
 */
final class StockAdjustmentDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'DTO Tenant',
            'slug' => 'dto-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'DTO Co',
            'legal_name' => 'DTO Co LLC',
            'tax_id' => 'TAX-DTO',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'DTO User',
            'email' => 'dto@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'DTO-01',
            'name' => 'DTO Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    #[Test]
    public function from_model_serializes_the_document_and_its_lines_with_decimal_strings(): void
    {
        $product = $this->product('DTO-P1', batchTracked: true);
        $batch = $this->lot($product, 'LOT-DTO');

        $adjustment = StockAdjustment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'adjustment_number' => 'ADJ-2026-0001',
            'status' => StockAdjustmentStatus::Posted,
            'note' => 'stocktake',
            'location_id' => $this->location->id,
            'occurred_at' => now(),
            'created_by_user_id' => $this->user->id,
            'posted_by_user_id' => $this->user->id,
            'posted_at' => now(),
        ]);

        StockAdjustmentLine::create([
            'adjustment_id' => $adjustment->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'reason_code' => MovementReason::AdjustmentNegative,
            'delta_quantity' => '-2.5000',
            'observed_before' => '12.0000',
            'quantity_before' => '12.0000',
            'quantity_after' => '9.5000',
            'line_note' => 'broken seal',
        ]);

        $array = StockAdjustmentData::fromModel($adjustment->fresh() ?? $adjustment)->toArray();

        $this->assertSame($adjustment->id, $array['id']);
        $this->assertSame('posted', $array['status']);
        $this->assertSame('ADJ-2026-0001', $array['adjustment_number']);
        $this->assertSame(1, $array['lines_count']);
        $this->assertCount(1, $array['lines']);

        $line = $array['lines'][0];
        $this->assertSame('-2.5000', $line['delta_quantity']);
        $this->assertSame('12.0000', $line['observed_before']);
        $this->assertSame('9.5000', $line['quantity_after']);
        $this->assertSame('adjustment_negative', $line['reason_code']);
        // The lot is exposed by its PUBLIC uuid; the int batch_id never ships.
        $this->assertSame($batch->uuid, $line['batch_uuid']);
        $this->assertSame('LOT-DTO', $line['batch_number']);
        $this->assertArrayNotHasKey('batch_id', $line);
        $this->assertIsInt($line['quantity_decimals']);

        $this->assertContainsOnlyDecimalStrings($array);
    }

    #[Test]
    public function the_correction_inverse_is_exposed_on_both_sides(): void
    {
        $product = $this->product('DTO-P2', batchTracked: false);

        $original = $this->minimalAdjustment($product, StockAdjustmentStatus::Posted);
        $contra = $this->minimalAdjustment($product, StockAdjustmentStatus::Draft, correctsId: $original->id);

        $originalArray = StockAdjustmentData::fromModel($original->fresh() ?? $original)->toArray();
        $contraArray = StockAdjustmentData::fromModel($contra->fresh() ?? $contra)->toArray();

        // §3 stores only one side; F4's canCorrect consumes the other.
        $this->assertNull($originalArray['corrects_adjustment_id']);
        $this->assertSame($contra->id, $originalArray['correction_id']);
        $this->assertSame($original->id, $contraArray['corrects_adjustment_id']);
        $this->assertNull($contraArray['correction_id']);
    }

    #[Test]
    public function a_cancelled_contra_is_not_reported_as_the_correction(): void
    {
        $product = $this->product('DTO-P2B', batchTracked: false);

        $original = $this->minimalAdjustment($product, StockAdjustmentStatus::Posted);
        $cancelled = $this->minimalAdjustment(
            $product,
            StockAdjustmentStatus::Cancelled,
            correctsId: $original->id,
        );

        // The API allows re-correcting after a cancellation, so surfacing the
        // abandoned document here would leave the UI's canCorrect false and
        // dead-end the operator where the server would have said yes.
        $array = StockAdjustmentData::fromModel($original->fresh() ?? $original)->toArray();
        $this->assertNull($array['correction_id']);
        $this->assertNotSame($cancelled->id, $array['correction_id']);
    }

    #[Test]
    public function lines_can_be_omitted_for_a_list_response(): void
    {
        $product = $this->product('DTO-P3', batchTracked: false);
        $adjustment = $this->minimalAdjustment($product, StockAdjustmentStatus::Draft);

        $array = StockAdjustmentData::fromModel($adjustment, withLines: false)->toArray();

        $this->assertSame([], $array['lines']);
        $this->assertSame(0, $array['lines_count']);
    }

    #[Test]
    public function stock_level_data_exposes_both_lot_fields(): void
    {
        $product = $this->product('DTO-P4', batchTracked: true);

        $level = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $withoutLots = StockLevelData::fromModel($level->fresh(['product.unitOfMeasure']) ?? $level)->toArray();
        $this->assertTrue($withoutLots['requires_batch_tracking']);
        $this->assertFalse(
            $withoutLots['has_lots_at_location'],
            'Door 1: a batch-tracked product with ZERO lots must report has_lots_at_location=false, '
            .'or the frontend would require a lot the picker cannot offer.'
        );

        $this->lot($product, 'LOT-DTO-4', withStock: true);

        $withLots = StockLevelData::fromModel($level->fresh(['product.unitOfMeasure']) ?? $level)->toArray();
        $this->assertTrue($withLots['has_lots_at_location']);
    }

    #[Test]
    public function stock_level_data_reports_lots_independently_of_the_flag(): void
    {
        $product = $this->product('DTO-P5', batchTracked: false);
        $this->lot($product, 'LOT-DTO-5', withStock: true);

        $level = StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved' => '0.0000',
        ]);

        $array = StockLevelData::fromModel($level->fresh(['product.unitOfMeasure']) ?? $level)->toArray();

        // Door 2: the flag is off, the lots are not.
        $this->assertFalse($array['requires_batch_tracking']);
        $this->assertTrue($array['has_lots_at_location']);
    }

    // ------------------------------------------------------------- fixtures

    private function product(string $sku, bool $batchTracked): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => "Product {$sku}",
            'type' => ProductType::Part,
            'is_active' => true,
            'requires_batch_tracking' => $batchTracked,
        ]);
    }

    private function lot(Product $product, string $batchNumber, bool $withStock = true): Batch
    {
        $batch = Batch::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
        ]);

        if ($withStock) {
            BatchStock::create([
                'tenant_id' => $this->tenant->id,
                'batch_id' => $batch->id,
                'location_id' => $this->location->id,
                'quantity' => '10.0000',
                'reserved_quantity' => '0.0000',
            ]);
        }

        return $batch;
    }

    private function minimalAdjustment(
        Product $product,
        StockAdjustmentStatus $status,
        ?string $correctsId = null,
    ): StockAdjustment {
        $adjustment = StockAdjustment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'status' => $status,
            'location_id' => $this->location->id,
            'occurred_at' => now(),
            'created_by_user_id' => $this->user->id,
            'corrects_adjustment_id' => $correctsId,
        ]);

        StockAdjustmentLine::create([
            'adjustment_id' => $adjustment->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'reason_code' => MovementReason::AdjustmentPositive,
            'delta_quantity' => '1.0000',
            'observed_before' => '0.0000',
        ]);

        return $adjustment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertContainsOnlyDecimalStrings(array $payload): void
    {
        array_walk_recursive($payload, function (mixed $value, string $key): void {
            if (in_array($key, ['delta_quantity', 'observed_before', 'quantity_before', 'quantity_after'], true)
                && $value !== null) {
                $this->assertIsString($value, "Expected {$key} to serialize as a string.");
                $this->assertMatchesRegularExpression('/^-?\d+\.\d+$/', $value);
            }
        });
    }
}
