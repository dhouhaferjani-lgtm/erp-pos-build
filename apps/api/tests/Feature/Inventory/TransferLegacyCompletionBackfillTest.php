<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\LocationStockQueryService;
use App\Modules\Inventory\Application\Services\StockMatrixQueryService;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferReceiptLine;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class TransferLegacyCompletionBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** Literal identities keep the historical snapshot reproducible. */
    private const IDS = [
        '00000001-0000-4000-8000-000000000001',
        '00000002-0000-4000-8000-000000000001',
        '00000003-0000-4000-8000-000000000001',
        '00000004-0000-4000-8000-000000000001',
        '00000005-0000-4000-8000-000000000001',
        '00000006-0000-4000-8000-000000000001',
        '00000007-0000-4000-8000-000000000001',
        '00000008-0000-4000-8000-000000000001',
        '00000009-0000-4000-8000-000000000001',
        '0000000a-0000-4000-8000-000000000001',
        '0000000b-0000-4000-8000-000000000001',
        '0000000c-0000-4000-8000-000000000001',
        '0000000d-0000-4000-8000-000000000001',
        '0000000e-0000-4000-8000-000000000001',
        '0000000f-0000-4000-8000-000000000001',
        '00000010-0000-4000-8000-000000000001',
        '00000011-0000-4000-8000-000000000001',
        '00000012-0000-4000-8000-000000000001',
        '00000013-0000-4000-8000-000000000001',
        '00000014-0000-4000-8000-000000000001',
        '00000015-0000-4000-8000-000000000001',
        '00000016-0000-4000-8000-000000000001',
        '00000017-0000-4000-8000-000000000001',
        '00000018-0000-4000-8000-000000000001',
        '00000019-0000-4000-8000-000000000001',
        '0000001a-0000-4000-8000-000000000001',
        '0000001b-0000-4000-8000-000000000001',
        '0000001c-0000-4000-8000-000000000001',
        '0000001d-0000-4000-8000-000000000001',
        '0000001e-0000-4000-8000-000000000001',
        '0000001f-0000-4000-8000-000000000001',
        '00000020-0000-4000-8000-000000000001',
        '00000021-0000-4000-8000-000000000001',
        '00000022-0000-4000-8000-000000000001',
        '00000023-0000-4000-8000-000000000001',
        '00000024-0000-4000-8000-000000000001',
        '00000025-0000-4000-8000-000000000001',
        '00000026-0000-4000-8000-000000000001',
        '00000027-0000-4000-8000-000000000001',
        '00000028-0000-4000-8000-000000000001',
        '00000029-0000-4000-8000-000000000001',
        '0000002a-0000-4000-8000-000000000001',
        '0000002b-0000-4000-8000-000000000001',
        '0000002c-0000-4000-8000-000000000001',
        '0000002d-0000-4000-8000-000000000001',
        '0000002e-0000-4000-8000-000000000001',
        '0000002f-0000-4000-8000-000000000001',
        '00000030-0000-4000-8000-000000000001',
        '00000031-0000-4000-8000-000000000001',
        '00000032-0000-4000-8000-000000000001',
        '00000033-0000-4000-8000-000000000001',
        '00000034-0000-4000-8000-000000000001',
        '00000035-0000-4000-8000-000000000001',
        '00000036-0000-4000-8000-000000000001',
        '00000037-0000-4000-8000-000000000001',
        '00000038-0000-4000-8000-000000000001',
        '00000039-0000-4000-8000-000000000001',
        '0000003a-0000-4000-8000-000000000001',
        '0000003b-0000-4000-8000-000000000001',
        '0000003c-0000-4000-8000-000000000001',
        '0000003d-0000-4000-8000-000000000001',
        '0000003e-0000-4000-8000-000000000001',
        '0000003f-0000-4000-8000-000000000001',
        '00000040-0000-4000-8000-000000000001',
        '00000041-0000-4000-8000-000000000001',
        '00000042-0000-4000-8000-000000000001',
        '00000043-0000-4000-8000-000000000001',
        '00000044-0000-4000-8000-000000000001',
        '00000045-0000-4000-8000-000000000001',
        '00000046-0000-4000-8000-000000000001',
        '00000047-0000-4000-8000-000000000001',
        '00000048-0000-4000-8000-000000000001',
        '00000049-0000-4000-8000-000000000001',
        '0000004a-0000-4000-8000-000000000001',
        '0000004b-0000-4000-8000-000000000001',
        '0000004c-0000-4000-8000-000000000001',
        '0000004d-0000-4000-8000-000000000001',
        '0000004e-0000-4000-8000-000000000001',
        '0000004f-0000-4000-8000-000000000001',
        '00000050-0000-4000-8000-000000000001',
    ];

    private const BASELINE = 'tests/Fixtures/Inventory/transfer-reader-baseline.json';

    /** @var array<string, array{locations: list<string>, products: list<string>}> */
    private array $fixture = [];

    public function test_capture_the_pre_change_reader_baseline(): void
    {
        if (getenv('T2T3_CAPTURE_BASELINE') !== '1') {
            self::markTestSkipped('capture-only; set T2T3_CAPTURE_BASELINE=1 on the PRE-CHANGE tree');
        }
        self::assertNotFalse(getenv('T2T3_CAPTURE_BASELINE'), 'capture-only method');
        $this->seedHistoricalFixture();
        $path = base_path(self::BASELINE);
        $baseline = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
        $baseline[DB::getDriverName()] = ['sites' => $this->readerAnswers()];
        ksort($baseline);
        $json = json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $json);
        self::assertGreaterThan(0, strlen($json));
        self::assertSame(4, count($baseline[DB::getDriverName()]['sites']));
    }

    public function test_historical_reader_answers_survive_the_backfill_and_the_reader_switch(): void
    {
        self::assertSame(['in_transit', 'partially_received'], StockTransfer::CARRYING_STATUSES, 'the reader switch must be in place before this regression can mean anything');
        $this->seedHistoricalFixture();
        $baseline = json_decode(file_get_contents(base_path(self::BASELINE)), true, flags: JSON_THROW_ON_ERROR)[DB::getDriverName()];
        $migration = require database_path('migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php');
        $migration->up();
        // Prove the intended changed answer independently, then restore that
        // one fixture row before comparing all historical answers byte-for-byte.
        $carrying = DB::table('stock_transfers')->where('status', 'in_transit')->where('transfer_number', 'BASE-in_transit-plain')->orderBy('id')->first();
        $carryingLine = DB::table('stock_transfer_lines')->where('transfer_id', $carrying->id)->sole();
        DB::table('stock_transfers')->where('id', $carrying->id)->update(['status' => 'partially_received']);
        DB::table('stock_transfer_lines')->where('id', $carryingLine->id)->update(['quantity' => '12.0000', 'quantity_received' => '7.0000']);
        $changed = $this->readerAnswers();
        self::assertSame('5.0000', $changed['location_stock_grouped'][$carrying->company_id][$carrying->destination_location_id][$carryingLine->product_id.'|']);
        self::assertSame('5.0000', $changed['location_stock_distribution'][$carrying->company_id][$carryingLine->product_id][$carrying->destination_location_id]);
        self::assertSame('5.0000', $changed['stock_matrix_incoming'][$carrying->company_id][$carryingLine->product_id.'||'.$carrying->destination_location_id]);
        self::assertSame(bcsub($baseline['sites']['wac_owned_quantity'][$carrying->company_id][$carryingLine->product_id], '7.4517', 7), $changed['wac_owned_quantity'][$carrying->company_id][$carryingLine->product_id]);
        DB::table('stock_transfers')->where('id', $carrying->id)->update(['status' => 'in_transit']);
        DB::table('stock_transfer_lines')->where('id', $carryingLine->id)->update(['quantity' => $carryingLine->quantity, 'quantity_received' => '0.0000']);
        $after = $this->readerAnswers();
        self::assertSame($baseline['sites']['location_stock_grouped'], $after['location_stock_grouped']);
        self::assertSame($baseline['sites']['location_stock_distribution'], $after['location_stock_distribution']);
        self::assertSame($baseline['sites']['stock_matrix_incoming'], $after['stock_matrix_incoming']);
        self::assertSame($baseline['sites']['wac_owned_quantity'], $after['wac_owned_quantity']);
    }

    public function test_every_completed_transfer_gets_one_legacy_receipt_and_rerun_adds_nothing(): void
    {
        $this->seedHistoricalFixture();
        $beforeEvents = DB::table('stored_events')->count();
        $migration = require database_path('migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php');
        $migration->up();
        self::assertSame(4, DB::table('stock_transfer_receipts')->where('kind', 'legacy_completion')->count());
        $before = $this->backfillSnapshot();
        $migration->up();
        self::assertSame($before, $this->backfillSnapshot());
        self::assertSame($beforeEvents, DB::table('stored_events')->count());
        foreach ($this->fixture as $companyId => $fixture) {
            self::assertSame(2, DB::table('stock_transfer_receipts')->where('company_id', $companyId)->count());
        }
    }

    public function test_lot_grain_follows_the_shipment_not_the_current_product_flag(): void
    {
        $this->seedHistoricalFixture();
        $query = StockTransferReceiptLine::query();
        foreach (DB::table('products')->select('id', 'requires_batch_tracking')->get() as $product) {
            DB::table('products')->where('id', $product->id)->update(['requires_batch_tracking' => ! $product->requires_batch_tracking]);
        }
        (require database_path('migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php'))->up();
        foreach ($query->get() as $line) {
            $shippedLots = DB::table('stock_transfer_line_batch_allocations')->where('stock_transfer_line_id', $line->transfer_line_id)->count();
            self::assertSame($shippedLots > 0, $line->is_lot_tracked);
            self::assertSame($shippedLots, DB::table('stock_transfer_receipt_line_lots')->where('receipt_line_id', $line->id)->count());
            self::assertNull($line->in_movement_id, 'This historical fixture has no matching transfer-in movement.');
        }
    }

    public function test_legacy_movement_resolution_requires_one_match_at_the_destination_and_shipped_lot(): void
    {
        $this->seedHistoricalFixture();
        $expected = [];
        $index = 0;
        foreach (StockTransfer::query()->where('status', 'completed')->with('lines.batchAllocations')->orderBy('id')->get() as $transfer) {
            $line = $transfer->lines->sole();
            $batchId = $line->batchAllocations->first()?->batch_id;
            $writer = $this->app->make(StockAdjustmentService::class);
            $this->app->make(CompanyContext::class)->setCompanyId($transfer->company_id);
            $movement = $writer->receive($line->product_id, $transfer->destination_location_id, $line->quantity, $transfer->transfer_number, $transfer->initiated_by_user_id, batchId: $batchId, expectedCompanyId: $transfer->company_id, movementType: MovementType::TransferIn, transferId: $transfer->id);
            // A transfer-linked movement at the source cannot be selected.
            $writer->receive($line->product_id, $transfer->source_location_id, '1.0000', $transfer->transfer_number, $transfer->initiated_by_user_id, batchId: $batchId, expectedCompanyId: $transfer->company_id, movementType: MovementType::TransferIn, transferId: $transfer->id);
            $expected[$line->id] = $index < 2 ? $movement->id : null;
            if ($index >= 2) {
                $writer->receive($line->product_id, $transfer->destination_location_id, '1.0000', $transfer->transfer_number, $transfer->initiated_by_user_id, batchId: $batchId, expectedCompanyId: $transfer->company_id, movementType: MovementType::TransferIn, transferId: $transfer->id);
            }
            $index++;
        }
        (require database_path('migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php'))->up();
        foreach (DB::table('stock_transfer_receipt_lines')->get() as $line) {
            if ($line->is_lot_tracked) {
                self::assertNull($line->in_movement_id);
                self::assertSame($expected[$line->transfer_line_id], DB::table('stock_transfer_receipt_line_lots')->where('receipt_line_id', $line->id)->sole()->in_movement_id);
            } else {
                self::assertSame($expected[$line->transfer_line_id], $line->in_movement_id);
            }
        }
    }

    public function test_interrupted_backfill_rolls_back_and_the_next_run_completes(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Migration transaction failure requires PostgreSQL.');
        }
        $this->seedHistoricalFixture();
        self::assertSame(0, DB::table('stock_transfer_receipts')->count());
        $failOnce = true;
        DB::listen(static function (QueryExecuted $query) use (&$failOnce): void {
            if ($failOnce && str_starts_with(strtolower($query->sql), 'insert into "stock_transfer_receipt_lines"')) {
                $failOnce = false;
                throw new \RuntimeException('T11 interrupted legacy backfill');
            }
        });
        $migration = require database_path('migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php');
        try {
            DB::transaction(static fn () => $migration->up());
            self::fail('The seeded interruption must fire.');
        } catch (\RuntimeException $exception) {
            self::assertSame('T11 interrupted legacy backfill', $exception->getMessage());
        }
        self::assertSame(0, DB::table('stock_transfer_receipts')->count());
        self::assertSame(0, DB::table('stock_transfer_receipt_lines')->count());
        DB::transaction(static fn () => $migration->up());
        self::assertSame(4, DB::table('stock_transfer_receipts')->count());
    }

    public function test_rerun_does_not_reclassify_modern_damage_as_good_received(): void
    {
        $this->seedHistoricalFixture();
        $migration = require database_path('migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php');
        $migration->up();
        DB::table('stock_transfer_receipts')->update(['kind' => 'receipt']);
        DB::table('stock_transfer_lines')->whereIn('transfer_id', DB::table('stock_transfer_receipts')->select('transfer_id'))
            ->update(['quantity_received' => '0.0000', 'quantity_damaged' => DB::raw('quantity')]);
        DB::table('stock_transfer_line_batch_allocations')->whereIn('stock_transfer_line_id', DB::table('stock_transfer_lines')->select('id')->where('quantity_damaged', '>', 0))
            ->update(['quantity_received' => '0.0000', 'quantity_damaged' => DB::raw('quantity')]);
        $before = $this->backfillSnapshot();
        $migration->up();
        self::assertSame($before, $this->backfillSnapshot());
        self::assertSame(0, DB::table('stock_transfer_lines')->where('quantity_damaged', '>', 0)->where('quantity_received', '>', 0)->count());
    }

    /** @return array<string, string> */
    private function backfillSnapshot(): array
    {
        $snapshot = [];
        foreach (['stock_transfer_receipts', 'stock_transfer_receipt_lines', 'stock_transfer_receipt_line_lots', 'stock_transfer_lines', 'stock_transfer_line_batch_allocations'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    private function seedHistoricalFixture(): void
    {
        $this->travelTo(Carbon::parse('2026-09-09 12:00:00 UTC'));
        $tenant = Tenant::factory()->create(['id' => self::IDS[0]]);
        $user = User::factory()->create(['id' => self::IDS[1], 'tenant_id' => $tenant->id]);
        $cursor = 2;
        foreach (['A', 'B'] as $companyName) {
            $company = Company::factory()->for($tenant)->create(['id' => self::IDS[$cursor++], 'name' => $companyName, 'currency' => 'TND']);
            $this->app->make(CompanyContext::class)->setCompanyId($company->id);
            $locations = [];
            foreach (['L1', 'L2'] as $name) {
                $locations[] = Location::factory()->create(['id' => self::IDS[$cursor++], 'company_id' => $company->id, 'name' => $name, 'type' => 'warehouse', 'is_active' => true, 'pos_enabled' => true])->id;
            }
            $products = [];
            foreach (['completed', 'cancelled', 'in_transit'] as $status) {
                foreach ([false, true] as $lotTracked) {
                    $product = Product::factory()->create(['id' => self::IDS[$cursor++], 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'name' => $status.($lotTracked ? '-lot' : '-plain'), 'cost_price' => '5.000000', 'requires_batch_tracking' => $lotTracked]);
                    $products[] = $product->id;
                    $transferId = self::IDS[$cursor++];
                    DB::table('stock_transfers')->insert(['id' => $transferId, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'transfer_number' => 'BASE-'.$status.($lotTracked ? '-lot' : '-plain'), 'status' => $status, 'source_location_id' => $locations[0], 'destination_location_id' => $locations[1], 'initiated_by_user_id' => $user->id, 'completed_by_user_id' => $status === 'completed' ? $user->id : null, 'initiated_at' => now(), 'completed_at' => $status === 'completed' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                    $lineId = self::IDS[$cursor++];
                    DB::table('stock_transfer_lines')->insert(['id' => $lineId, 'transfer_id' => $transferId, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'product_id' => $product->id, 'quantity' => '12.4517', 'unit_cost_snapshot' => '5.0000', 'created_at' => now(), 'updated_at' => now()]);
                    if ($lotTracked) {
                        $batch = Batch::create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'product_id' => $product->id, 'batch_number' => 'BASE-'.$status, 'expiry_date' => '2028-01-01', 'is_active' => true, 'is_expired' => false, 'is_recalled' => false]);
                        DB::table('stock_transfer_line_batch_allocations')->insert(['id' => self::IDS[$cursor++], 'stock_transfer_line_id' => $lineId, 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'batch_id' => $batch->id, 'quantity' => '12.4517', 'created_at' => now(), 'updated_at' => now()]);
                    }
                    foreach ($locations as $index => $locationId) {
                        DB::table('stock_levels')->insert(['id' => self::IDS[$cursor++], 'tenant_id' => $tenant->id, 'company_id' => $company->id, 'product_id' => $product->id, 'location_id' => $locationId, 'quantity' => $index === 0 ? '20.0000' : ($status === 'completed' ? '12.4517' : '0.0000'), 'reserved' => '0.0000', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
            $this->fixture[$company->id] = ['locations' => $locations, 'products' => $products];
        }
    }

    /** @return array<string, array<string, array<string, string|array<string, string>>>> */
    private function readerAnswers(): array
    {
        $sites = ['location_stock_grouped' => [], 'location_stock_distribution' => [], 'stock_matrix_incoming' => [], 'wac_owned_quantity' => []];
        $reader = $this->app->make(LocationStockQueryService::class);
        $matrix = $this->app->make(StockMatrixQueryService::class);
        $wac = $this->app->make(WeightedAverageCostService::class);
        foreach ($this->fixture as $companyId => $fixture) {
            $this->app->make(CompanyContext::class)->setCompanyId($companyId);
            foreach ($fixture['locations'] as $locationId) {
                $incoming = [];
                foreach ($reader->read(self::IDS[0], $companyId, $locationId, null, 1, 100)->incoming as $row) {
                    $incoming[$row->productId.'|'.($row->variantId ?? '')] = $row->incomingTransfer;
                }
                ksort($incoming);
                $sites['location_stock_grouped'][$companyId][$locationId] = $incoming;
            }
            foreach ($fixture['products'] as $productId) {
                $distribution = $reader->stockDistributionForProduct(self::IDS[0], $companyId, $productId, null, $fixture['locations'][0]);
                $incoming = [];
                foreach ($distribution->locations as $row) {
                    $incoming[$row->locationId] = $row->incomingTransfer;
                }
                ksort($incoming);
                $sites['location_stock_distribution'][$companyId][$productId] = $incoming;
                $sites['wac_owned_quantity'][$companyId][$productId] = (new ReflectionMethod($wac, 'companyOwnedQuantity'))->invoke($wac, $productId, self::IDS[0], $companyId);
            }
            $incoming = (new ReflectionMethod($matrix, 'incoming'))->invoke($matrix, self::IDS[0], $companyId, $fixture['products'], $fixture['locations']);
            ksort($incoming);
            $sites['stock_matrix_incoming'][$companyId] = $incoming;
        }

        return $sites;
    }
}
