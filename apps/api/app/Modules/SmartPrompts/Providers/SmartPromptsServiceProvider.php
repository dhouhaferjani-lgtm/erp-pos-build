<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Providers;

use App\Modules\SmartPrompts\Application\Contracts\RecommendationEngineClientInterface;
use App\Modules\SmartPrompts\Infrastructure\Http\RecommendationEngineHttpClient;
use Illuminate\Support\ServiceProvider;

class SmartPromptsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecommendationEngineClientInterface::class, function (): RecommendationEngineHttpClient {
            /** @var array{url: string, timeout: int, connect_timeout: int, retry_times: int, retry_delay: int, circuit_breaker_threshold: int, circuit_breaker_cooldown: int} $config */
            $config = config('services.recommendation_engine');

            return new RecommendationEngineHttpClient(
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
