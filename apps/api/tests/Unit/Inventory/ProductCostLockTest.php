<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\Services\ProductCostLock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductCostLockTest extends TestCase
{
    public function test_acquire_runs_callback_and_returns_its_value(): void
    {
        $lock = new ProductCostLock;
        $result = DB::transaction(fn () => $lock->acquire('t', 'c', ['p2', 'p1'], fn () => 'ok'));
        $this->assertSame('ok', $result);
    }

    public function test_acquire_is_noop_on_non_pgsql_driver(): void
    {
        // On the SQLite test runner this must not throw and must still run the callback.
        $lock = new ProductCostLock;
        $ran = false;
        DB::transaction(function () use ($lock, &$ran): void {
            $lock->acquire('t', 'c', ['p1'], function () use (&$ran): void {
                $ran = true;
            });
        });
        $this->assertTrue($ran);
    }
}
