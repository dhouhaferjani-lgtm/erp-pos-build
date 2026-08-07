<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Taxation\Presentation\Controllers\SalesWithholdingTrackingController;
use App\Modules\Taxation\Presentation\Controllers\TaxConfigurationController;
use App\Modules\Taxation\Presentation\Controllers\VatPeriodController;
use App\Modules\Taxation\Presentation\Controllers\VatReportController;
use App\Modules\Taxation\Presentation\Controllers\WithholdingCertificateController;
use App\Modules\Taxation\Presentation\Controllers\WithholdingPreviewController;
use App\Modules\Taxation\Presentation\Controllers\WithholdingTaxRuleController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function (): void {
    // Tax Configuration endpoints. Reads stay open to any authenticated tenant
    // user (document forms need the rate table); writes require the dedicated
    // manage permission so a cashier cannot alter tax rates.
    Route::prefix('taxation/configurations')->group(function (): void {
        Route::get('/', [TaxConfigurationController::class, 'index']);
        Route::get('/document-types', [TaxConfigurationController::class, 'documentTypes']);
        Route::post('/reorder', [TaxConfigurationController::class, 'reorder'])
            ->middleware('can:taxation.tax_configurations.manage');
        Route::get('/{id}', [TaxConfigurationController::class, 'show']);
        Route::post('/', [TaxConfigurationController::class, 'store'])
            ->middleware('can:taxation.tax_configurations.manage');
        Route::patch('/{id}', [TaxConfigurationController::class, 'update'])
            ->middleware('can:taxation.tax_configurations.manage');
        Route::delete('/{id}', [TaxConfigurationController::class, 'destroy'])
            ->middleware('can:taxation.tax_configurations.manage');
    });

    // Withholding Tax Preview endpoint
    Route::post('withholding/preview', [WithholdingPreviewController::class, 'preview']);

    // Withholding Tax Rules (Admin). ALL routes in this group — including
    // `deactivate`/`destroy`, which pre-fix carried NO authorization check
    // at all — gate on `taxation.withholding_rules.manage`, the same
    // permission CreateWithholdingRuleRequest/UpdateWithholdingRuleRequest's
    // authorize() already reference (docs/superpowers/tickets/
    // 2026-08-03-w5a-withholding-defects.md #3, §153-174).
    Route::prefix('withholding/rules')->middleware('can:taxation.withholding_rules.manage')->group(function (): void {
        Route::get('/', [WithholdingTaxRuleController::class, 'index']);
        Route::get('/{id}', [WithholdingTaxRuleController::class, 'show']);
        Route::post('/', [WithholdingTaxRuleController::class, 'store']);
        Route::patch('/{id}', [WithholdingTaxRuleController::class, 'update']);
        Route::post('/{id}/deactivate', [WithholdingTaxRuleController::class, 'deactivate']);
        Route::delete('/{id}', [WithholdingTaxRuleController::class, 'destroy']);
    });

    // Withholding Certificates. Pre-fix this group carried NO authorization
    // middleware at all (docs/superpowers/tickets/
    // 2026-08-03-w5a-withholding-defects.md #3, §106-152) — mirrors the
    // neighbouring `sales-withholding` group's `can:` pattern two lines
    // below. Read routes (list/show/downloads, which stream partner
    // identity, VAT numbers, amounts and hash-chain fields) gate on
    // `withholding.view`; `store` on `withholding.create`; the lifecycle
    // mutators (`issue`/`void`/`submitTEJ`, which write into the fiscal hash
    // chain) on `withholding.update`; `destroy` on `withholding.delete`.
    Route::prefix('withholding/certificates')->group(function (): void {
        Route::get('/', [WithholdingCertificateController::class, 'index'])
            ->middleware('can:withholding.view');
        Route::get('/export-tej-batch', [WithholdingCertificateController::class, 'downloadBatchTEJXML'])
            ->middleware('can:withholding.view');
        Route::get('/{id}', [WithholdingCertificateController::class, 'show'])
            ->middleware('can:withholding.view');
        Route::post('/', [WithholdingCertificateController::class, 'store'])
            ->middleware('can:withholding.create');
        Route::post('/{id}/issue', [WithholdingCertificateController::class, 'issue'])
            ->middleware('can:withholding.update');
        Route::post('/{id}/void', [WithholdingCertificateController::class, 'void'])
            ->middleware('can:withholding.update');
        Route::post('/{id}/submit-tej', [WithholdingCertificateController::class, 'submitTEJ'])
            ->middleware('can:withholding.update');
        Route::get('/{id}/download-pdf', [WithholdingCertificateController::class, 'downloadPDF'])
            ->middleware('can:withholding.view');
        Route::get('/{id}/download-tej-xml', [WithholdingCertificateController::class, 'downloadTEJXML'])
            ->middleware('can:withholding.view');
        Route::delete('/{id}', [WithholdingCertificateController::class, 'destroy'])
            ->middleware('can:withholding.delete');
    });

    // Sales Withholding Tracking (when customers withhold from our sales invoices)
    Route::prefix('sales-withholding')->group(function (): void {
        Route::get('/', [SalesWithholdingTrackingController::class, 'index'])
            ->middleware('can:invoices.view');
        Route::get('/{id}', [SalesWithholdingTrackingController::class, 'show'])
            ->middleware('can:invoices.view');
        Route::patch('/{id}/certificate-received', [SalesWithholdingTrackingController::class, 'markCertificateReceived'])
            ->middleware('can:invoices.update');
    });

    // Record withholding on document
    Route::post('/documents/{documentId}/record-withholding', [SalesWithholdingTrackingController::class, 'recordWithholding'])
        ->middleware('can:invoices.update');

    // VAT Period endpoints
    //
    // W-6 D5 owner ruling (2026-08-05, "Option B split"): VAT period reads
    // are "VAT declaration" reporting, so they gate on reports.financial
    // (reports.view is deprecated and no longer checked). Mutating period
    // lifecycle actions (generate/close/reopen/file) stay on reports.manage,
    // unchanged by this split.
    Route::prefix('vat/periods')->group(function (): void {
        Route::get('/', [VatPeriodController::class, 'index'])->middleware('can:reports.financial');
        Route::post('/generate', [VatPeriodController::class, 'generate'])->middleware('can:reports.manage');
        Route::get('/{id}', [VatPeriodController::class, 'show'])->middleware('can:reports.financial');
        Route::post('/{id}/close', [VatPeriodController::class, 'close'])->middleware('can:reports.manage');
        Route::post('/{id}/reopen', [VatPeriodController::class, 'reopen'])->middleware('can:reports.manage');
        Route::post('/{id}/file', [VatPeriodController::class, 'file'])->middleware('can:reports.manage');
    });

    // VAT Report endpoints
    Route::prefix('vat/reports')->group(function (): void {
        Route::get('/summary', [VatReportController::class, 'summary'])->middleware('can:reports.financial');
        Route::get('/{periodId}/summary', [VatReportController::class, 'periodSummary'])->middleware('can:reports.financial');
        Route::get('/{periodId}/export-formats', [VatReportController::class, 'exportFormats'])->middleware('can:reports.financial');
        Route::get('/{periodId}/export/{format}', [VatReportController::class, 'export'])->middleware('can:reports.financial');
    });
});
