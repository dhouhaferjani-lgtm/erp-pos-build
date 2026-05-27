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
