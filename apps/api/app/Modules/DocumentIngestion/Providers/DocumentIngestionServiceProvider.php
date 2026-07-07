<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Providers;

use Illuminate\Support\ServiceProvider;

final class DocumentIngestionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
