<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Providers;

use App\Modules\DocumentIngestion\Infrastructure\ErpMlExtractionClient;
use App\Shared\Contracts\ExtractionClientInterface;
use Illuminate\Support\ServiceProvider;

final class DocumentIngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExtractionClientInterface::class, ErpMlExtractionClient::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
