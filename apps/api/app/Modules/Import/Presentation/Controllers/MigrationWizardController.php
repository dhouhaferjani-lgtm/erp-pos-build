<?php

declare(strict_types=1);

namespace App\Modules\Import\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Services\ModuleEntitlementCheck;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Services\MigrationWizardService;
use App\Modules\Import\Services\SpreadsheetParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class MigrationWizardController extends Controller
{
    public function __construct(
        private readonly MigrationWizardService $wizardService,
        private readonly CompanyContext $companyContext,
        private readonly SpreadsheetParserService $spreadsheetParser,
        private readonly ModuleEntitlementCheck $moduleEntitlement,
    ) {}

    /**
     * Parse an uploaded spreadsheet (CSV any delimiter, XLSX, XLS) and return
     * its header row, so the mapping step works for files the browser cannot
     * parse client-side.
     */
    public function parseHeaders(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $path = $file->store('imports/header-preview', 'local');
        if ($path === false) {
            return response()->json(['error' => 'Failed to store file'], 500);
        }

        try {
            $result = $this->spreadsheetParser->parse(Storage::disk('local')->path($path));

            return response()->json([
                'data' => [
                    'headers' => $result['headers'],
                    'row_count' => count($result['rows']),
                ],
            ]);
        } catch (\Throwable) {
            return response()->json([
                'error' => 'Could not parse file. Please upload a valid CSV or Excel file.',
            ], 422);
        } finally {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Get recommended import order
     */
    public function order(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $importOrder = array_values(array_filter(
            $this->wizardService->getRecommendedImportOrder(),
            fn (ImportType $type): bool => $this->moduleEntitlement->allows($type, $user),
        ));

        $data = array_map(
            fn (ImportType $type) => $this->wizardService->getImportTypeMetadata($type),
            $importOrder
        );

        return response()->json(['data' => $data]);
    }

    /**
     * Check dependencies for an import type
     */
    public function dependencies(Request $request, string $type): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        try {
            $importType = ImportType::from($type);
        } catch (\ValueError) {
            return response()->json(['error' => 'Invalid import type'], 400);
        }

        $result = $this->wizardService->checkDependencies($tenantId, $importType);

        return response()->json(['data' => $result]);
    }

    /**
     * Suggest column mappings
     */
    public function suggestMapping(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string'],
            'headers' => ['required', 'array'],
            'headers.*' => ['string'],
        ]);

        try {
            $importType = ImportType::from($request->input('type'));
        } catch (\ValueError) {
            return response()->json(['error' => 'Invalid import type'], 400);
        }

        /** @var array<string> $headers */
        $headers = $request->input('headers');
        $suggestions = $this->wizardService->suggestColumnMapping($importType, $headers);

        // Determine unmapped columns
        $mappedSource = array_filter($suggestions);
        $unmappedSource = array_diff($headers, $mappedSource);
        $unmappedTarget = array_keys(array_filter($suggestions, fn ($v) => $v === null));

        return response()->json([
            'data' => [
                'suggestions' => $suggestions,
                'unmapped_source' => array_values($unmappedSource),
                'unmapped_target' => $unmappedTarget,
            ],
        ]);
    }

    /**
     * Generate import template
     */
    public function template(Request $request, string $type): Response
    {
        try {
            $importType = ImportType::from($type);
        } catch (\ValueError) {
            return response()->json(['error' => 'Invalid import type'], 400);
        }

        /** @var User $user */
        $user = $request->user();
        $this->moduleEntitlement->ensure($importType, $user);

        // Retired types remain in the enum for historical reads only — handing
        // out a template would invite an import the API now refuses (ruling D4).
        if ($importType->isDeprecated()) {
            return response()->json([
                'error' => 'This import type is no longer supported.',
            ], 422);
        }

        $template = $this->wizardService->generateTemplate($importType);

        return response($template, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$type}_template.csv\"",
        ]);
    }

    /**
     * Get migration status
     */
    public function status(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $tenantId = $company->tenant_id;

        $status = $this->wizardService->getMigrationStatus($tenantId);

        /** @var User $user */
        $user = $request->user();
        foreach (array_keys($status) as $typeValue) {
            $type = ImportType::tryFrom($typeValue);
            if ($type !== null && ! $this->moduleEntitlement->allows($type, $user)) {
                unset($status[$typeValue]);
            }
        }

        return response()->json(['data' => $status]);
    }
}
