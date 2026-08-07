<?php

declare(strict_types=1);

namespace App\Modules\Document\Providers;

use App\Modules\Document\Application\Observers\DocumentMediaCascadeObserver;
use App\Modules\Document\Application\Projections\DocumentAccountChargeFactureBridge;
use App\Modules\Document\Application\Services\DocumentPartnerReferenceSource;
use App\Modules\Document\Application\Services\OperationResolver;
use App\Modules\Document\Domain\Contracts\DocumentVehicleContextWriterInterface;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Services\Conversion\Converters\DeliveryNoteToInvoiceConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\InvoiceToCreditNoteConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\PurchaseOrderToGoodsReceiptConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\PurchaseQuoteRequestToPurchaseOrderConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\QuoteToSalesOrderConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToDeliveryNoteConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToInvoiceConverter;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Infrastructure\Persistence\EloquentDocumentVehicleContextWriter;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Shared\Contracts\Document\OperationResolverInterface;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Support\ServiceProvider;

class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Partner delete guard (lane R2-S): this module answers for its own
        // partner-referencing tables. Consumed by
        // `PartnerReferenceCounter` via
        // `app->tagged(PartnerReferenceSource::class)`. Tagged in
        // `register()` (not `boot()`) to match the `FiscalEventProjector`
        // precedent.
        $this->app->tag([DocumentPartnerReferenceSource::class], PartnerReferenceSource::class);

        $this->app->singleton(DocumentNumberingService::class);

        $this->app->bind(
            DocumentVehicleContextWriterInterface::class,
            EloquentDocumentVehicleContextWriter::class,
        );

        $this->app->bind(
            OperationResolverInterface::class,
            OperationResolver::class,
        );

        $this->app->singleton(DocumentConverterRegistry::class, function ($app) {
            $registry = new DocumentConverterRegistry;

            // Register all converters
            $registry->register($app->make(QuoteToSalesOrderConverter::class));
            $registry->register($app->make(SalesOrderToInvoiceConverter::class));
            $registry->register($app->make(SalesOrderToDeliveryNoteConverter::class));
            $registry->register($app->make(DeliveryNoteToInvoiceConverter::class));
            $registry->register($app->make(InvoiceToCreditNoteConverter::class));
            $registry->register($app->make(PurchaseOrderToGoodsReceiptConverter::class));
            $registry->register($app->make(PurchaseQuoteRequestToPurchaseOrderConverter::class));

            return $registry;
        });

        $this->app->tag(
            [
                DocumentAccountChargeFactureBridge::class,
            ],
            FiscalEventProjector::class,
        );
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Presentation/routes.php');
        Document::observe(DocumentMediaCascadeObserver::class);
    }
}
