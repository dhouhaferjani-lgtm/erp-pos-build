<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Accounting\Application\Services\ArApOpeningLedgerService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Application\Services\PartnerControlAccountResolver;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
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
use Illuminate\Database\Eloquent\Builder;
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
 * - AR batches mint customer-side documents (HIST-INV / HIST-CN), AP batches
 *   supplier-side ones (HIST-SINV / HIST-SCN) — the batch side decides the type,
 *   not the row (W4-3)
 * - external_document_number stores the original invoice number from old system
 * - balance_due set to the open amount
 * - Each open item posts its OWN historical journal entry against the seeded
 *   opening-balance counterpart, with the control leg (411/401) carrying the
 *   partner dimension, and refreshes the partner sub-ledger (W4-4). The GL
 *   opening batch must NOT restate 411/401 — AccountingOpeningService refuses
 *   partner control accounts for exactly that reason.
 */
class ArApOpeningService
{
    public function __construct(
        private readonly OpeningBalanceBatchService $batchService,
        private readonly ArApOpeningLedgerService $ledgerService,
        private readonly PartnerControlAccountResolver $controlAccounts,
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

        // A sealed batch must not be re-validated: it would flip POSTED rows back to
        // VALID and replace the mapped_data the batch SHA-256 seal is computed over.
        if (! $batch->isEditable()) {
            throw new RuntimeException(
                "Cannot validate batch in {$batch->status->label()} status. Only draft batches can be validated."
            );
        }

        $isAr = $batch->type === OpeningBatchType::ArOpenItems;
        $companyCurrency = $batch->company->currency;
        // POSTED rows are excluded alongside SKIPPED — their documents already exist.
        $rows = $batch->rows()
            ->whereNotIn('status', [OpeningImportRowStatus::Skipped, OpeningImportRowStatus::Posted])
            ->get();
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

        // Validate document type (default to invoice).
        //
        // W4-3: the row's `document_type` states the DIRECTION of the open item
        // ("we are owed" vs "we owe back"), not the concrete document type — the
        // AR and AP templates share one column vocabulary, and the Parties import
        // (`Import\Services\PartiesRowMapper::balancePayload()`, named not imported)
        // emits the same two words for both sides from the SIGN of the balance.
        // The batch side is what turns that into a type. Before this, both sides
        // mapped to Invoice/CreditNote, so an AP opening was a CUSTOMER invoice:
        // `PaymentController` decides supplier-ness by
        // `type === DocumentType::SupplierInvoice`, which such a document can never
        // satisfy, so paying the supplier booked Dr bank / Cr 411 and moved the
        // cash the wrong way while the 401 debt stayed standing.
        $docTypeValue = $rawData['document_type'] ?? 'invoice';
        $docType = match (strtolower($docTypeValue)) {
            'invoice', 'inv' => $isAr ? DocumentType::Invoice : DocumentType::SupplierInvoice,
            'credit_note', 'creditnote', 'cn' => $isAr ? DocumentType::CreditNote : DocumentType::SupplierCreditNote,
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
     * - document_number = HIST-INV / HIST-CN (AR) or HIST-SINV / HIST-SCN (AP)
     * - external_document_number = original invoice number from old system
     * - balance_due = open amount
     * - status = Posted
     * - fiscal_category = NonFiscal (excluded from hash chain)
     *
     * Each document also posts its cutover journal entry through
     * ArApOpeningLedgerService (control leg partner-tagged, counterpart on the
     * seeded opening-balance equity account), and the partner sub-ledger is
     * refreshed once per distinct partner at the end of the batch.
     *
     * @return array<string, mixed> Summary of documents created
     *
     * @throws RuntimeException If batch cannot be posted
     */
    public function postBatch(OpeningBalanceBatch $batch, string $userId): array
    {
        $this->assertValidBatchType($batch);

        // Cheap fast-fail. The AUTHORITATIVE guard is the locked re-read inside the
        // transaction below — this one reads an in-memory model a concurrent request
        // may already have superseded.
        if (! $batch->canPost()) {
            throw new RuntimeException(
                "Cannot post batch in {$batch->status->label()} status. Batch must be in draft status."
            );
        }

        $company = Company::findOrFail($batch->company_id);
        $isAr = $batch->type === OpeningBatchType::ArOpenItems;
        $batchId = $batch->id;

        return DB::transaction(function () use ($batchId, $company, $isAr, $userId): array {
            // FIRST statement: re-read the batch FOR UPDATE, guard on the fresh row,
            // then read the rows under that lock.
            $batch = $this->batchService->lockBatchForPosting($batchId);

            $validRows = $batch->rows()
                ->where('status', OpeningImportRowStatus::Valid)
                ->orderBy('row_number')
                ->get();

            if ($validRows->isEmpty()) {
                throw new RuntimeException('No valid rows to post. Please validate the batch first.');
            }

            // Gate r1 I-3 — the control-account rule has to hold in BOTH posting
            // orders, or it is not a rule.
            //
            // AccountingOpeningService refuses a GL opening that states 411/401, so
            // going forward this batch is their single writer. But posting ORDER
            // decides everything: a company that LOCKED a GL opening containing
            // `411 150` / `401 500` before that guard shipped — the campaign's own
            // §A.6, and what the shipped CSV template taught — would get both, and a
            // locked batch is not deletable, so there is no recovery path in
            // product. Refuse here rather than silently double the control account.
            $this->assertGlOpeningDidNotStateControlAccounts($company->id, $isAr);

            $documentsCreated = [];
            $rowEntityMap = [];
            /** @var list<string> $partnerIds */
            $partnerIds = [];
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

                // W4-4: the cutover GL leg, one entry per open item, control leg
                // tagged with the partner. Inside this transaction so a document
                // and its opening entry can never exist without each other.
                $openingEntry = $this->ledgerService->postOpeningEntry(
                    $document,
                    $batch->cutover_date,
                    $userId,
                );

                $documentsCreated[] = [
                    'id' => $document->id,
                    'document_number' => $documentNumber,
                    'partner' => $mappedData['partner_name'] ?? 'N/A',
                    'total' => $mappedData['total'],
                    'balance_due' => $mappedData['open_amount'],
                    'type' => $docType->value,
                    'journal_entry_number' => $openingEntry?->entry_number,
                ];

                $partnerIds[] = (string) $mappedData['partner_id'];
                $totalAmount = bcadd($totalAmount, $mappedData['total'], $this->scale());
                $totalOpenAmount = bcadd($totalOpenAmount, $mappedData['open_amount'], $this->scale());
                $rowEntityMap[$row->id] = $document->id;
            }

            // W4-4: opening entries are created directly Posted and therefore never
            // dispatch JournalEntryPosted, so the RefreshPartnerBalanceOnJournalEntryPosted
            // listener never runs. Refresh the sub-ledger explicitly, once per
            // distinct partner — without this the partner pages keep reading 0.000
            // against real open items, which is the whole of W4-4.
            $this->ledgerService->refreshPartnerBalances($company->id, $partnerIds);

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
            // N-3 discriminator — see AccountingOpeningService::getPostPreview().
            // The pre-existing nested `batch.batch_type` is kept as-is: removing it
            // would be a breaking change for any consumer already reading it.
            'batch_type' => $batch->type->value,
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
            'note' => 'Historical documents will be created with is_historical=true. Each open item also posts its own opening journal entry against the opening-balance account, with the partner dimension on the receivable/payable leg, so the partner balances and the control accounts agree at cutover. Do NOT restate the receivable/payable control accounts in the GL Accounting opening — that batch refuses them.',
        ];
    }

    /**
     * Generate a concurrency-safe document number for a historical invoice/credit note.
     *
     * Format: HIST-INV-2025-00001 or HIST-CN-2025-00001
     *
     * Must be called INSIDE an open DB::transaction. On PostgreSQL, acquires a
     * transaction-scoped advisory lock keyed on (companyId, prefix, year) to close
     * the TOCTOU race between the read-max and the insert — the sequence is per
     * PREFIX, so the key must be too. Without it two concurrent posts both read the
     * same max and both mint HIST-INV-{year}-00001, and the loser fails on the
     * documents (tenant_id, type, document_number) unique index — which stays as the
     * second line of defence. On SQLite (test runner) the advisory lock is skipped.
     *
     * Same pattern as the corrected sibling generateOpeningEntryNumber() in the
     * Inventory module's OpeningBalancePostingService (named, not imported: module
     * boundaries are enforced by deptrac and a docblock is not a dependency).
     */
    private function generateHistoricalDocumentNumber(string $companyId, DocumentType $type): string
    {
        $year = date('Y');
        $prefix = match ($type) {
            DocumentType::Invoice => 'HIST-INV',
            DocumentType::CreditNote => 'HIST-CN',
            // W4-3: the AP side gets its OWN sequences. Sharing HIST-INV made an
            // opening supplier bill indistinguishable from a customer invoice in
            // every list, export and search the operator has.
            DocumentType::SupplierInvoice => 'HIST-SINV',
            DocumentType::SupplierCreditNote => 'HIST-SCN',
            default => 'HIST-DOC',
        };

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtext(?))',
                ["hist-doc-seq:{$companyId}:{$prefix}:{$year}"],
            );
        }

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
     * Refuse the batch when a posted GL opening already carries the control account
     * this batch is about to write (gate r1 I-3).
     *
     * Scoped to the side being posted: an AR batch is not blocked by a GL opening
     * that stated only the payable. The account set walks the purpose tree, so a GL
     * opening that stated `4011` rather than `401` is caught too (I-4).
     *
     * @throws RuntimeException
     */
    private function assertGlOpeningDidNotStateControlAccounts(string $companyId, bool $isAr): void
    {
        $purpose = $isAr
            ? SystemAccountPurpose::CustomerReceivable
            : SystemAccountPurpose::SupplierPayable;

        $controlAccountIds = $this->controlAccounts->controlAccountIds($companyId, $purpose);

        if ($controlAccountIds === []) {
            return;
        }

        $conflicting = JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'opening_balance')
            ->where('status', JournalEntryStatus::Posted)
            ->whereHas('lines', static function (Builder $q) use ($controlAccountIds): void {
                /** @var Builder<JournalLine> $q */
                $q->whereIn('account_id', $controlAccountIds);
            })
            ->pluck('entry_number')
            ->all();

        if ($conflicting === []) {
            return;
        }

        $side = $isAr ? 'receivable' : 'payable';
        $entries = implode(', ', array_map(strval(...), $conflicting));

        throw new RuntimeException(
            "The GL accounting opening ({$entries}) already states the {$side} control account, so posting these ".
            'open items would count the same balance twice. The control account belongs to this batch: remove the '.
            'control-account line from the GL opening and post it again, or post these open items into a company '.
            'whose GL opening does not state it.'
        );
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
