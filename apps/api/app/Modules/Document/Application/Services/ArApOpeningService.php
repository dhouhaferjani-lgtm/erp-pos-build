<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Accounting\Domain\OpeningBalanceImportRow;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Service for handling AR/AP Opening Balance imports.
 *
 * This service manages the import, validation, and posting of
 * open items (unpaid invoices/credit notes) from a previous system.
 *
 * Key features:
 * - Validates partner codes exist (customer for AR, supplier for AP)
 * - Validates dates and amounts
 * - Creates historical documents with is_historical = true
 * - Uses HIST-INV-XXXX or HIST-CN-XXXX numbering for our reference
 * - external_document_number stores the original invoice number from old system
 * - balance_due set to the open amount
 * - No GL entry created (GL was handled by accounting opening)
 */
class ArApOpeningService
{
    public function __construct(
        private readonly OpeningBalanceBatchService $batchService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Validate all import rows in an AR or AP opening batch.
     *
     * @return array<string, mixed>
     */
    public function validateBatch(OpeningBalanceBatch $batch): array
    {
        $this->assertValidBatchType($batch);

        $isAr = $batch->type === OpeningBatchType::ArOpenItems;
        $companyCurrency = $batch->company->currency;
        $rows = $batch->rows()->where('status', '!=', OpeningImportRowStatus::Skipped)->get();
        $validationResults = [];
        $errors = [];

        $totalAmount = '0.00';
        $totalOpenAmount = '0.00';

        foreach ($rows as $row) {
            $result = $this->validateRow($row, $batch->company_id, $isAr, $companyCurrency);
            $validationResults[$row->id] = $result;

            if ($result['valid']) {
                $totalAmount = bcadd($totalAmount, $result['mapped_data']['total'] ?? '0.00', $this->scale());
                $totalOpenAmount = bcadd($totalOpenAmount, $result['mapped_data']['open_amount'] ?? '0.00', $this->scale());
            } else {
                $errors[$row->id] = $result['errors'];
            }
        }

        // Apply validation results to rows
        $this->batchService->applyValidationResults($batch, $validationResults);

        $validCount = count(array_filter($validationResults, fn (array $r): bool => $r['valid']));
        $invalidCount = count($validationResults) - $validCount;

        return [
            'valid' => $invalidCount === 0 && $validCount > 0,
            'total_rows' => count($rows),
            'valid_rows' => $validCount,
            'invalid_rows' => $invalidCount,
            'total_amount' => $totalAmount,
            'total_open_amount' => $totalOpenAmount,
            'errors' => $errors,
        ];
    }

    /**
     * Validate a single import row.
     *
     * Expected raw_data format:
     * {
     *   "partner_code": "CUST-001",
     *   "external_invoice_number": "F2024-0012",
     *   "document_date": "2024-11-15",
     *   "due_date": "2024-12-15",
     *   "total": "1500.00",
     *   "open_amount": "1500.00",
     *   "currency": "EUR",
     *   "document_type": "invoice",  // or "credit_note"
     *   "notes": "Optional notes"
     * }
     *
     * @return array{valid: bool, errors: array<string, array<string>>, mapped_data: array<string, mixed>}
     */
    private function validateRow(OpeningBalanceImportRow $row, string $companyId, bool $isAr, string $companyCurrency): array
    {
        $rawData = $row->raw_data;
        $errors = [];
        $mappedData = [];

        // Validate partner code
        if (! isset($rawData['partner_code']) || $rawData['partner_code'] === '') {
            $errors['partner_code'] = ['Partner code is required'];
        } else {
            $partnerQuery = Partner::forCompany($companyId)
                ->where('code', $rawData['partner_code']);

            // For AR: must be a customer; for AP: must be a supplier
            if ($isAr) {
                $partnerQuery->customers();
            } else {
                $partnerQuery->suppliers();
            }

            $partner = $partnerQuery->first();

            $partnerTypeLabel = $isAr ? 'customer' : 'supplier';
            if ($partner === null) {
                $errors['partner_code'] = ["Partner '{$rawData['partner_code']}' not found or is not a {$partnerTypeLabel}"];
            } else {
                $mappedData['partner_id'] = $partner->id;
                $mappedData['partner_code'] = $partner->code;
                $mappedData['partner_name'] = $partner->name;
            }
        }

        // Validate document type (default to invoice)
        $docTypeValue = $rawData['document_type'] ?? 'invoice';
        $docType = match (strtolower($docTypeValue)) {
            'invoice', 'inv' => DocumentType::Invoice,
            'credit_note', 'creditnote', 'cn' => DocumentType::CreditNote,
            default => null,
        };

        if ($docType === null) {
            $errors['document_type'] = ["Invalid document type '{$docTypeValue}'. Must be 'invoice' or 'credit_note'"];
        } else {
            $mappedData['document_type'] = $docType;
        }

        // Validate document date
        $documentDate = $rawData['document_date'] ?? null;
        if ($documentDate === null || $documentDate === '') {
            $errors['document_date'] = ['Document date is required'];
        } else {
            try {
                $parsedDate = Carbon::parse($documentDate);
                $mappedData['document_date'] = $parsedDate->toDateString();
            } catch (\Exception) {
                $errors['document_date'] = ["Invalid date format: '{$documentDate}'"];
            }
        }

        // Validate due date
        $dueDate = $rawData['due_date'] ?? null;
        if ($dueDate === null || $dueDate === '') {
            $errors['due_date'] = ['Due date is required'];
        } else {
            try {
                $parsedDueDate = Carbon::parse($dueDate);
                $mappedData['due_date'] = $parsedDueDate->toDateString();
            } catch (\Exception) {
                $errors['due_date'] = ["Invalid date format: '{$dueDate}'"];
            }
        }

        // Validate total amount
        $total = $rawData['total'] ?? '0.00';
        if (! is_numeric($total) || bccomp((string) $total, '0.00', $this->scale()) <= 0) {
            $errors['total'] = ['Total amount must be a positive number'];
        } else {
            $mappedData['total'] = bcadd('0.00', (string) $total, $this->scale());
        }

        // Validate open amount
        $openAmount = $rawData['open_amount'] ?? '0.00';
        if (! is_numeric($openAmount) || bccomp((string) $openAmount, '0.00', $this->scale()) < 0) {
            $errors['open_amount'] = ['Open amount must be a non-negative number'];
        } else {
            $mappedData['open_amount'] = bcadd('0.00', (string) $openAmount, $this->scale());
        }

        // Validate open_amount <= total
        if (empty($errors['total']) && empty($errors['open_amount'])) {
            $openAmountVal = $mappedData['open_amount'] ?? '0.00';
            $totalVal = $mappedData['total'] ?? '0.00';
            if (bccomp($openAmountVal, $totalVal, $this->scale()) > 0) {
                $errors['open_amount'] = ['Open amount cannot exceed total amount'];
            }
        }

        // Optional: external invoice number (reference to old system)
        $mappedData['external_invoice_number'] = $rawData['external_invoice_number'] ?? null;

        // Optional: currency (default to company currency)
        $currency = trim((string) ($rawData['currency'] ?? ''));
        if ($currency === '') {
            $currency = $companyCurrency;
        } elseif ($currency !== $companyCurrency) {
            $errors['currency'] = ["Currency must match company currency ({$companyCurrency})."];
        }
        $mappedData['currency'] = $currency;

        // Optional: notes
        $mappedData['notes'] = $rawData['notes'] ?? null;

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'mapped_data' => $mappedData,
        ];
    }

