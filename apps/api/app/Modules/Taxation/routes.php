<?php

declare(strict_types=1);

use App\Modules\Identity\Presentation\Middleware\EnforceTokenTenantClaim;
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
use App\Modules\Taxation\Presentation\Controllers\SalesWithholdingTrackingController;
use App\Modules\Taxation\Presentation\Controllers\StampDutyRuleController;
use App\Modules\Taxation\Presentation\Controllers\TaxConfigurationController;
use App\Modules\Taxation\Presentation\Controllers\VatPeriodController;
use App\Modules\Taxation\Presentation\Controllers\VatReportController;
use App\Modules\Taxation\Presentation\Controllers\WithholdingCertificateController;
use App\Modules\Taxation\Presentation\Controllers\WithholdingPreviewController;
use App\Modules\Taxation\Presentation\Controllers\WithholdingTaxRuleController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class])->group(function (): void {
    // Tax Configuration endpoints
    Route::prefix('taxation/configurations')->group(function (): void {
        Route::get('/', [TaxConfigurationController::class, 'index']);
        Route::get('/document-types', [TaxConfigurationController::class, 'documentTypes']);
        Route::post('/reorder', [TaxConfigurationController::class, 'reorder']);
        Route::get('/{id}', [TaxConfigurationController::class, 'show']);
        Route::post('/', [TaxConfigurationController::class, 'store']);
        Route::patch('/{id}', [TaxConfigurationController::class, 'update']);
        Route::delete('/{id}', [TaxConfigurationController::class, 'destroy']);
    });

    // Stamp Duty Rule endpoints
    Route::prefix('taxation/stamp-duties')->group(function (): void {
        Route::get('/', [StampDutyRuleController::class, 'index']);
        Route::get('/{id}', [StampDutyRuleController::class, 'show']);
        Route::post('/', [StampDutyRuleController::class, 'store']);
        Route::patch('/{id}', [StampDutyRuleController::class, 'update']);
        Route::delete('/{id}', [StampDutyRuleController::class, 'destroy']);
    });

    // Withholding Tax Preview endpoint
    Route::post('withholding/preview', [WithholdingPreviewController::class, 'preview']);

    // Withholding Tax Rules (Admin)
    Route::prefix('withholding/rules')->group(function (): void {
        Route::get('/', [WithholdingTaxRuleController::class, 'index']);
        Route::get('/{id}', [WithholdingTaxRuleController::class, 'show']);
        Route::post('/', [WithholdingTaxRuleController::class, 'store']);
        Route::patch('/{id}', [WithholdingTaxRuleController::class, 'update']);
        Route::post('/{id}/deactivate', [WithholdingTaxRuleController::class, 'deactivate']);
        Route::delete('/{id}', [WithholdingTaxRuleController::class, 'destroy']);
    });

    // Withholding Certificates
    Route::prefix('withholding/certificates')->group(function (): void {
        Route::get('/', [WithholdingCertificateController::class, 'index']);
        Route::get('/export-tej-batch', [WithholdingCertificateController::class, 'downloadBatchTEJXML']);
        Route::get('/{id}', [WithholdingCertificateController::class, 'show']);
        Route::post('/', [WithholdingCertificateController::class, 'store']);
        Route::post('/{id}/issue', [WithholdingCertificateController::class, 'issue']);
        Route::post('/{id}/void', [WithholdingCertificateController::class, 'void']);
        Route::post('/{id}/submit-tej', [WithholdingCertificateController::class, 'submitTEJ']);
        Route::get('/{id}/download-pdf', [WithholdingCertificateController::class, 'downloadPDF']);
        Route::get('/{id}/download-tej-xml', [WithholdingCertificateController::class, 'downloadTEJXML']);
        Route::delete('/{id}', [WithholdingCertificateController::class, 'destroy']);
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
    Route::prefix('vat/periods')->group(function (): void {
        Route::get('/', [VatPeriodController::class, 'index'])->middleware('can:reports.view');
        Route::post('/generate', [VatPeriodController::class, 'generate'])->middleware('can:reports.manage');
        Route::get('/{id}', [VatPeriodController::class, 'show'])->middleware('can:reports.view');
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
