<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use App\Console\Commands\SweepInventoryBlockCommand;
use App\Console\Commands\SweepInventoryClaimCommand;
use App\Console\Commands\SweepInventoryDeferCommand;
use App\Console\Commands\SweepInventoryGenerateCommand;
use App\Console\Commands\SweepInventoryRecheckClearCommand;
use App\Console\Commands\SweepInventoryReviewCommand;
use App\Console\Commands\SweepInventoryStartCommand;
use App\Console\Commands\SweepInventoryStatusCommand;
use App\Console\Commands\SweepInventorySubmitCommand;
use App\Console\Commands\SweepInventoryUnblockCommand;
use App\Console\Commands\SweepInventoryVerifyHistoryCommand;
use Tests\TestCase;

/**
 * Sweep tooling mutates planning YAML in docs/superpowers and is never invoked
 * from HTTP, queues, schedulers, or production runbooks. M1.4 of the dev
 * go-live remediation plan gates the commands so they are hidden and disabled
 * when APP_ENV=production. This test asserts both:
 *   - in production, the commands return isEnabled() === false so they are
 *     omitted from `php artisan list` and rejected on invocation
 *   - in any non-production environment, the commands remain enabled
 */
class SweepCommandsProductionGateTest extends TestCase
{
    /** @return array<int, array{0: class-string}> */
    public static function sweepCommandProvider(): array
    {
        return [
            [SweepInventoryBlockCommand::class],
            [SweepInventoryClaimCommand::class],
            [SweepInventoryDeferCommand::class],
            [SweepInventoryGenerateCommand::class],
            [SweepInventoryRecheckClearCommand::class],
            [SweepInventoryReviewCommand::class],
            [SweepInventoryStartCommand::class],
            [SweepInventoryStatusCommand::class],
            [SweepInventorySubmitCommand::class],
            [SweepInventoryUnblockCommand::class],
            [SweepInventoryVerifyHistoryCommand::class],
        ];
    }

    /**
     * @dataProvider sweepCommandProvider
     *
     * @param  class-string  $commandClass
     */
    public function test_sweep_command_is_disabled_in_production(string $commandClass): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $command = new $commandClass;
        $command->setLaravel($this->app);

        $this->assertFalse(
            $command->isEnabled(),
            "{$commandClass} must be disabled when APP_ENV=production so it is not exposed in `php artisan list` or executable on production runbooks.",
        );
    }

    /**
     * @dataProvider sweepCommandProvider
     *
     * @param  class-string  $commandClass
     */
    public function test_sweep_command_is_enabled_in_non_production(string $commandClass): void
    {
        foreach (['local', 'staging', 'testing', 'development'] as $environment) {
            $this->app->detectEnvironment(static fn () => $environment);

            $command = new $commandClass;
            $command->setLaravel($this->app);

            $this->assertTrue(
                $command->isEnabled(),
                "{$commandClass} must remain enabled in {$environment} so dev/CI sweep workflow keeps running.",
            );
        }
    }
}