    /**
     * Post a validated AR/AP opening batch.
     *
     * Creates historical documents:
     * - is_historical = true
     * - document_number = HIST-INV-XXXX or HIST-CN-XXXX (our reference)
     * - external_document_number = original invoice number from old system
     * - balance_due = open amount
     * - status = Posted
     * - fiscal_category = NonFiscal (excluded from hash chain)
     *
     * No GL entry is created (GL was handled by accounting opening).
     *
     * @return array<string, mixed> Summary of documents created
     *
     * @throws RuntimeException If batch cannot be posted
     */
    public function postBatch(OpeningBalanceBatch $batch, string $userId): array
    {
        $this->assertValidBatchType($batch);

        if (! $batch->canPost()) {
            throw new RuntimeException(
                "Cannot post batch in {$batch->status->label()} status. Batch must be in draft status."
            );
        }

        // Get valid rows only
        $validRows = $batch->rows()
            ->where('status', OpeningImportRowStatus::Valid)
            ->orderBy('row_number')
            ->get();

        if ($validRows->isEmpty()) {
            throw new RuntimeException('No valid rows to post. Please validate the batch first.');
        }

        $company = Company::findOrFail($batch->company_id);
        $isAr = $batch->type === OpeningBatchType::ArOpenItems;

        return DB::transaction(function () use ($batch, $validRows, $company, $isAr, $userId): array {
            $documentsCreated = [];
            $rowEntityMap = [];
            $totalAmount = '0.00';
            $totalOpenAmount = '0.00';

            foreach ($validRows as $row) {
                $mappedData = $row->mapped_data;

                if (! is_array($mappedData) || ! isset($mappedData['partner_id'])) {
                    continue;
                }

                $docType = $mappedData['document_type'] instanceof DocumentType
                    ? $mappedData['document_type']
                    : DocumentType::from((string) $mappedData['document_type']);
                $documentNumber = $this->generateHistoricalDocumentNumber($company->id, $docType);

                $document = Document::create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'partner_id' => $mappedData['partner_id'],
                    'type' => $docType,
                    'status' => DocumentStatus::Posted,
                    'fiscal_category' => FiscalCategory::NonFiscal, // Historical = non-fiscal
                    'fiscal_status' => FiscalStatus::Draft, // Non-fiscal doesn't need sealing
                    'document_number' => $documentNumber,
                    'document_date' => $mappedData['document_date'],
                    'due_date' => $mappedData['due_date'],
                    'currency' => $mappedData['currency'],
                    'subtotal' => $mappedData['total'],
                    'discount_amount' => '0.00',
                    'tax_amount' => '0.00',
                    'total' => $mappedData['total'],
                    'balance_due' => $mappedData['open_amount'],
                    'is_historical' => true,
                    'external_document_number' => $mappedData['external_invoice_number'],
                    'notes' => $mappedData['notes'],
                    'reference' => "Opening Balance Batch: {$batch->name}",
                ]);

                $documentsCreated[] = [
                    'id' => $document->id,
                    'document_number' => $documentNumber,
                    'partner' => $mappedData['partner_name'] ?? 'N/A',
                    'total' => $mappedData['total'],
                    'balance_due' => $mappedData['open_amount'],
                    'type' => $docType->value,
                ];

                $totalAmount = bcadd($totalAmount, $mappedData['total'], $this->scale());
                $totalOpenAmount = bcadd($totalOpenAmount, $mappedData['open_amount'], $this->scale());
                $rowEntityMap[$row->id] = $document->id;
            }

            // Transition the batch first — markBatchValidated requires the rows
            // to still be in Valid status (a Posted row no longer counts as valid).
            $this->batchService->markBatchValidated($batch, $userId);

            $this->batchService->markRowsPosted($rowEntityMap);

            return [
                'documents_created' => count($documentsCreated),
                'total_amount' => $totalAmount,
                'total_open_amount' => $totalOpenAmount,
                'batch_type' => $isAr ? 'AR' : 'AP',
                'documents' => $documentsCreated,
            ];
        });
    }

    /**
     * Get a preview of what will be posted.
     *
     * @return array<string, mixed>
     */
    public function getPostPreview(OpeningBalanceBatch $batch): array
    {
        $this->assertValidBatchType($batch);

        $isAr = $batch->type === OpeningBatchType::ArOpenItems;

        $validRows = $batch->rows()
            ->where('status', OpeningImportRowStatus::Valid)
            ->orderBy('row_number')
            ->get();

        $documents = $validRows->map(function (OpeningBalanceImportRow $row): array {
            $mappedData = $row->mapped_data;
            $docType = $mappedData['document_type'] ?? null;

            return [
                'row_number' => $row->row_number,
                'partner_code' => $mappedData['partner_code'] ?? 'N/A',
                'partner_name' => $mappedData['partner_name'] ?? 'N/A',
                'external_invoice_number' => $mappedData['external_invoice_number'] ?? '',
                'document_type' => $docType instanceof DocumentType ? $docType->value : 'invoice',
                'document_date' => $mappedData['document_date'] ?? '',
                'due_date' => $mappedData['due_date'] ?? '',
                'currency' => $mappedData['currency'] ?? 'TND',
                'total' => $mappedData['total'] ?? '0.00',
                'open_amount' => $mappedData['open_amount'] ?? '0.00',
            ];
        });

        $totalAmount = $documents->reduce(
            /** @phpstan-ignore argument.type */
            fn (string $carry, array $doc): string => bcadd($carry, (string) $doc['total'], $this->scale()),
            '0.00'
        );

        $totalOpenAmount = $documents->reduce(
            /** @phpstan-ignore argument.type */
            fn (string $carry, array $doc): string => bcadd($carry, (string) $doc['open_amount'], $this->scale()),
            '0.00'
        );

        return [
            'batch' => [
                'cutover_date' => $batch->cutover_date->toDateString(),
                'description' => ($isAr ? 'AR' : 'AP')." Open Items - {$batch->name}",
                'is_historical' => true,
                'batch_type' => $isAr ? 'AR_OPEN_ITEMS' : 'AP_OPEN_ITEMS',
            ],
            'documents' => $documents,
            'totals' => [
                'total_documents' => $documents->count(),
                'total_amount' => $totalAmount,
                'total_open_amount' => $totalOpenAmount,
            ],
            'note' => 'Historical documents will be created with is_historical=true. No GL entry will be created (GL balance should be handled via Accounting Opening).',
        ];
    }

    /**
     * Generate document number for historical invoice/credit note.
     *
     * Format: HIST-INV-2025-00001 or HIST-CN-2025-00001
     */
    private function generateHistoricalDocumentNumber(string $companyId, DocumentType $type): string
    {
        $year = date('Y');
        $prefix = match ($type) {
            DocumentType::Invoice => 'HIST-INV',
            DocumentType::CreditNote => 'HIST-CN',
            default => 'HIST-DOC',
        };

        $lastDocument = Document::query()
            ->where('company_id', $companyId)
            ->where('document_number', 'like', "{$prefix}-{$year}-%")
            ->orderByDesc('document_number')
            ->first();

        if ($lastDocument !== null) {
            $lastNumber = (int) substr($lastDocument->document_number, -5);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('%s-%s-%05d', $prefix, $year, $nextNumber);
    }

    /**
     * Assert that the batch type is valid for this service.
     *
     * @throws RuntimeException If batch type is not AR or AP
     */
    private function assertValidBatchType(OpeningBalanceBatch $batch): void
    {
        if ($batch->type !== OpeningBatchType::ArOpenItems && $batch->type !== OpeningBatchType::ApOpenItems) {
            throw new RuntimeException(
                'This service only handles AR_OPEN_ITEMS and AP_OPEN_ITEMS batch types.'
            );
        }
    }
}
