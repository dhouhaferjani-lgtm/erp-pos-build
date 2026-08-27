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
 *
 * **Coverage block (2026-08-05 review, B3/C3).** The run closes with a
 * `TENANT COVERAGE:` block carrying one line for EVERY directory tenant it
 * touched — `verified` / `N CHAIN(S) INVALID` / `NO-DATA` / `SKIPPED` /
 * `ERRORED` — because a tenant with no companies, a tenant whose database could
 * not be opened, and a tenant whose run threw were all previously invisible in
 * the output, so the E-7 pack could not demonstrate coverage.
 *
 * **The PASS line is a true summary (2026-08-05 review, B2/C2).**
 * `Status: ALL CHAINS VALID ✓` is the string step D.1 of
 * `docs/qa/2026-05-12-first-tenant-smoke.md` ticks as PASS. It is now
 * unreachable unless the aggregate exit is 0 AND no tenant was skipped by the
 * database probe AND no tenant's run threw. Anything else prints
 * `Status: INCOMPLETE` naming the tenants involved. Exit codes are 0/1 only —
 * the base's {@see TenantScopedCommand::INVALID} (2) for an unknown `--tenant`
 * is collapsed onto 1 (M2).
 *
 * **`--fix` removed (2026-08-05 review, R6).** The flag was declared,
 * documented as dangerous, warned against in two QA plans — and read by
 * nothing. A server-side "fix" of a device-authored chain would itself be a
 * fiscal-integrity defect, so the flag is gone rather than implemented.
 */
final class VerifyFiscalChainsCommand extends TenantScopedCommand
{
    /**
     * @var string
     */
    protected $signature = 'fiscal:verify-chains
                            {--tenant= : Specific tenant UUID to verify (default: every tenant)}
                            {--company= : Specific company ID to verify}
                            {--type= : Document type to verify (invoice, credit_note)}';

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

        // R1 (2026-08-05 fiscal review): `DocumentType::from()` used to run
        // inside the per-tenant closure, so an invalid `--type` raised a
        // `ValueError` that `forEachTenant()` swallowed into a FAILURE — AFTER
        // `$companiesVerified` had been incremented, which put the summary
        // block (and its `Status: ALL CHAINS VALID ✓` line) on the screen for a
        // run that verified nothing. Validate up front, exactly as
        // `pos:verify-chains` already did for its own `--type`.
        $documentTypeOption = $this->stringOption('type');
        $types = $this->resolveDocumentTypes($documentTypeOption);
        if ($types === null) {
            $this->error(sprintf(
                "Invalid type '%s'. Must be one of: %s",
                (string) $documentTypeOption,
                implode(', ', array_map(static fn (DocumentType $t): string => $t->value, self::VERIFIABLE_TYPES)),
            ));

            return Command::FAILURE;
        }

        $companiesVerified = 0;
        $totalDocuments = 0;
        $invalidChains = 0;
        /** @var array<string, string> $verdicts */
        $verdicts = [];

        $exit = $this->forEachTenantFiltered(
            $this->stringOption('tenant'),
            function (Tenant $tenant) use (
                $companyFilter,
                $types,
                &$companiesVerified,
                &$totalDocuments,
                &$invalidChains,
                &$verdicts,
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
                    // Recorded, not silent: a tenant with nothing to verify is
                    // still coverage an E-7 reviewer has to be able to see.
                    $verdicts[(string) $tenant->id] = 'NO-DATA (no company matched this run)';

                    return self::SUCCESS;
                }

                $tenantInvalidChains = 0;

                foreach ($companies as $company) {
                    $companiesVerified++;
                    $this->info("Verifying company: {$company->name} ({$company->id})");

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
                    $verdicts[(string) $tenant->id] = sprintf('%d CHAIN(S) INVALID', $tenantInvalidChains);
                    $this->error(sprintf(
                        'TENANT %s (%s): %d CHAIN(S) INVALID ✗',
                        $tenant->id,
                        $tenant->slug,
                        $tenantInvalidChains,
                    ));

                    return self::FAILURE;
                }

                $verdicts[(string) $tenant->id] = sprintf('verified (%d company(ies))', $companies->count());
                $this->info(sprintf('TENANT %s (%s): ALL CHAINS VALID ✓', $tenant->id, $tenant->slug));

                return self::SUCCESS;
            },
        );

        // Coverage FIRST, verdict second — the summary below is only allowed to
        // print its PASS line once every directory tenant is accounted for.
        $erroredTenants = $this->reportTenantCoverage($verdicts);
        $unaccounted = array_merge($erroredTenants, $this->skippedTenantIds());

        if ($companiesVerified === 0 && $unaccounted === [] && $exit === Command::SUCCESS) {
            // Historic message and exit code preserved. What changed is that
            // it can no longer be produced by "the console cannot see the
            // companies table" — every reachable tenant was opened and asked.
            $this->error('No companies found.');

            return Command::FAILURE;
        }

        $this->newLine();
        $this->info('Verification Summary:');
        $this->info("  Companies verified: {$companiesVerified}");
        $this->info("  Total documents verified: {$totalDocuments}");

        if ($invalidChains > 0) {
            $this->error("  Status: {$invalidChains} CHAIN(S) INVALID ✗");

            return Command::FAILURE;
        }

        if ($unaccounted !== []) {
            // B2/C2 (2026-08-05 fiscal review). `$invalidChains` only ever
            // captured verdicts the closure COMPUTED. A tenant whose closure
            // threw, or whose database could not be opened, left it at 0 while
            // `$companiesVerified` had already been incremented by the tenants
            // that did run — so control reached this branch and printed the
            // exact string `docs/qa/2026-05-12-first-tenant-smoke.md:117` ticks
            // as PASS, then returned a non-zero exit nobody reads.
            $this->error(sprintf(
                '  Status: INCOMPLETE - %d tenant(s) produced no verdict: %s. Nothing was verified for them; '.
                'this run is NOT evidence that their chains are intact.',
                count($unaccounted),
                implode(', ', $unaccounted),
            ));

            return Command::FAILURE;
        }

        if ($exit !== Command::SUCCESS) {
            // The base reported a non-zero aggregate for a reason the counters
            // above cannot see. Never print the PASS line over it.
            $this->error('  Status: INCOMPLETE - the run did not complete cleanly; see the errors above.');

            return Command::FAILURE;
        }

        $this->info('  Status: ALL CHAINS VALID ✓');

        return Command::SUCCESS;
    }

    /**
     * The document types this verifier walks. `--type` narrows to one of them;
     * absent, both are walked.
     *
     * @var list<DocumentType>
     */
    private const array VERIFIABLE_TYPES = [DocumentType::Invoice, DocumentType::CreditNote];

    /**
     * @return list<DocumentType>|null null when the option names a type this
     *                                 command cannot verify
     */
    private function resolveDocumentTypes(?string $documentType): ?array
    {
        if ($documentType === null) {
            return self::VERIFIABLE_TYPES;
        }

        $type = DocumentType::tryFrom($documentType);

        return $type !== null && in_array($type, self::VERIFIABLE_TYPES, true) ? [$type] : null;
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
                // R-2 / LEDGER D-T9-1: the chain is built from documents that were SEALED,
                // and a seal is only reachable past `Draft` — where the number is allocated.
                // Recomputing a chain input from a NULL number would silently produce a
                // different hash than the one on the row, so it fails loudly instead.
                'document_number' => $document->requireDocumentNumber(),
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
