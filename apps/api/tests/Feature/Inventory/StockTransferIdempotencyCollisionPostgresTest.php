<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferMovementSupport;
use App\Modules\Inventory\Application\Services\StockTransferReceiptService;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\ProductVariantLookup;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * ID-4 — a REAL PostgreSQL unique-constraint race on stock_transfers.
 *
 * A committed winner row is written on a second connection with the exact same
 * generated transfer number AND the same idempotency key immediately before the
 * loser's real INSERT, so the loser violates BOTH unique indexes. The service
 * must roll back, re-read on (tenant_id, company_id, idempotency_key), and
 * return the committed winner — at transaction depth 0 and inside a caller's
 * transaction (depth 1, savepoint rollback only).
 */
final class StockTransferIdempotencyCollisionPostgresTest extends TestCase
{
    private const WRITER = 'stock_transfer_collision_writer';

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $source;

    private Location $destination;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The real unique-collision harness is PostgreSQL-only.');
        }

        // Rev 9/10 (gate r8 B2, r9 B2): the reserved per-session database is EMPTY on first
        // use and this class deliberately avoids RefreshDatabase. Bootstrap the schema BEFORE
        // any fixture is created, using the same idempotent check + `migrate --force` that
        // tests/Feature/Tenant/TenantStanclFlipTest.php:47-49 performs inline in its setUp()
        // (extracted here into a private helper; that source has no helper of its own).
        $this->ensureCentralSchemaMigrated();

        $suffix = Str::lower(Str::random(10));
        $this->tenant = Tenant::create([
            'name' => 'Collision '.$suffix,
            'slug' => 'collision-'.$suffix,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Collision Company '.$suffix,
            'legal_name' => 'Collision Company '.$suffix,
            'tax_id' => 'COL-'.$suffix,
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'en',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Collision User',
            'email' => 'collision-'.$suffix.'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->source = Location::create([
            'company_id' => $this->company->id,
            'code' => 'SRC-'.$suffix,
            'name' => 'Collision Source',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->destination = Location::create([
            'company_id' => $this->company->id,
            'code' => 'DST-'.$suffix,
            'name' => 'Collision Destination',
            'type' => 'shop',
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'COL-'.$suffix,
            'name' => 'Collision Product',
            'type' => ProductType::Part,
            'is_active' => true,
            'cost_price' => '5.0000',
            'sale_price' => '10.0000',
        ]);
        app(StockAdjustmentService::class)->receive(
            productId: $this->product->id,
            locationId: $this->source->id,
            quantity: '50.0000',
            reference: 'COLLISION-SEED',
            userId: $this->user->id,
        );
    }

    /**
     * Rev 10 (gate r9 B2): extracted from the inline bootstrap in TenantStanclFlipTest::setUp().
     * The plan's private PG database is empty on first use; `migrate --force` runs the central
     * migrations and, under the testing environment, the tenant migrations that
     * AppServiceProvider (lines 227-245) loads for single-connection test runs.
     */
    private function ensureCentralSchemaMigrated(): void
    {
        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        DB::purge(self::WRITER);
        config(['database.connections.'.self::WRITER => null]);
        if (! isset($this->tenant)) {
            parent::tearDown();

            return;
        }
        DB::table('stock_transfer_line_batch_allocations')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_transfer_lines')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_transfers')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_movements')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('stock_levels')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('products')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('locations')->where('company_id', $this->company->id)->delete();
        DB::table('users')->where('id', $this->user->id)->delete();
        DB::table('companies')->where('id', $this->company->id)->delete();
        DB::table('tenants')->where('id', $this->tenant->id)->delete();
        parent::tearDown();
    }

    private function writer(): Connection
    {
        $default = (string) config('database.default');
        config(['database.connections.'.self::WRITER => config('database.connections.'.$default)]);
        DB::purge(self::WRITER);

        return DB::connection(self::WRITER);
    }

    #[Group('pg')]
    public function test_initiate_rereads_committed_winner_after_dual_generated_number_and_key_collision(): void
    {
        $data = new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->source->id,
            destinationLocationId: $this->destination->id,
            initiatedByUserId: $this->user->id,
            lines: [new InitiateTransferLineData($this->product->id, '5.0000')],
            idempotencyKey: 'race-key-'.$this->tenant->id,
        );
        $service = new InsertCollidingStockTransferService(
            app(StockAdjustmentService::class),
            app(ProductCostLock::class),
            app(ProductVariantLookup::class),
            $this->writer(),
            $this->app->make(StockTransferMovementSupport::class),
            $this->app->make(StockTransferReceiptService::class),
        );

        $winner = $service->initiate($data);

        self::assertSame($service->winnerId, $winner->id);
        self::assertSame(1, $service->insertCalls);
        self::assertSame(2, $service->findCalls);
        self::assertSame(0, $service->secondLookupTransactionLevel);
        self::assertSame(1, StockTransfer::query()
            ->where('tenant_id', $data->tenantId)
            ->where('company_id', $data->companyId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->count());
    }

    #[Group('pg')]
    public function test_initiate_inside_outer_transaction_rereads_dual_collision_after_savepoint_rollback(): void
    {
        $data = new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->source->id,
            destinationLocationId: $this->destination->id,
            initiatedByUserId: $this->user->id,
            lines: [new InitiateTransferLineData($this->product->id, '5.0000')],
            idempotencyKey: 'nested-race-key-'.$this->tenant->id,
        );
        $service = new InsertCollidingStockTransferService(
            app(StockAdjustmentService::class),
            app(ProductCostLock::class),
            app(ProductVariantLookup::class),
            $this->writer(),
            $this->app->make(StockTransferMovementSupport::class),
            $this->app->make(StockTransferReceiptService::class),
        );

        $winner = DB::transaction(function () use ($data, $service): StockTransfer {
            self::assertSame(1, DB::transactionLevel());
            $result = $service->initiate($data);
            self::assertSame(1, DB::transactionLevel());

            return $result;
        });

        self::assertSame($service->winnerId, $winner->id);
        self::assertSame(1, $service->insertCalls);
        self::assertSame(2, $service->findCalls);
        self::assertSame(1, $service->secondLookupTransactionLevel);
        self::assertSame(0, DB::transactionLevel());
        self::assertSame(1, StockTransfer::query()
            ->where('tenant_id', $data->tenantId)
            ->where('company_id', $data->companyId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->count());
    }
}

