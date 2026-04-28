<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Commands;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Command to backfill fiscal hashes for existing posted documents.
 *
 * This command is used to retroactively add hash chain entries to documents
 * that were posted before the NF525 compliance implementation was complete.
 *
 * IMPORTANT: This should only be run once, and the results should be verified
 * using the fiscal:verify-chains command afterward.
 */
class BackfillFiscalHashesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fiscal:backfill
                            {--company= : Specific company ID to backfill}
                            {--type= : Document type to backfill (invoice, credit_note)}
                            {--dry-run : Preview changes without applying them}
                            {--force : Skip confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Backfill fiscal hashes for existing posted documents';

    /**
     * Document types that require fiscal hash chain.
     *
     * @var list<DocumentType>
     */
    private const array FISCAL_DOCUMENT_TYPES = [
        DocumentType::Invoice,
        DocumentType::CreditNote,
    ];

    public function __construct(
        private readonly FiscalHashService $hashService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForced = (bool) $this->option('force');

        $this->info($isDryRun ? 'DRY RUN - No changes will be made' : 'Starting fiscal hash backfill...');
        $this->newLine();

        /** @var string|null $companyId */
        $companyId = $this->option('company');

        /** @var string|null $documentType */
        $documentType = $this->option('type');

        /** @var Collection<int, Company> $companies */
        $companies = $companyId
            ? Company::where('id', $companyId)->get()
            : Company::all();

        if ($companies->isEmpty()) {
            $this->error('No companies found.');

            return Command::FAILURE;
        }

        // Count documents to be processed
        $totalToProcess = 0;
        foreach ($companies as $company) {
            $types = $documentType
                ? [DocumentType::from($documentType)]
                : self::FISCAL_DOCUMENT_TYPES;

            foreach ($types as $type) {
                $count = Document::where('company_id', $company->id)
                    ->where('type', $type)
                    ->where('status', DocumentStatus::Posted)
                    ->whereNull('fiscal_hash')
                    ->count();

                $totalToProcess += $count;
            }
        }

        if ($totalToProcess === 0) {
            $this->info('No documents require backfill. All posted documents already have fiscal hashes.');

            return Command::SUCCESS;
        }

        $this->warn("Found {$totalToProcess} document(s) without fiscal hashes.");

        if (! $isDryRun && ! $isForced) {
            if (! $this->confirm('Do you want to proceed with backfilling?')) {
                $this->info('Backfill cancelled.');

                return Command::SUCCESS;
            }
        }

        $processedCount = 0;
        $errorCount = 0;

        foreach ($companies as $company) {
            /** @var Company $company */
            $this->info("Processing company: {$company->name} ({$company->id})");

            $types = $documentType
                ? [DocumentType::from($documentType)]
                : self::FISCAL_DOCUMENT_TYPES;

            foreach ($types as $type) {
                $result = $this->backfillForCompanyAndType($company->id, $type, $isDryRun);
                $processedCount += $result['processed'];
                $errorCount += $result['errors'];
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info('Backfill Summary:');
        $this->info("  Documents processed: {$processedCount}");

        if ($errorCount > 0) {
            $this->error("  Errors: {$errorCount}");

            return Command::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('  This was a dry run. Run without --dry-run to apply changes.');
        } else {
            $this->info('  Status: SUCCESS ✓');
            $this->info('  Run "php artisan fiscal:verify-chains" to verify the hash chains.');
        }

        return Command::SUCCESS;
    }

    /**
     * Backfill fiscal hashes for a specific company and document type.
     *
     * @return array{processed: int, errors: int}
     */
    private function backfillForCompanyAndType(string $companyId, DocumentType $type, bool $isDryRun): array
    {
        // Get all posted documents without fiscal hash, ordered by document_date and created_at
        // This ensures we process them in chronological order for proper chain linking
        $documents = Document::where('company_id', $companyId)
            ->where('type', $type)
            ->where('status', DocumentStatus::Posted)
            ->whereNull('fiscal_hash')
            ->orderBy('document_date')
            ->orderBy('created_at')
            ->get();

        if ($documents->isEmpty()) {
            $this->line("  {$type->value}: No documents to backfill");

            return ['processed' => 0, 'errors' => 0];
        }

        $this->line("  {$type->value}: Found {$documents->count()} document(s) to backfill");

        $processed = 0;
        $errors = 0;

        // Get the last document with a fiscal hash (if any) to continue the chain
        $lastHashedDoc = Document::where('company_id', $companyId)
            ->where('type', $type)
            ->where('status', DocumentStatus::Posted)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('chain_sequence')
            ->first();

        $previousHash = $lastHashedDoc?->fiscal_hash;
        $chainSequence = $lastHashedDoc !== null ? $lastHashedDoc->chain_sequence : 0;

        foreach ($documents as $document) {
            try {
                $chainSequence++;

                $input = $this->hashService->serializeForHashing([
                    'document_number' => $document->document_number,
                    'posted_at' => $document->document_date->toDateString(),
                    'total' => $document->total ?? '0.00',
                    'currency' => $document->currency,
                ]);

                $fiscalHash = $this->hashService->calculateHash($input, $previousHash);

                if ($isDryRun) {
                    $this->line("    → Would update {$document->document_number}: hash={$fiscalHash}, seq={$chainSequence}");
                } else {
                    DB::transaction(function () use ($document, $fiscalHash, $previousHash, $chainSequence): void {
                        $document->update([
                            'fiscal_hash' => $fiscalHash,
                            'previous_hash' => $previousHash,
                            'chain_sequence' => $chainSequence,
                        ]);
                    });

                    $this->line("    ✓ Updated {$document->document_number}");
                }

                $previousHash = $fiscalHash;
                $processed++;
            } catch (\Throwable $e) {
                $this->error("    ✗ Failed to process {$document->document_number}: {$e->getMessage()}");
                $errors++;
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }
}
