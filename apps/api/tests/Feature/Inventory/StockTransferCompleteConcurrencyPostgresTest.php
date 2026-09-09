<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Committed fixtures + independent writer, matching the idempotency collision harness. */
#[Group('pg')]
final class StockTransferCompleteConcurrencyPostgresTest extends TestCase
{
    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $source;

    private Location $destination;

    private Product $product;

    private ?Process $contender = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Completion row locks and committed writers require PostgreSQL.');
        }
        // No RefreshDatabase: the second process must see committed fixtures.
        if (! Schema::connection('central')->hasTable('tenants')) {
            Artisan::call('migrate', ['--force' => true]);
        }
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->source = Location::factory()->create(['company_id' => $this->company->id]);
        $this->destination = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'cost_price' => '5.0000', 'requires_batch_tracking' => false]);
        app(StockAdjustmentService::class)->receive($this->product->id, $this->source->id, '10.0000', 'T1-PG', $this->user->id, expectedCompanyId: $this->company->id);
    }

    protected function tearDown(): void
    {
        $this->contender?->stop(0);
        if (isset($this->tenant)) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (['stock_transfer_line_batch_allocations', 'stock_transfer_lines', 'stock_transfers', 'stock_movements', 'stock_levels', 'products'] as $table) {
                DB::table($table)->where('tenant_id', $this->tenant->id)->delete();
            }
            DB::table('locations')->where('company_id', $this->company->id)->delete();
            DB::table('users')->where('id', $this->user->id)->delete();
            DB::table('companies')->where('id', $this->company->id)->delete();
            DB::table('tenants')->where('id', $this->tenant->id)->delete();
        }
        parent::tearDown();
    }

    public function test_concurrent_complete_waits_for_row_lock_then_refuses_without_duplicate_stock(): void
    {
        $transfer = $this->transfer();
        DB::beginTransaction();
        // The winner has completed inside its still-uncommitted transaction.
        // The other process sees InTransit and must block on the row lock.
        app(StockTransferService::class)->complete($transfer->id, $this->user->id);
        $tag = 't1-complete-'.$transfer->id;
        $script = <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[4]]);
$app->make(App\Modules\Company\Services\CompanyContext::class)->setCompanyId($argv[3]);
try {
    $app->make(App\Modules\Inventory\Application\Services\StockTransferService::class)->complete($argv[1], $argv[2]);
    echo 'UNEXPECTED_COMPLETION';
    exit(2);
} catch (App\Modules\Inventory\Domain\Exceptions\TransferStateException $e) {
    echo json_encode(['status' => $e->currentStatus->value, 'action' => $e->attemptedAction], JSON_THROW_ON_ERROR);
}
CHILD;
        $this->contender = new Process([PHP_BINARY, '-r', $script, $transfer->id, $this->user->id, $this->company->id, $tag], base_path(), $this->childEnvironment(), timeout: 20);
        $this->contender->start();
        $deadline = microtime(true) + 10;
        $blocked = false;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $row = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$tag]);
            $blocked = $row !== null && $row->wait_event_type === 'Lock';
            if ($blocked || ! $this->contender->isRunning()) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        self::assertTrue($blocked, 'Second process must actually block on PostgreSQL lock: '.$this->contender->getErrorOutput().$this->contender->getOutput());
        DB::commit();
        self::assertSame(0, $this->contender->wait(), $this->contender->getErrorOutput());
        self::assertSame(['status' => 'completed', 'action' => 'complete'], json_decode($this->contender->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('4.0000', StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->destination->id)->sole()->quantity);
        self::assertSame('6.0000', StockLevel::query()->where('product_id', $this->product->id)->where('location_id', $this->source->id)->sole()->quantity);
        self::assertSame(1, StockMovement::query()->where('reference_id', $transfer->id)->where('movement_type', MovementType::TransferIn)->count());
    }

    public function test_completed_status_precedes_freight_capitalization_inside_transaction(): void
    {
        $transfer = $this->transfer('10.000');
        DB::transaction(function () use ($transfer): void {
            app(StockTransferService::class)->complete($transfer->id, $this->user->id);
            self::assertGreaterThan(0, DB::transactionLevel());
            self::assertSame(TransferStatus::Completed, $transfer->refresh()->status);
            // 50 initial value + 10 freight / 10 owned units = 6, never 5.7142 (14 units).
            self::assertSame(0, bccomp('6.0000', $this->product->refresh()->cost_price, 4));
            $cost = StockMovement::query()->where('reference_id', $transfer->id)->where('movement_type', MovementType::Adjustment)->sole();
            self::assertSame('10.0000', $cost->quantity_before);
            self::assertSame('10.0000', $cost->quantity_after);
            self::assertSame(0, bccomp('6.0000', $cost->avg_cost_after, 4));
        });
        self::assertSame(0, bccomp('6.0000', $this->product->refresh()->cost_price, 4));
    }

    private function transfer(string $cost = '0.000'): StockTransfer
    {
        return app(StockTransferService::class)->initiate(new InitiateTransferData($this->tenant->id, $this->company->id, $this->source->id, $this->destination->id, $this->user->id, [new InitiateTransferLineData($this->product->id, '4.0000')], transferCost: $cost));
    }

    /** @return array<string, string> */
    private function childEnvironment(): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $env = ['APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(), 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'pgsql', 'TENANCY_DB_PER_TENANT' => 'false', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr'];
        foreach (['HOST' => 'host', 'PORT' => 'port', 'DATABASE' => 'database', 'USERNAME' => 'username', 'PASSWORD' => 'password'] as $suffix => $key) {
            $env['DB_'.$suffix] = (string) $connection[$key];
            $env['DB_CENTRAL_'.$suffix] = (string) $connection[$key];
        }

        return $env;
    }
}
