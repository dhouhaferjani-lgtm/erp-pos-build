<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Infrastructure\Runtime;

use Closure;

function register_shutdown_function(Closure $handler): void
{
    PhpExecutionTimeLimitShutdownRegistry::register($handler);
}

final class PhpExecutionTimeLimitShutdownRegistry
{
    /** @var list<Closure> */
    private static array $handlers = [];

    public static function register(Closure $handler): void
    {
        self::$handlers[] = $handler;
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }

    /** @return list<Closure> */
    public static function handlers(): array
    {
        return self::$handlers;
    }
}

namespace Tests\Unit\Modules\Tenant;

use App\Modules\Tenant\Infrastructure\Runtime\PhpExecutionTimeLimit;
use App\Modules\Tenant\Infrastructure\Runtime\PhpExecutionTimeLimitShutdownRegistry;
use PHPUnit\Framework\TestCase;

final class PhpExecutionTimeLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PhpExecutionTimeLimitShutdownRegistry::reset();
    }

    public function test_one_process_handler_dispatches_only_the_current_pending_compensation(): void
    {
        $runtime = new PhpExecutionTimeLimit;
        $firstCalls = 0;
        $secondCalls = 0;
        $clearedCalls = 0;

        $runtime->registerShutdownHandler(function () use (&$firstCalls): void {
            $firstCalls++;
        });
        $runtime->registerShutdownHandler(function () use (&$secondCalls): void {
            $secondCalls++;
        });

        $handlers = PhpExecutionTimeLimitShutdownRegistry::handlers();
        self::assertCount(1, $handlers);

        $handlers[0]();
        $handlers[0]();

        self::assertSame(0, $firstCalls);
        self::assertSame(1, $secondCalls);

        $runtime->registerShutdownHandler(function () use (&$clearedCalls): void {
            $clearedCalls++;
        });
        $runtime->clear();
        $handlers[0]();

        self::assertCount(1, PhpExecutionTimeLimitShutdownRegistry::handlers());
        self::assertSame(0, $clearedCalls);
    }
}