final class InsertCollidingStockTransferService extends StockTransferService
{
    public int $findCalls = 0;

    public int $insertCalls = 0;

    public ?int $secondLookupTransactionLevel = null;

    public string $winnerId = '';

    public function __construct(
        StockAdjustmentService $stockAdjustmentService,
        ProductCostLock $costLock,
        ProductVariantLookup $variantLookup,
        private readonly Connection $writer,
        StockTransferMovementSupport $movementSupport,
        StockTransferReceiptService $receiptService,
    ) {
        parent::__construct($stockAdjustmentService, $costLock, $variantLookup, $movementSupport, $receiptService);
    }

    protected function findExistingTransfer(InitiateTransferData $data): ?StockTransfer
    {
        $this->findCalls++;
        if ($this->findCalls === 1) {
            return null;
        }
        $this->secondLookupTransactionLevel = DB::transactionLevel();

        return parent::findExistingTransfer($data);
    }

    protected function insertTransfer(InitiateTransferData $data, string $transferNumber): StockTransfer
    {
        $this->insertCalls++;
        $this->winnerId = Str::uuid()->toString();
        $this->writer->table('stock_transfers')->insert([
            'id' => $this->winnerId,
            'tenant_id' => $data->tenantId,
            'company_id' => $data->companyId,
            'transfer_number' => $transferNumber,
            'transfer_type' => $data->transferType->value,
            'status' => TransferStatus::Draft->value,
            'source_location_id' => $data->sourceLocationId,
            'destination_location_id' => $data->destinationLocationId,
            'notes' => $data->notes,
            'transfer_cost' => $data->transferCost,
            'transfer_cost_label' => $data->transferCostLabel,
            'transfer_cost_distribution' => $data->transferCostDistribution->value,
            'idempotency_key' => $data->idempotencyKey,
            'initiated_by_user_id' => $data->initiatedByUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return parent::insertTransfer($data, $transferNumber);
    }
}
