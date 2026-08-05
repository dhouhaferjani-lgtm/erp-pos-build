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

/**
 * `fiscal:verify-chains` — document-side (invoice / credit-note) fiscal hash
 * chain verifier. Named as a launch verifier in
 * `docs/handoff/DISPATCH-PLAN-v4-first-tenant-2026-07-31.md:130` and step D.1
 * of `docs/qa/2026-05-12-first-tenant-smoke.md`.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter), converted 2026-08-05.
 *
 * Before the conversion the command opened with `Company::all()` on the
 * console's CENTRAL connection. `companies` is a TENANT table, so after the
 * 2026-05-28 database-per-tenant flip a bare fleet run raised 42P01 and a
 * `--company=<uuid>` run returned an empty set — i.e. the NF525 verifier the
 * launch program leans on could not verify anything at all.
 *
 * The verification LOGIC is untouched: same seed handling, same per-document
 * link + rehash checks, same `--type` narrowing. Only the context changed —
 * companies are now enumerated inside each tenant's own database.
 *
 * **Evidence shape.** Every verdict names the tenant it belongs to
 * (`TENANT <id> (<slug>): …`) because the output is filed as E-7 evidence,
 * and the aggregate exit is non-zero if ANY tenant reports a broken chain.
 * `--company` remains an in-tenant narrowing filter, but a company that no
 * reachable tenant owns is now a loud failure rather than a clean
 * "No companies found." — the historic message is kept, the exit code it
 * carried (FAILURE) is unchanged.
 */
final class VerifyFiscalChainsCommand extends TenantScopedCommand
{
    /**
     * @var string
     */
    protected $signature = 'fiscal:verify-chains
                            {--tenant= : Specific tenant UUID to verify (default: every tenant)}
                            {--company= : Specific company ID to verify}
                            {--type= : Document type to verify (invoice, credit_note)}
                            {--fix : Attempt to fix broken chains (dangerous)}';

    /**
     * @var string
     */
    protected $description = 'Verify the integrity of fiscal hash chains, per tenant';

    public function __construct(
        CompanyContext $companyContext,
        private readonly FiscalHashService $hashService,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $this->info('Starting fiscal chain verification...');
        $this->newLine();

        $companyFilter = $this->stringOption('company');
        $documentType = $this->stringOption('type');

        $companiesVerified = 0;
        $totalDocuments = 0;
        $invalidChains = 0;

        $exit = $this->forEachTenantFiltered(
            $this->stringOption('tenant'),
            function (Tenant $tenant) use (
                $companyFilter,
                $documentType,
                &$companiesVerified,
                &$totalDocuments,
                &$invalidChains,
            ): int {
                // The explicit tenant_id predicate is redundant under
                // database-per-tenant and load-bearing in single-schema
                // compatibility mode, where one shared database holds every
                // tenant's companies.
                $companies = Company::query()
                    ->where('tenant_id', $tenant->id)
                    ->when($companyFilter !== null, fn ($query) => $query->where('id', $companyFilter))
                    ->get();

                if ($companies->isEmpty()) {
                    return self::SUCCESS;
                }

                $tenantInvalidChains = 0;

                foreach ($companies as $company) {
                    $companiesVerified++;
                    $this->info("Verifying company: {$company->name} ({$company->id})");

                    $types = $documentType !== null
                        ? [DocumentType::from($documentType)]
                        : [DocumentType::Invoice, DocumentType::CreditNote];

                    foreach ($types as $type) {
                        $result = $this->verifyChainForCompanyAndType(
                            (string) $company->id,
                            $type,
                            $company->fiscal_chain_seed,
                        );

                        $totalDocuments += $result['count'];

                        if (! $result['valid']) {
                            $tenantInvalidChains++;
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

                $invalidChains += $tenantInvalidChains;

                if ($tenantInvalidChains > 0) {
                    $this->error(sprintf(
                        'TENANT %s (%s): %d CHAIN(S) INVALID ✗',
                        $tenant->id,
                        $tenant->slug,
                        $tenantInvalidChains,
                    ));

                    return self::FAILURE;
                }

                $this->info(sprintf('TENANT %s (%s): ALL CHAINS VALID ✓', $tenant->id, $tenant->slug));

                return self::SUCCESS;
            },
        );

        if ($companiesVerified === 0) {
            // Historic message and exit code preserved. What changed is that
            // it can no longer be produced by "the console cannot see the
            // companies table" — every reachable tenant was opened and asked.
            $this->error('No companies found.');

            return $exit === Command::SUCCESS ? Command::FAILURE : $exit;
        }

        $this->newLine();
        $this->info('Verification Summary:');
        $this->info("  Companies verified: {$companiesVerified}");
        $this->info("  Total documents verified: {$totalDocuments}");

        if ($invalidChains === 0) {
            $this->info('  Status: ALL CHAINS VALID ✓');

            return $exit;
        }

        $this->error("  Status: {$invalidChains} CHAIN(S) INVALID ✗");

        return $exit === Command::SUCCESS ? Command::FAILURE : $exit;
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
