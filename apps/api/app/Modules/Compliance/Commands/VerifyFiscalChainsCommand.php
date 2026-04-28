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

class VerifyFiscalChainsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fiscal:verify-chains
                            {--company= : Specific company ID to verify}
                            {--type= : Document type to verify (invoice, credit_note)}
                            {--fix : Attempt to fix broken chains (dangerous)}';

    /**
     * @var string
     */
    protected $description = 'Verify the integrity of fiscal hash chains';

    public function __construct(
        private readonly FiscalHashService $hashService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting fiscal chain verification...');
        $this->newLine();

        $companyId = $this->option('company');
        $documentType = $this->option('type');

        /** @var Collection<int, Company> $companies */
        $companies = $companyId
            ? Company::where('id', $companyId)->get()
            : Company::all();

        if ($companies->isEmpty()) {
            $this->error('No companies found.');

            return Command::FAILURE;
        }

        $overallValid = true;
        $totalDocuments = 0;
        $invalidChains = 0;

        foreach ($companies as $company) {
            /** @var Company $company */
            $this->info("Verifying company: {$company->name} ({$company->id})");

            /** @var string|null $typeOption */
            $typeOption = $documentType;
            $types = $typeOption
                ? [DocumentType::from($typeOption)]
                : [DocumentType::Invoice, DocumentType::CreditNote];

            foreach ($types as $type) {
                $result = $this->verifyChainForCompanyAndType($company->id, $type, $company->fiscal_chain_seed);

                $totalDocuments += $result['count'];

                if (! $result['valid']) {
                    $overallValid = false;
                    $invalidChains++;
                    $this->error("  ✗ {$type->value}: INVALID at sequence {$result['failed_at']}");

                    if ($result['details']) {
                        $this->warn("    → {$result['details']}");
                    }
                } else {
                    $this->info("  ✓ {$type->value}: Valid ({$result['count']} documents)");
                }
            }

            $this->newLine();
        }

        $this->newLine();
        $this->info('Verification Summary:');
        $this->info("  Total documents verified: {$totalDocuments}");

        if ($overallValid) {
            $this->info('  Status: ALL CHAINS VALID ✓');

            return Command::SUCCESS;
        }

        $this->error("  Status: {$invalidChains} CHAIN(S) INVALID ✗");

        return Command::FAILURE;
    }

    /**
     * @return array{valid: bool, count: int, failed_at: int|null, details: string|null}
     */
    private function verifyChainForCompanyAndType(string $companyId, DocumentType $type, ?string $genesisSeed): array
    {
        $documents = Document::where('company_id', $companyId)
            ->where('type', $type)
            ->where('status', DocumentStatus::Posted)
            ->whereNotNull('fiscal_hash')
            ->orderBy('chain_sequence')
            ->get();

        if ($documents->isEmpty()) {
            return [
                'valid' => true,
                'count' => 0,
                'failed_at' => null,
                'details' => null,
            ];
        }

        $previousHash = null;

        foreach ($documents as $document) {
            // Check chain sequence continuity
            if ($document->previous_hash !== $previousHash) {
                return [
                    'valid' => false,
                    'count' => $documents->count(),
                    'failed_at' => $document->chain_sequence,
                    'details' => "Previous hash mismatch for {$document->document_number}",
                ];
            }

            // Verify the document's own hash
            $input = $this->hashService->serializeForHashing([
                'document_number' => $document->document_number,
                'posted_at' => $document->document_date->toDateString(),
                'total' => $document->total ?? '0.00',
                'currency' => $document->currency,
            ]);

            // Pass the company's genesis seed so the genesis document (previousHash === null)
            // recomputes to the same hash that was written by DocumentPostingService.
            $storedHash = $document->fiscal_hash ?? '';
            $seedForThisDoc = $previousHash === null ? $genesisSeed : null;
            if (! $this->hashService->verifyHash($input, $previousHash, $storedHash, $seedForThisDoc)) {
                return [
                    'valid' => false,
                    'count' => $documents->count(),
                    'failed_at' => $document->chain_sequence,
                    'details' => "Hash verification failed for {$document->document_number}",
                ];
            }

            $previousHash = $document->fiscal_hash;
        }

        return [
            'valid' => true,
            'count' => $documents->count(),
            'failed_at' => null,
            'details' => null,
        ];
    }
}
