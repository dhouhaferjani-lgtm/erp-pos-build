<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\CreateStockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\OpeningBalanceLine;
use App\Modules\Inventory\Application\DTOs\OpeningBalancePosting;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentLineInput;
use App\Modules\Inventory\Application\Services\OpeningBalancePostingService;
use App\Modules\Inventory\Application\Services\StockAdjustmentDocumentService;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\OpeningLotExpiryOutcome;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockAdjustment;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * L-1 blast-radius pins: receipt-only expiry conflict policy must not change
 * DEFAULT-lot or explicit stock-adjustment reuse semantics.
 */
final class DefaultLotExpiryDriftRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $main;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'L1 Blast Radius Tenant',
            'slug' => 'l1-blast-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'L1 Blast Radius Company',
            'legal_name' => 'L1 Blast Radius Company LLC',
            'tax_id' => 'L1-BLAST-'.uniqid(),
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'L1 Blast Radius User',
            'email' => 'l1-blast-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->main = $this->location('MAIN');
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'L1-DEFAULT-DRIFT',
            'name' => 'L1 Default Drift Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.000000',
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 180,
        ]);

        foreach ([
            ['3100', 'Inventory', AccountType::Asset, SystemAccountPurpose::Inventory],
            ['3900', 'Opening Balance Equity', AccountType::Equity, SystemAccountPurpose::OpeningBalanceEquity],
        ] as [$code, $name, $type, $purpose]) {
            Account::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'system_purpose' => $purpose,
                'is_active' => true,
                'is_system' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_next_day_positive_adjustment_reuses_default_lot_despite_derived_expiry_drift(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        $service = app(StockAdjustmentService::class);
        $service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->main->id,
            deltaQuantity: '1.0000',
            reference: 'L1-DEFAULT-DAY-1',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $default = $this->defaultBatch();
        $originalExpiry = $default->expiry_date?->toDateString();

        Carbon::setTestNow('2026-09-02 10:00:00');
        $service->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->main->id,
            deltaQuantity: '1.0000',
            reference: 'L1-DEFAULT-DAY-2',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
        );

        $this->assertSame(1, Batch::where('product_id', $this->product->id)->count());
        $this->assertSame($default->id, $this->defaultBatch()->id);
        $this->assertSame($originalExpiry, $this->defaultBatch()->expiry_date?->toDateString());
        $this->assertSame('2.0000', $this->batchQuantity($default));
    }

    public function test_opening_balance_reports_conflicting_default_expiry_and_still_posts(): void
    {
        $annex = $this->location('ANNEX');

        $first = $this->postOpening($this->main, '2027-05-31', 'L1 opening first');
        $second = $this->postOpening($annex, '2028-01-31', 'L1 opening conflict');

        $this->assertSame(OpeningLotExpiryOutcome::Applied, $first);
        $this->assertSame(OpeningLotExpiryOutcome::ConflictExistingLot, $second);
        $this->assertSame('2027-05-31', $this->defaultBatch()->expiry_date?->toDateString());
        $this->assertSame(2, BatchStock::where('batch_id', $this->defaultBatch()->id)->count());
    }

    public function test_explicit_lot_stock_adjustment_still_reuses_a_conflicting_expiry(): void
    {
        $batchService = app(BatchStockService::class);
        $batch = $batchService->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-EXPLICIT',
            expiryDate: '2027-05-31',
        );
        $reused = $batchService->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-EXPLICIT',
            expiryDate: '2028-01-31',
        );

        app(StockAdjustmentService::class)->adjustByDelta(
            productId: $this->product->id,
            locationId: $this->main->id,
            deltaQuantity: '1.0000',
            reference: 'L1-EXPLICIT-LOT',
            userId: $this->user->id,
            reasonCode: MovementReason::AdjustmentPositive,
            batchId: $reused->id,
        );

        $this->assertSame($batch->id, $reused->id);
        $this->assertSame('2027-05-31', $reused->expiry_date?->toDateString());
        $this->assertSame(1, Batch::where('product_id', $this->product->id)->count());
        $this->assertSame('1.0000', $this->batchQuantity($batch));
    }

    public function test_past_dated_opening_lot_stays_adjustable_until_the_expiry_job_marks_it(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        $outcome = $this->postOpening($this->main, '2020-01-01', 'L1 past opening');
        $batch = $this->defaultBatch();

        $this->assertSame(OpeningLotExpiryOutcome::Applied, $outcome);
        $this->assertFalse($batch->is_expired);

        $adjustment = $this->positiveAdjustmentFor($batch, '1.0000');
        app(StockAdjustmentDocumentService::class)->post($adjustment->id, $this->user->id);

        $this->assertSame('2.0000', $this->batchQuantity($batch));
    }

    public function test_past_dated_explicit_lot_stays_adjustable_when_minted_outside_receiving(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');

        $batch = app(BatchStockService::class)->findOrCreateBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            batchNumber: 'LOT-PAST-ADJUSTMENT',
            expiryDate: '2020-01-01',
        );
        $this->assertFalse($batch->is_expired);

        $adjustment = $this->positiveAdjustmentFor($batch, '1.0000');
        app(StockAdjustmentDocumentService::class)->post($adjustment->id, $this->user->id);

        $this->assertSame('1.0000', $this->batchQuantity($batch));
    }

    private function location(string $code): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => "L1 {$code}",
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => $code === 'MAIN',
        ]);
    }

    private function defaultBatch(): Batch
    {
        return Batch::query()
            ->where('product_id', $this->product->id)
            ->where('batch_number', BatchStockService::DEFAULT_BATCH_NUMBER)
            ->firstOrFail();
    }

    private function batchQuantity(Batch $batch): string
    {
        return (string) BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->main->id)
            ->firstOrFail()
            ->quantity;
    }

    /** @param numeric-string $quantity */
    private function positiveAdjustmentFor(Batch $batch, string $quantity): StockAdjustment
    {
        return DB::transaction(fn (): StockAdjustment => app(StockAdjustmentDocumentService::class)->createDraft(
            new CreateStockAdjustmentData(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                locationId: $this->main->id,
                createdByUserId: $this->user->id,
                lines: [new StockAdjustmentLineInput(
                    productId: $this->product->id,
                    variantId: null,
                    batchUuid: (string) $batch->uuid,
                    reasonCode: MovementReason::AdjustmentPositive,
                    deltaQuantity: $quantity,
                    observedBefore: $this->batchQuantityOrZero($batch),
                )],
            ),
        ));
    }

    /** @return numeric-string */
    private function batchQuantityOrZero(Batch $batch): string
    {
        $quantity = (string) (BatchStock::query()
            ->where('batch_id', $batch->id)
            ->where('location_id', $this->main->id)
            ->value('quantity') ?? '0.0000');

        return is_numeric($quantity) ? $quantity : '0.0000';
    }

    private function postOpening(Location $location, string $expiryDate, string $reference): OpeningLotExpiryOutcome
    {
        $result = app(OpeningBalancePostingService::class)->post(new OpeningBalancePosting(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            entryDate: Carbon::parse('2026-09-01'),
            isHistorical: true,
            sourceType: 'opening_balance',
            sourceId: $this->product->id,
            reference: $reference,
            notes: null,
            lines: [OpeningBalanceLine::make(
                $this->product->id,
                null,
                $location->id,
                '1.0000',
                '5.000',
                3,
                $expiryDate,
            )],
        ));

        return $result->expiryOutcomesInInputOrder[0];
    }
}
