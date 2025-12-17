<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Application\Services\InvoiceService;
use App\Modules\Billing\Application\Services\PaymentProviderManager;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register PaymentProviderManager as singleton
        $this->app->singleton(PaymentProviderManager::class, function () {
            return new PaymentProviderManager;
        });

        // Register InvoiceService
        $this->app->singleton(InvoiceService::class, function () {
            return new InvoiceService;
        });

        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../../../../config/billing.php',
            'billing'
        );
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../../../../config/billing.php' => config_path('billing.php'),
        ], 'billing-config');

        // Load views
        $this->loadViewsFrom(
            __DIR__.'/../../../../resources/views/billing',
            'billing'
        );

        // Publish views
        $this->publishes([
            __DIR__.'/../../../../resources/views/billing' => resource_path('views/vendor/billing'),
        ], 'billing-views');
    }
}
