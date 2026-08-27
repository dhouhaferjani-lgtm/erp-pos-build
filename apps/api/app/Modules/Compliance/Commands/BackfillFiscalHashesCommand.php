<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Command to backfill fiscal hashes for existing posted documents.
 *
 * This command is used to retroactively add hash chain entries to documents
 * that were posted before the NF525 compliance implementation was complete.
 *
 * IMPORTANT: This should only be run once, and the results should be verified
 * using the fiscal:verify-chains command afterward.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter) behind an EXPLICIT scope,
 * converted 2026-08-05 (cat-(b) wave 2).
 *
 * The command used to open with `Company::all()` on the console's CENTRAL
 * connection and call that the fleet. `companies` is a TENANT table, so after
 * the 2026-05-28 database-per-tenant flip it raised 42P01 and backfilled
 * nothing.
 *
 * A one-time backfill that silently skips a tenant leaves permanently wrong
 * fiscal data behind, so the scope must now be named:
 * `--tenant=<uuid>` for one tenant, or `--all-tenants` for a deliberate fleet
 * run. Neither (or both) is a usage error and nothing is processed.
 *
 * The hashing LOGIC — chronological ordering, chain continuation from the last
 * hashed document, per-document transaction — is untouched.
 *
 * The confirmation prompt is now per tenant (it reports that tenant's document
 * count, as it always reported the run's count) and `--force` still skips it.
 */
final class BackfillFiscalHashesCommand extends TenantScopedCommand
{
    /**
     * @var string
     */
    protected $signature = 'fiscal:backfill
                            {--tenant= : Tenant UUID to backfill (required unless --all-tenants)}
                            {--all-tenants : Deliberate fleet-wide run over every reachable tenant}
                            {--company= : Specific company ID to backfill}
                            {--type= : Document type to backfill (invoice, credit_note)}
                            {--dry-run : Preview changes without applying them}
                            {--force : Skip confirmation prompt}';

    /**
     * @var string
     */
    protected $description = 'Backfill fiscal hashes for existing posted documents (per tenant)';

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
        CompanyContext $companyContext,
        private readonly FiscalHashService $hashService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForced = (bool) $this->option('force');

        $this->info($isDryRun ? 'DRY RUN - No changes will be made' : 'Starting fiscal hash backfill...');
        $this->newLine();

        $companyFilter = $this->stringOption('company');
        $documentType = $this->stringOption('type');

        $companiesSeen = 0;
        $processedCount = 0;
        $errorCount = 0;

        $exit = $this->forEachExplicitlySelectedTenant(
            $this->stringOption('tenant'),
            $this->option('all-tenants') === true,
            function (Tenant $tenant) use (
                $companyFilter,
                $documentType,
                $isDryRun,
                $isForced,
                &$companiesSeen,
                &$processedCount,
                &$errorCount,
            ): int {
                // The explicit tenant_id predicate is redundant under
                // database-per-tenant and load-bearing in single-schema
                // compatibility mode.
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when($companyFilter !== null, fn ($query) => $query->where('id', $companyFilter))
                    ->get();

                if ($companies->isEmpty()) {
                    return Command::SUCCESS;
                }

                $companiesSeen += $companies->count();

                $types = $documentType !== null
                    ? [DocumentType::from($documentType)]
                    : self::FISCAL_DOCUMENT_TYPES;

                $tenantToProcess = 0;
                foreach ($companies as $company) {
                    foreach ($types as $type) {
                        $tenantToProcess += Document::where('company_id', $company->id)
                            ->where('type', $type)
                            ->where('status', DocumentStatus::Posted)
                            ->whereNull('fiscal_hash')
                            ->count();
                    }
                }

                if ($tenantToProcess === 0) {
                    $this->info(sprintf(
                        'TENANT %s (%s): no documents require backfill.',
                        $tenant->id,
                        $tenant->slug,
                    ));

                    return Command::SUCCESS;
                }

                $this->warn(sprintf(
                    'TENANT %s (%s): found %d document(s) without fiscal hashes.',
                    $tenant->id,
                    $tenant->slug,
                    $tenantToProcess,
                ));

                if (! $isDryRun && ! $isForced && ! $this->confirm('Do you want to proceed with backfilling this tenant?')) {
                    $this->info('Backfill cancelled for this tenant.');

                    return Command::SUCCESS;
                }

                foreach ($companies as $company) {
                    /** @var Company $company */
                    $this->info("Processing company: {$company->name} ({$company->id})");

                    foreach ($types as $type) {
                        $result = $this->backfillForCompanyAndType((string) $company->id, $type, $isDryRun);
                        $processedCount += $result['processed'];
                        $errorCount += $result['errors'];
                    }

                    $this->newLine();
                }

                return Command::SUCCESS;
            },
        );

        if ($exit !== Command::SUCCESS) {
            return $exit;
        }

        if ($companiesSeen === 0) {
            $this->error('No companies found.');

            return Command::FAILURE;
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
                    // R-2 / LEDGER D-T9-1: the chain is built from documents that were SEALED,
                    // and a seal is only reachable past `Draft` — where the number is allocated.
                    // Recomputing a chain input from a NULL number would silently produce a
                    // different hash than the one on the row, so it fails loudly instead.
                    'document_number' => $document->requireDocumentNumber(),
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
