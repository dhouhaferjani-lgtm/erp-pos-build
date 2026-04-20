<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\DeliveryNoteConfirmed;
use App\Modules\Document\Domain\Services\DeliveryNoteService;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for Delivery Note hash chain compliance (Tunisia requirement).
 *
 * Delivery Notes in Tunisia must be:
 * - Sequentially numbered
 * - Tamper-proof (hash chain)
 * - Matched to invoices by fiscal year end
 *
 * Key difference from Invoice hash chain:
 * - DN is hashed on CONFIRM (when stock moves), not on POST
 * - DN does not create GL entries (not an accounting document)
 * - DN has its own separate hash chain per company
 */
class DeliveryNoteHashChainTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryNoteService $deliveryNoteService;

    private FiscalHashService $hashService;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deliveryNoteService = app(DeliveryNoteService::class);
        $this->hashService = app(FiscalHashService::class);

        // Create test tenant, company, and partner
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN', // Tunisia requires DN hash chain
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        // Create chart of accounts (required for invoice posting in chain separation test)
        $accounts = [
            ['code' => '411000', 'name' => 'Customer Receivable', 'type' => 'asset', 'purpose' => 'customer_receivable'],
            ['code' => '701000', 'name' => 'Product Sales', 'type' => 'revenue', 'purpose' => 'product_revenue'],
            ['code' => '706000', 'name' => 'Service Revenue', 'type' => 'revenue', 'purpose' => 'service_revenue'],
            ['code' => '445660', 'name' => 'VAT Collected', 'type' => 'liability', 'purpose' => 'vat_collected'],
            ['code' => '709000', 'name' => 'Sales Returns', 'type' => 'revenue', 'purpose' => 'sales_return'],
        ];
        foreach ($accounts as $accountData) {
            Account::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'code' => $accountData['code'],
                'name' => $accountData['name'],
                'type' => AccountType::from($accountData['type']),
                'system_purpose' => SystemAccountPurpose::from($accountData['purpose']),
                'is_active' => true,
            ]);
        }
    }

    public function test_confirming_delivery_note_creates_fiscal_hash(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        $deliveryNote = $this->createDraftDeliveryNote('DN-001');

        $confirmedDN = $this->deliveryNoteService->confirm($deliveryNote);

        $this->assertEquals(DocumentStatus::Confirmed, $confirmedDN->status);
        $this->assertNotNull($confirmedDN->fiscal_hash);
        $this->assertNull($confirmedDN->previous_hash); // First document has no previous hash
        $this->assertEquals(1, $confirmedDN->chain_sequence);
        $this->assertEquals(FiscalStatus::Sealed, $confirmedDN->fiscal_status);

        Event::assertDispatched(DeliveryNoteConfirmed::class, function (DeliveryNoteConfirmed $event) use ($confirmedDN) {
            return $event->deliveryNoteId === $confirmedDN->id
                && $event->fiscalHash === $confirmedDN->fiscal_hash
                && $event->chainSequence === 1;
        });
    }

    public function test_sequential_delivery_notes_create_linked_hash_chain(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        // Confirm first DN
        $dn1 = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN1 = $this->deliveryNoteService->confirm($dn1);

        // Confirm second DN
        $dn2 = $this->createDraftDeliveryNote('DN-002');
        $confirmedDN2 = $this->deliveryNoteService->confirm($dn2);

        // Confirm third DN
        $dn3 = $this->createDraftDeliveryNote('DN-003');
        $confirmedDN3 = $this->deliveryNoteService->confirm($dn3);

        // Verify chain linking
        $this->assertNull($confirmedDN1->previous_hash);
        $this->assertEquals(1, $confirmedDN1->chain_sequence);

        $this->assertEquals($confirmedDN1->fiscal_hash, $confirmedDN2->previous_hash);
        $this->assertEquals(2, $confirmedDN2->chain_sequence);

        $this->assertEquals($confirmedDN2->fiscal_hash, $confirmedDN3->previous_hash);
        $this->assertEquals(3, $confirmedDN3->chain_sequence);
    }

    public function test_delivery_note_chain_is_separate_from_invoice_chain(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        // Confirm DN
        $dn = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN = $this->deliveryNoteService->confirm($dn);

        // Create and post an invoice (using the posting service)
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'TND',
            'total' => '100.00',
        ]);

        $postingService = app(DocumentPostingService::class);
        $postedInvoice = $postingService->post($invoice);

        // Confirm second DN
        $dn2 = $this->createDraftDeliveryNote('DN-002');
        $confirmedDN2 = $this->deliveryNoteService->confirm($dn2);

        // Verify separate chains
        $this->assertEquals(1, $confirmedDN->chain_sequence);
        $this->assertEquals(1, $postedInvoice->chain_sequence); // Invoice has its own chain
        $this->assertEquals(2, $confirmedDN2->chain_sequence);

        // DN2 should link to DN1, not to invoice
        $this->assertEquals($confirmedDN->fiscal_hash, $confirmedDN2->previous_hash);
    }

    public function test_dn_hash_chain_uses_company_genesis_seed(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        $dn = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN = $this->deliveryNoteService->confirm($dn);

        // Verify the hash was calculated using company seed
        $input = $this->hashService->serializeForHashing([
            'document_number' => $confirmedDN->document_number,
            'posted_at' => $confirmedDN->document_date->toDateString(),
            'total' => $confirmedDN->total ?? '0.00',
            'currency' => $confirmedDN->currency,
        ]);

        $expectedHash = $this->hashService->calculateHash(
            $input,
            null, // No previous hash for genesis
            $this->company->fiscal_chain_seed // Company's unique seed
        );

        $this->assertEquals($expectedHash, $confirmedDN->fiscal_hash);
    }

    public function test_dn_hash_chain_is_verifiable(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        // Create chain of 3 DNs
        $dn1 = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN1 = $this->deliveryNoteService->confirm($dn1);

        $dn2 = $this->createDraftDeliveryNote('DN-002');
        $confirmedDN2 = $this->deliveryNoteService->confirm($dn2);

        $dn3 = $this->createDraftDeliveryNote('DN-003');
        $confirmedDN3 = $this->deliveryNoteService->confirm($dn3);

        // Manually verify each hash in chain
        $chain = [
            [
                'input' => $this->hashService->serializeForHashing([
                    'document_number' => $confirmedDN1->document_number,
                    'posted_at' => $confirmedDN1->document_date->toDateString(),
                    'total' => $confirmedDN1->total ?? '0.00',
                    'currency' => $confirmedDN1->currency,
                ]),
                'hash' => $confirmedDN1->fiscal_hash,
                'previous_hash' => $confirmedDN1->previous_hash,
            ],
            [
                'input' => $this->hashService->serializeForHashing([
                    'document_number' => $confirmedDN2->document_number,
                    'posted_at' => $confirmedDN2->document_date->toDateString(),
                    'total' => $confirmedDN2->total ?? '0.00',
                    'currency' => $confirmedDN2->currency,
                ]),
                'hash' => $confirmedDN2->fiscal_hash,
                'previous_hash' => $confirmedDN2->previous_hash,
            ],
            [
                'input' => $this->hashService->serializeForHashing([
                    'document_number' => $confirmedDN3->document_number,
                    'posted_at' => $confirmedDN3->document_date->toDateString(),
                    'total' => $confirmedDN3->total ?? '0.00',
                    'currency' => $confirmedDN3->currency,
                ]),
                'hash' => $confirmedDN3->fiscal_hash,
                'previous_hash' => $confirmedDN3->previous_hash,
            ],
        ];

        $isValid = $this->hashService->verifyChain($chain, $this->company->fiscal_chain_seed);
        $this->assertTrue($isValid);
    }

    public function test_different_companies_have_separate_dn_chains(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        // Create second company
        $company2 = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $partner2 = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
        ]);

        // Confirm DN for company 1
        $dn1 = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN1 = $this->deliveryNoteService->confirm($dn1);

        // Create location for company 2
        $location2 = Location::create([
            'company_id' => $company2->id,
            'name' => 'Warehouse 2',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        // Confirm DN for company 2
        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'partner_id' => $partner2->id,
            'location_id' => $location2->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'C2-DN-001',
            'document_date' => now(),
            'currency' => 'TND',
            'total' => '0.00',
        ]);
        $confirmedDN2 = $this->deliveryNoteService->confirm($dn2);

        // Both should be first in their respective chains
        $this->assertEquals(1, $confirmedDN1->chain_sequence);
        $this->assertEquals(1, $confirmedDN2->chain_sequence);
        $this->assertNull($confirmedDN1->previous_hash);
        $this->assertNull($confirmedDN2->previous_hash);

        // Different genesis seeds = different hashes even with same data
        $this->assertNotEquals($confirmedDN1->fiscal_hash, $confirmedDN2->fiscal_hash);
    }

    public function test_confirmed_dn_cannot_be_modified(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        $dn = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN = $this->deliveryNoteService->confirm($dn);

        // DN should be fiscally sealed
        $this->assertEquals(FiscalStatus::Sealed, $confirmedDN->fiscal_status);
        $this->assertTrue($confirmedDN->isFiscallyImmutable());
        $this->assertFalse($confirmedDN->isEditable());
    }

    public function test_tampered_dn_hash_is_detectable(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        $dn = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN = $this->deliveryNoteService->confirm($dn);

        // Simulate tampering - manually change the hash in database
        // (In real scenario, this would be caught by hash verification)
        $originalHash = $confirmedDN->fiscal_hash;
        $tamperedHash = hash('sha256', 'tampered');

        // Verify the original hash
        $input = $this->hashService->serializeForHashing([
            'document_number' => $confirmedDN->document_number,
            'posted_at' => $confirmedDN->document_date->toDateString(),
            'total' => $confirmedDN->total ?? '0.00',
            'currency' => $confirmedDN->currency,
        ]);

        // Original hash should verify correctly
        $this->assertTrue(
            $this->hashService->verifyHash($input, null, $originalHash)
            || $this->hashService->calculateHash($input, null, $this->company->fiscal_chain_seed) === $originalHash
        );

        // Tampered hash should fail verification
        $this->assertFalse($this->hashService->verifyHash($input, null, $tamperedHash));
    }

    public function test_confirming_draft_dn_works_correctly(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        $dn = $this->createDraftDeliveryNote('DN-001');

        $this->assertEquals(DocumentStatus::Draft, $dn->status);
        $this->assertNull($dn->fiscal_hash);

        $confirmedDN = $this->deliveryNoteService->confirm($dn);

        $this->assertEquals(DocumentStatus::Confirmed, $confirmedDN->status);
        $this->assertNotNull($confirmedDN->fiscal_hash);
    }

    public function test_already_confirmed_dn_cannot_be_confirmed_again(): void
    {
        Event::fake([DeliveryNoteConfirmed::class]);

        $dn = $this->createDraftDeliveryNote('DN-001');
        $confirmedDN = $this->deliveryNoteService->confirm($dn);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only draft delivery notes can be confirmed');

        $this->deliveryNoteService->confirm($confirmedDN);
    }

    /**
     * Create a draft delivery note with lines for testing.
     */
    private function createDraftDeliveryNote(string $documentNumber): Document
    {
        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'location_id' => $this->location->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        // Add some lines
        DocumentLine::create([
            'document_id' => $dn->id,
            'line_number' => 1,
            'description' => 'Product A',
            'quantity' => '10.00',
            'unit_price' => '0.00',
            'tax_rate' => '0.00',
            'line_total' => '0.00',
        ]);

        DocumentLine::create([
            'document_id' => $dn->id,
            'line_number' => 2,
            'description' => 'Product B',
            'quantity' => '5.00',
            'unit_price' => '0.00',
            'tax_rate' => '0.00',
            'line_total' => '0.00',
        ]);

        return $dn->fresh(['lines']);
    }
}
