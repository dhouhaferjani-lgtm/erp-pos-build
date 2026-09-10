<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

require_once __DIR__.'/StockTransferReceiveTest.php';
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

final class StockTransferReceiveConcurrencyPostgresTest extends TransferReceiptFeatureTestCase
{
    /** @var list<Process> */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Independent committed writers and row locks require PostgreSQL.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            $child->stop(0);
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_two_parallel_receipts_of_eight_of_twelve_yield_one_201_and_one_over_receipt(): void
    {
        $results = $this->race('receive', 'receive');
        self::assertSame([201, 422], array_column($results, 'status'));
        self::assertSame('OVER_RECEIPT', $results[1]['code']);
        self::assertSame('8.0000', $this->destinationQuantity());
        self::assertSame(1, StockTransferReceipt::query()->where('transfer_id', $this->transfer->id)->count());
    }

    public function test_receive_and_close_serialise_in_both_forced_orderings(): void
    {
        foreach ([['receive', 'close'], ['close', 'receive']] as [$first, $second]) {
            $this->transfer = $this->initiate('12.0000');
            $results = $this->race($first, $second);
            self::assertSame([201, $first === 'close' ? 422 : 201], array_column($results, 'status'));
            self::assertSame('closed_returned', $this->transfer->refresh()->status->value);
            self::assertSame('0.0000', $this->transfer->lines->sole()->remainingQuantity());
            self::assertSame($first === 'close' ? '12.0000' : '4.0000', $this->transfer->lines->sole()->quantity_returned);
        }
        self::assertSame('8.0000', $this->destinationQuantity());
        self::assertSame(0, $this->journalCount());
    }

    public function test_receive_and_complete_serialise_and_capitalise_freight_once(): void
    {
        $this->transfer = $this->initiate('12.0000', '120.0000');
        $results = $this->race('receive', 'complete');
        self::assertSame([201, 200], array_column($results, 'status'));
        self::assertSame('completed', $this->transfer->refresh()->status->value);
        self::assertSame('12.0000', $this->destinationQuantity());
        self::assertSame('120.0000', $this->transfer->lines->sole()->allocated_transfer_cost);
        self::assertSame(1, DB::table('stock_movements')->where('reference_id', $this->transfer->id)->where('movement_type', 'adjustment')->count());
    }

    /** @return list<array{status: int, code?: string}> */
    private function race(string $first, string $second): array
    {
        DB::beginTransaction();
        DB::table('stock_transfers')->where('id', $this->transfer->id)->lockForUpdate()->first();
        foreach ([$first, $second] as $index => $action) {
            $tag = 't2-receive-'.$this->transfer->id.'-'.$index;
            $script = <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name', ?, false)", [$argv[6]]);
$app->make(App\Modules\Company\Services\CompanyContext::class)->setCompanyId($argv[3]);
try {
    $action = $argv[5];
    if ($action === 'complete') {
        $app->make(App\Modules\Inventory\Application\Services\StockTransferService::class)->complete($argv[1], $argv[2]);
        echo json_encode(['status' => 200]);
    } else {
        $payload = $action === 'receive'
            ? ['idempotency_key' => $argv[6], 'lines' => [['transfer_line_id' => $argv[4], 'quantity_received' => '8.0000', 'quantity_damaged' => '0.0000']]]
            : ['idempotency_key' => $argv[6], 'disposition' => 'return_to_source', 'reason' => 'other'];
        $app->make(App\Modules\Inventory\Application\Services\StockTransferReceiptService::class)->$action($argv[1], $argv[2], $payload);
        echo json_encode(['status' => 201]);
    }
} catch (App\Modules\Inventory\Domain\Exceptions\TransferReceiptFailureException $e) {
    echo json_encode(['status' => 422, 'code' => $e->reason->value]);
} catch (App\Modules\Inventory\Domain\Exceptions\TransferStateException $e) {
    echo json_encode(['status' => 422, 'code' => 'TRANSFER_STATE']);
}
CHILD;
            $child = new Process([PHP_BINARY, '-r', $script, $this->transfer->id, $this->user->id, $this->company->id, $this->transfer->lines->sole()->id, $action, $tag], base_path(), $this->childEnvironment(), timeout: 30);
            $this->children[] = $child;
            $child->start();
            $deadline = microtime(true) + 8;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$tag]);
                if ($waiting?->wait_event_type === 'Lock' || ! $child->isRunning()) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            self::assertTrue($child->isRunning(), $child->getErrorOutput());
            self::assertSame('Lock', $waiting?->wait_event_type, 'Each writer must demonstrably wait on the held header lock.');
        }
        DB::commit();
        $results = [];
        foreach (array_slice($this->children, -2) as $child) {
            self::assertSame(0, $child->wait(), $child->getErrorOutput());
            $results[] = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
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
