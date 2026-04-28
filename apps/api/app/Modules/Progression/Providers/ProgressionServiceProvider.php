<?php

declare(strict_types=1);

namespace App\Modules\Progression\Providers;

use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Progression\Infrastructure\Http\GrowthAdvisorHttpClient;
use Illuminate\Support\ServiceProvider;

final class ProgressionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GrowthAdvisorClientInterface::class, function (): GrowthAdvisorHttpClient {
            /** @var array{url: string, timeout: int, connect_timeout: int, retry_times: int, retry_delay: int, circuit_breaker_threshold: int, circuit_breaker_cooldown: int} $config */
            $config = config('services.growth_advisor');

            return new GrowthAdvisorHttpClient(
                baseUrl: (string) $config['url'],
                timeout: (int) $config['timeout'],
                connectTimeout: (int) $config['connect_timeout'],
                retryTimes: (int) $config['retry_times'],
                retryDelay: (int) $config['retry_delay'],
                circuitBreakerThreshold: (int) $config['circuit_breaker_threshold'],
                circuitBreakerCooldown: (int) $config['circuit_breaker_cooldown'],
            );
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
