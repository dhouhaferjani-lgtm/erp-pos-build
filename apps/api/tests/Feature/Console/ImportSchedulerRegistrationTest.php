<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * SG-6-FU: the import maintenance sweeps only ever run because `routes/console.php`
 * registers them. A silent edit to that file (or a fluent-helper swap such as
 * `everyFiveMinutes()` -> `everyTenMinutes()`) would leave abandoned imports stuck
 * in `importing` and source files retained past the 90-day policy window, with no
 * failing test anywhere. These assertions pin the two cadences to their exact cron
 * expressions so any change to them has to be deliberate.
 *
 * No database is required: the assertions read the in-memory Schedule the framework
 * builds at boot, so this class runs on the default SQLite lane.
 */
final class ImportSchedulerRegistrationTest extends TestCase
{
    public function test_reap_stuck_imports_is_scheduled_every_five_minutes(): void
    {
        $event = $this->scheduledEventFor('imports:reap-stuck');

        $this->assertSame('*/5 * * * *', $event->getExpression());
    }

    public function test_purge_expired_import_artifacts_is_scheduled_daily_at_midnight(): void
    {
        $event = $this->scheduledEventFor('imports:purge-expired');

        $this->assertSame('0 0 * * *', $event->getExpression());
    }

    /**
     * Resolves the single scheduled entry whose command line carries the given
     * artisan signature, failing loudly if the sweep is unregistered or duplicated.
     */
    private function scheduledEventFor(string $signature): Event
    {
        $schedule = $this->app->make(Schedule::class);
        self::assertInstanceOf(Schedule::class, $schedule);

        $matches = array_values(array_filter(
            $schedule->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, $signature),
        ));

        $this->assertCount(1, $matches, sprintf('`%s` must be scheduled exactly once.', $signature));

        return $matches[0];
    }
}
