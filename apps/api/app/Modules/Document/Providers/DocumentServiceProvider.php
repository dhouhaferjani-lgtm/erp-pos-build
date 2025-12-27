<?php

declare(strict_types=1);

namespace App\Modules\Document\Providers;

use App\Modules\Document\Domain\Services\Conversion\Converters\DeliveryNoteToInvoiceConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\QuoteToSalesOrderConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToDeliveryNoteConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToInvoiceConverter;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use Illuminate\Support\ServiceProvider;

class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DocumentNumberingService::class);

        $this->app->singleton(DocumentConverterRegistry::class, function ($app) {
            $registry = new DocumentConverterRegistry();

            // Register all converters
            $registry->register($app->make(QuoteToSalesOrderConverter::class));
            $registry->register($app->make(SalesOrderToInvoiceConverter::class));
            $registry->register($app->make(SalesOrderToDeliveryNoteConverter::class));
            $registry->register($app->make(DeliveryNoteToInvoiceConverter::class));
            $registry->register($app->make(\App\Modules\Document\Domain\Services\Conversion\Converters\InvoiceToCreditNoteConverter::class));

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
    }
}
