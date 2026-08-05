<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Tests\Traits\ProvisionsTenantDatabases;

/**
 * VerifyFiscalChainGenesisDocumentTest
 *
 * Closes audit finding 🔴-5: `fiscal:verify-chains` previously reported INVALID for
 * every tenant's first (genesis) document. Root cause was that the write path used
 * the company's `fiscal_chain_seed` when hashing the genesis document, but the
 * verify path called `FiscalHashService::verifyHash()` which recomputed the
 * expected hash without the seed.
 *
 * These tests lock in:
 *  - Happy path: genesis invoice verifies cleanly via the artisan command.
 *  - Parity: stored hashes equal hashes calculated with the correct seed.
 *  - Negative: tampered documents are caught.
 *  - Multi-vertical: all 5 seeded verticals verify cleanly from a fresh post.
 *  - Empty chain: tenants without posted documents verify as valid.
 */
class VerifyFiscalChainGenesisDocumentTest extends TestCase
{
    use ProvisionsTenantDatabases;
    use RefreshDatabase;

    private DocumentPostingService $postingService;

    private FiscalHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postingService = app(DocumentPostingService::class);
        $this->hashService = app(FiscalHashService::class);

        // InvoicePostedListener writes to chart of accounts; short-circuit it for these tests.
        Event::fake([InvoicePosted::class]);
    }

    public function test_verify_command_passes_for_genesis_document(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('genesis-co');

        $invoice = $this->createConfirmedInvoice($tenant, $company, $partner, 'INV-G-0001');
        $posted = $this->postingService->post($invoice);

        $this->assertSame(DocumentStatus::Posted, $posted->status, 'Precondition: document must be posted');
        $this->assertNotNull($posted->fiscal_hash, 'Precondition: genesis doc must have a fiscal hash');
        $this->assertNull($posted->previous_hash, 'Precondition: genesis doc must have null previous_hash');

        [$exit, $output] = $this->runVerify($company->id);

        $this->assertSame(0, $exit, 'Genesis document must verify as valid via artisan fiscal:verify-chains');
        $this->assertStringContainsString('ALL CHAINS VALID', $output, 'Command output must contain success banner');
    }

    public function test_stored_hashes_match_seed_based_recalculation_across_chain(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('parity-co');

        $inv1 = $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-P-0001'));
        $inv2 = $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-P-0002'));
        $inv3 = $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-P-0003'));

        /** @var Company $refreshedCompany */
        $refreshedCompany = Company::findOrFail($company->id);
        $seed = $refreshedCompany->fiscal_chain_seed;
        $this->assertNotNull($seed, 'Company must have a fiscal_chain_seed');

        // Genesis: hashed using company seed, previousHash=null
        $input1 = $this->serialize($inv1);
        $expected1 = $this->hashService->calculateHash($input1, null, $seed);
        $this->assertSame($expected1, $inv1->fiscal_hash, 'Genesis fiscal_hash must match seed-based calculation');

        // Chained: no seed, previousHash=prior.fiscal_hash
        $input2 = $this->serialize($inv2);
        $expected2 = $this->hashService->calculateHash($input2, $inv1->fiscal_hash);
        $this->assertSame($expected2, $inv2->fiscal_hash, 'Second doc fiscal_hash must match chained calculation');

        $input3 = $this->serialize($inv3);
        $expected3 = $this->hashService->calculateHash($input3, $inv2->fiscal_hash);
        $this->assertSame($expected3, $inv3->fiscal_hash, 'Third doc fiscal_hash must match chained calculation');

        // And the verify command agrees.
        [$exit, $output] = $this->runVerify($company->id);
        $this->assertSame(0, $exit, 'fiscal:verify-chains must pass for a legitimate 3-doc chain. Output: '.$output);
    }

    public function test_verify_command_detects_tampering(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('tamper-co');

        $inv1 = $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-T-0001'));
        $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-T-0002'));

        // Tamper: change the stored total of the first (genesis) doc directly in DB,
        // simulating an attacker who bypasses the application layer. On PostgreSQL
        // the trg_document_immutability trigger blocks even raw UPDATEs on a sealed
        // row, so disable it for the injection (SQLite has no such trigger). This is
        // exactly the post-seal mutation the verify command must still detect.
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($isPgsql) {
            DB::statement('ALTER TABLE documents DISABLE TRIGGER trg_document_immutability');
        }

        DB::table('documents')->where('id', $inv1->id)->update(['total' => '999999.99']);

        if ($isPgsql) {
            DB::statement('ALTER TABLE documents ENABLE TRIGGER trg_document_immutability');
        }

        [$exit, $output] = $this->runVerify($company->id);

        $this->assertSame(1, $exit, 'Tampered genesis document must fail verification');
        $this->assertStringContainsString('INVALID', $output, 'Command output must flag chain invalid');
    }

    public function test_verify_command_passes_across_all_five_verticals(): void
    {
        // Covers the NF525 critical assertion: every seeded vertical must verify
        // cleanly after posting genesis + chained invoices.
        $verticalSlugs = [
            'mechanic-verify',
            'retail-verify',
            'pharmacy-verify',
            'coffee-verify',
            'restaurant-verify',
        ];

        foreach ($verticalSlugs as $slug) {
            [$tenant, $company, $partner] = $this->makeTenantCompanyPartner($slug);

            // Two docs so we exercise both genesis and chained verification paths.
            $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, strtoupper($slug).'-INV-0001'));
            $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, strtoupper($slug).'-INV-0002'));

            [$exit, $output] = $this->runVerify($company->id);
            $this->assertSame(0, $exit, "Vertical {$slug} must verify cleanly. Output: ".$output);
        }
    }

    public function test_verify_command_passes_for_empty_chain(): void
    {
        [, $company] = $this->makeTenantCompanyPartner('empty-co');

        // No documents posted.
        [$exit, $output] = $this->runVerify($company->id);

        $this->assertSame(0, $exit, 'A company with zero posted documents must verify as valid');
        $this->assertStringContainsString('ALL CHAINS VALID', $output, 'Empty chain output must contain success banner');
    }

    // =================================================================
    // Per-tenant iteration (cat-(b) wave 2, 2026-08-05)
    //
    // `fiscal:verify-chains` used to open with `Company::all()` on the
    // console's CENTRAL connection. `companies` is a TENANT table, so after
    // the database-per-tenant flip a bare fleet run raised 42P01 and a
    // `--company` run matched nothing. These lock in the replacement: iterate
    // the tenant directory, attribute every verdict, and fail the aggregate if
    // ANY tenant is broken.
    // =================================================================

    public function test_fleet_run_attributes_verdicts_per_tenant_and_fails_if_any_tenant_is_broken(): void
    {
        [$cleanTenant, $cleanCompany, $cleanPartner] = $this->makeTenantCompanyPartner('fleet-clean-co');
        $this->postingService->post($this->createConfirmedInvoice($cleanTenant, $cleanCompany, $cleanPartner, 'INV-FC-0001'));

        [$brokenTenant, $brokenCompany, $brokenPartner] = $this->makeTenantCompanyPartner('fleet-broken-co');
        $broken = $this->postingService->post($this->createConfirmedInvoice($brokenTenant, $brokenCompany, $brokenPartner, 'INV-FB-0001'));
        $this->tamperWithTotal((string) $broken->id);

        [$exit, $output] = $this->runVerifyWith([]);

        $this->assertSame(1, $exit, 'A broken chain in one tenant must fail the fleet run. Output: '.$output);
        $this->assertStringContainsString(
            sprintf('TENANT %s (fleet-clean-co): ALL CHAINS VALID', $cleanTenant->id),
            $output,
            'The clean tenant must carry its own attributable PASS verdict',
        );
        $this->assertStringContainsString(
            sprintf('TENANT %s (fleet-broken-co): 1 CHAIN(S) INVALID', $brokenTenant->id),
            $output,
            'The broken tenant must carry its own attributable FAIL verdict',
        );
    }

    public function test_tenant_filter_narrows_verification_to_one_tenant(): void
    {
        [$cleanTenant, $cleanCompany, $cleanPartner] = $this->makeTenantCompanyPartner('narrow-clean-co');
        $this->postingService->post($this->createConfirmedInvoice($cleanTenant, $cleanCompany, $cleanPartner, 'INV-NC-0001'));

        [$brokenTenant, $brokenCompany, $brokenPartner] = $this->makeTenantCompanyPartner('narrow-broken-co');
        $broken = $this->postingService->post($this->createConfirmedInvoice($brokenTenant, $brokenCompany, $brokenPartner, 'INV-NB-0001'));
        $this->tamperWithTotal((string) $broken->id);

        [$exit, $output] = $this->runVerifyWith(['--tenant' => $cleanTenant->id]);

        $this->assertSame(0, $exit, 'The clean tenant must pass in isolation. Output: '.$output);
        $this->assertStringNotContainsString($brokenTenant->id, $output, 'The filtered-out tenant must not be verified');
    }

    public function test_unknown_tenant_filter_fails_instead_of_reporting_all_chains_valid(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('unknown-filter-co');
        $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-UF-0001'));

        [$exit, $output] = $this->runVerifyWith(['--tenant' => '00000000-0000-0000-0000-000000000000']);

        $this->assertNotSame(0, $exit, 'An unknown --tenant must not exit 0. Output: '.$output);
        $this->assertStringContainsString('not found in the central tenant directory', $output);
    }

    public function test_unknown_company_filter_fails_instead_of_reporting_all_chains_valid(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('unknown-company-co');
        $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-UC-0001'));

        [$exit, $output] = $this->runVerifyWith(['--company' => '00000000-0000-0000-0000-000000000000']);

        $this->assertSame(1, $exit, 'A company no reachable tenant owns must fail. Output: '.$output);
        $this->assertStringContainsString('No companies found.', $output);
        $this->assertStringNotContainsString('ALL CHAINS VALID', $output);
    }

    // =================================================================
    // Banner truth + coverage (2026-08-05 wave-2 fiscal review: B2/C2,
    // B3/C3, R1, R6, M2).
    //
    // `Status: ALL CHAINS VALID ✓` is the literal string
    // `docs/qa/2026-05-12-first-tenant-smoke.md:117` ticks as PASS, so it must
    // be unreachable for any run that did not actually verify every tenant.
    // =================================================================

    public function test_the_coverage_block_names_every_directory_tenant_including_the_ones_with_nothing_to_verify(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('coverage-with-data');
        $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-CV-0001'));

        $emptyTenant = Tenant::factory()->create(['slug' => 'coverage-no-companies']);

        [$exit, $output] = $this->runVerifyWith([]);

        $this->assertSame(0, $exit, 'A tenant with no companies is coverage, not a failure. Output: '.$output);
        $this->assertStringContainsString('TENANT COVERAGE:', $output);
        $this->assertStringContainsString(
            sprintf('TENANT %s: verified', $tenant->id),
            $output,
        );
        $this->assertStringContainsString(
            sprintf('TENANT %s: NO-DATA', $emptyTenant->id),
            $output,
            'A tenant with no companies emitted no line at all before, so the E-7 pack could not show coverage.',
        );
        $this->assertStringContainsString('Status: ALL CHAINS VALID', $output);
    }

    /**
     * B2/B3. A tenant whose database cannot be opened is skipped by
     * `forEachTenant()` with only a `Log::warning`, and touches none of the
     * command's own counters — so the summary block used to print the PASS
     * line and then return a non-zero exit nobody reads.
     */
    public function test_a_skipped_tenant_blocks_the_pass_line_and_is_named_in_the_coverage_block(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $reachable = $this->provisionTenantDatabaseWithSchema(Tenant::factory()->create(['slug' => 'skip-reachable']));
        $unreachable = Tenant::factory()->create(['slug' => 'skip-unreachable']);

        // The reachable tenant must contribute a real verdict, otherwise the
        // run lands on the pre-existing "No companies found." branch and this
        // test could not tell the B2/B3 fix from the old behaviour: with
        // `$companiesVerified > 0` and a skip that leaves the aggregate at
        // SUCCESS, the old code printed `Status: ALL CHAINS VALID ✓` and
        // exited 0.
        $this->withinTenantDatabase($reachable, static function (Tenant $tenant): void {
            Company::factory()->create(['tenant_id' => $tenant->id]);
        });

        [$exit, $output] = $this->runVerifyWith([]);

        $this->assertSame(1, $exit, 'A tenant that was never opened must not leave the run green. Output: '.$output);
        $this->assertStringNotContainsString(
            'Status: ALL CHAINS VALID',
            $output,
            'The PASS string the launch checklist ticks must be unreachable when a tenant was never verified.',
        );
        $this->assertStringContainsString('Status: INCOMPLETE', $output);
        $this->assertStringContainsString($unreachable->id, $output, 'The skipped tenant must be named.');
        $this->assertStringContainsString(
            sprintf('TENANT %s: SKIPPED', $unreachable->id),
            $output,
        );
        $this->assertStringContainsString($reachable->id, $output);
    }

    /**
     * R1. `DocumentType::from()` used to run inside the per-tenant closure, so
     * an invalid `--type` raised a `ValueError` that `forEachTenant()` swallowed
     * into a FAILURE — after `$companiesVerified` had already been incremented,
     * which put `Status: ALL CHAINS VALID ✓` on screen for a run that verified
     * nothing.
     */
    public function test_an_invalid_type_fails_up_front_without_printing_the_pass_line(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('bad-type-co');
        $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-BT-0001'));

        [$exit, $output] = $this->runVerifyWith(['--type' => 'not_a_document_type']);

        $this->assertSame(1, $exit, 'An invalid --type is an operator error, not a pass. Output: '.$output);
        $this->assertStringContainsString("Invalid type 'not_a_document_type'", $output);
        $this->assertStringNotContainsString('ALL CHAINS VALID', $output);
        $this->assertStringNotContainsString('Verification Summary', $output);
    }

    /**
     * R1, second half: a REAL DocumentType this verifier does not walk (the
     * enum holds quotes, orders and delivery notes too) must be rejected the
     * same way rather than silently verifying an empty set.
     */
    public function test_a_document_type_this_verifier_does_not_walk_is_rejected(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('unwalked-type-co');
        $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-UT-0001'));

        [$exit, $output] = $this->runVerifyWith(['--type' => DocumentType::Quote->value]);

        $this->assertSame(1, $exit, 'Output: '.$output);
        $this->assertStringNotContainsString('ALL CHAINS VALID', $output);
    }

    /**
     * M2. `forEachTenantFiltered()` returns INVALID (2) for an unknown
     * `--tenant`; this command's contract only ever used 0 and 1.
     */
    public function test_an_unknown_tenant_filter_exits_one_not_two(): void
    {
        [$tenant, $company, $partner] = $this->makeTenantCompanyPartner('exit-code-co');
        $this->postingService->post($this->createConfirmedInvoice($tenant, $company, $partner, 'INV-EC-0001'));

        [$exit] = $this->runVerifyWith(['--tenant' => '00000000-0000-0000-0000-000000000000']);

        $this->assertSame(1, $exit);
    }

    /**
     * R6. The flag was declared, documented as dangerous, warned against in two
     * QA plans — and read by nothing.
     */
    public function test_the_dead_fix_flag_is_gone(): void
    {
        $definition = $this->app->make(ConsoleKernel::class)
            ->all()['fiscal:verify-chains']
            ->getDefinition();

        $this->assertFalse(
            $definition->hasOption('fix'),
            'A server-side "fix" of a device-authored chain would itself be a fiscal-integrity defect.',
        );
    }

    /**
     * Post-seal mutation performed straight against the row, bypassing the
     * application layer. On PostgreSQL the immutability trigger blocks even a
     * raw UPDATE on a sealed row, so it is disabled for the injection.
     */
    private function tamperWithTotal(string $documentId): void
    {
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';
        if ($isPgsql) {
            DB::statement('ALTER TABLE documents DISABLE TRIGGER trg_document_immutability');
        }

        DB::table('documents')->where('id', $documentId)->update(['total' => '999999.99']);

        if ($isPgsql) {
            DB::statement('ALTER TABLE documents ENABLE TRIGGER trg_document_immutability');
        }
    }

    /**
     * @param  array<string, string>  $options
     * @return array{0: int, 1: string}
     */
    private function runVerifyWith(array $options): array
    {
        /** @var ConsoleKernel $kernel */
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('fiscal:verify-chains', $options);

        return [$exit, $kernel->output()];
    }

    /**
     * Run the fiscal:verify-chains artisan command scoped to a single company.
     *
     * Returns [exitCode, capturedOutput]. Using the console Kernel directly
     * keeps the return type a plain int (unlike $this->artisan() which returns
     * Illuminate\Testing\PendingCommand|int and trips PHPStan level 8).
     *
     * @return array{0: int, 1: string}
     */
    private function runVerify(string $companyId): array
    {
        /** @var ConsoleKernel $kernel */
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('fiscal:verify-chains', ['--company' => $companyId]);

        return [$exit, $kernel->output()];
    }

    /**
     * @return array{0: Tenant, 1: Company, 2: Partner}
     */
    private function makeTenantCompanyPartner(string $slug): array
    {
        $tenant = Tenant::factory()->create(['slug' => $slug]);
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        return [$tenant, $company, $partner];
    }

    private function createConfirmedInvoice(Tenant $tenant, Company $company, Partner $partner, string $number): Document
    {
        return Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);
    }

    private function serialize(Document $document): string
    {
        return $this->hashService->serializeForHashing([
            'document_number' => $document->document_number,
            'posted_at' => $document->document_date->toDateString(),
            'total' => $document->total ?? '0.00',
            'currency' => $document->currency,
        ]);
    }
}
