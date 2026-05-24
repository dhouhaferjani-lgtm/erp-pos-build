<?php

declare(strict_types=1);

namespace App\Modules\Channel\Providers;

use App\Modules\Channel\Application\Jobs\ChannelReconciliationJob;
use App\Modules\Channel\Application\Listeners\DispatchStockChangeToChannels;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Inventory\Domain\Events\StockMovementRecorded;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

final class ChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');

        Event::listen(StockMovementRecorded::class, DispatchStockChangeToChannels::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->call(function (): void {
                if (! Schema::hasTable('channels')) {
                    return;
                }

                Channel::query()
                    ->with('company:id,tenant_id')
                    ->where('is_active', true)
                    ->each(function (Channel $channel): void {
                        $tenantId = $channel->company?->tenant_id;
                        if ($tenantId !== null) {
                            ChannelReconciliationJob::dispatch($channel->id, $tenantId);
                        }
                    });
            })->dailyAt('03:30')->name('channels:reconcile');
        });
    }
}
