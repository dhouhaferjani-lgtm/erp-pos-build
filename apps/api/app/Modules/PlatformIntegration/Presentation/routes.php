<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\PlatformIntegration\Infrastructure\Middleware\VerifySynerivaWebhookSignature;
use App\Modules\PlatformIntegration\Presentation\Controllers\BarcodeLookupController;
use App\Modules\PlatformIntegration\Presentation\Controllers\CatalogBrowseController;
use App\Modules\PlatformIntegration\Presentation\Controllers\EnrichmentWebhookController;
use App\Modules\PlatformIntegration\Presentation\Controllers\ProductSubmissionController;
use App\Modules\PlatformIntegration\Presentation\Controllers\VinDecodeController;
use Illuminate\Support\Facades\Route;

// Webhook receiver — NO auth:sanctum (platform calls this with HMAC signature)
Route::middleware(['api', VerifySynerivaWebhookSignature::class])
    ->prefix('api/v1/webhooks')
    ->group(function () {
        Route::post('/syneriva', EnrichmentWebhookController::class)
            ->name('platform.webhook.syneriva');
    });

Route::prefix('api/v1/platform')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {
    // Barcode Lookup
    Route::post('barcode-lookup', BarcodeLookupController::class)
        ->name('platform.barcode-lookup');

    // Catalog Browse (proxy to platform)
    Route::prefix('catalog')->group(function () {
        Route::get('manufacturers', [CatalogBrowseController::class, 'manufacturers'])
            ->name('platform.catalog.manufacturers');

        Route::get('manufacturers/{manufacturerId}/model-series', [CatalogBrowseController::class, 'modelSeries'])
            ->name('platform.catalog.model-series');

        Route::get('model-series/{modelSeriesId}/vehicles', [CatalogBrowseController::class, 'vehicles'])
            ->name('platform.catalog.vehicles');

        // Single vehicle detail
        Route::get('vehicles/{vehicleType}/{vehicleId}', [CatalogBrowseController::class, 'vehicle'])
            ->name('platform.catalog.vehicle')
            ->where('vehicleId', '[a-f0-9-]+');

        Route::get('vehicles/{vehicleType}/{vehicleId}/articles', [CatalogBrowseController::class, 'vehicleArticles'])
            ->name('platform.catalog.vehicle-articles');

        // Articles: static routes BEFORE parameterized {articleId}
        Route::get('articles/cross-reference', [CatalogBrowseController::class, 'crossReferenceSearch'])
            ->name('platform.catalog.cross-reference');

        Route::match(['get', 'post'], 'articles/search-by-criteria', [CatalogBrowseController::class, 'searchByCriteria'])
            ->name('platform.catalog.search-by-criteria');

        Route::get('articles', [CatalogBrowseController::class, 'searchArticles'])
            ->name('platform.catalog.articles');

        Route::get('articles/{articleId}', [CatalogBrowseController::class, 'article'])
            ->name('platform.catalog.article');

        Route::get('articles/{articleId}/linkages', [CatalogBrowseController::class, 'articleLinkages'])
            ->name('platform.catalog.article-linkages');

        // Metadata
        Route::get('criteria', [CatalogBrowseController::class, 'criteria'])
            ->name('platform.catalog.criteria');

        Route::get('suppliers', [CatalogBrowseController::class, 'suppliers'])
            ->name('platform.catalog.suppliers');

        // Search Tree
        Route::get('search-tree/roots', [CatalogBrowseController::class, 'searchTreeRoots'])
            ->name('platform.catalog.search-tree-roots');

        Route::get('search-tree/{nodeId}/children', [CatalogBrowseController::class, 'searchTreeChildren'])
            ->name('platform.catalog.search-tree-children');

        Route::get('search-tree/{nodeId}/articles', [CatalogBrowseController::class, 'searchTreeArticles'])
            ->name('platform.catalog.search-tree-articles');
    });

    // VIN/Plate Decoding
    Route::post('vin-decode', [VinDecodeController::class, 'decode'])
        ->name('platform.vin-decode');

    Route::post('vin-decode/confirm-match', [VinDecodeController::class, 'confirmMatch'])
        ->name('platform.vin-decode.confirm-match');

    Route::post('submit-for-enrichment', ProductSubmissionController::class)
        ->name('platform.submit-for-enrichment');
});
