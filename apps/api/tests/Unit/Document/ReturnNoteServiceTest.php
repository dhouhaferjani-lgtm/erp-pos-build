<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Events\ReturnNoteConfirmed;
use App\Modules\Document\Domain\Services\ReturnNoteService;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReturnNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReturnNoteService $service;
    private Tenant $tenant;
    private Company $company;
    private Location $location;
    private Partner $partner;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create dependencies
        $wacService = $this->app->make(WeightedAverageCostService::class);
        $hashService = $this->app->make(FiscalHashService::class);
        $this->service = new ReturnNoteService($wacService, $hashService);

        // Create test data
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        // Create location manually (no factory exists)
        $this->location = Location::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Test Location',
            'type' => \App\Modules\Company\Domain\Enums\LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => false,
        ]);

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '50.00',
        ]);

        // Authenticate a user for confirmed_by tracking
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->actingAs($user);
    }

    public function test_confirms_return_note(): void
    {
        // Arrange
        $returnNote = $this->createDraftReturnNote();
        $this->assertEquals(DocumentStatus::Draft, $returnNote->status);

        // Act
        $confirmedRN = $this->service->confirm($returnNote);

        // Assert
        $this->assertEquals(DocumentStatus::Confirmed, $confirmedRN->status);
        $this->assertEquals(FiscalStatus::Sealed, $confirmedRN->fiscal_status);
        $this->assertNotNull($confirmedRN->fiscal_hash);
        $this->assertNotNull($confirmedRN->chain_sequence);
        $this->assertEquals(1, $confirmedRN->chain_sequence); // First return note
    }

    public function test_receives_stock_back(): void
    {
        // Arrange
        $returnNote = $this->createDraftReturnNote();

        // Act
        $confirmedRN = $this->service->confirm($returnNote);

        // Assert - stock movement should be created
        $movement = StockMovement::where('reference_id', $confirmedRN->id)
            ->where('reference_type', 'Document')
            ->first();

        $this->assertNotNull($movement);
        $this->assertEquals(MovementType::Receipt, $movement->movement_type);
        $this->assertEquals($this->product->id, $movement->product_id);
        $this->assertEquals('10.00', $movement->quantity); // Returned quantity
    }

    public function test_uses_fiscal_hash_chain(): void
    {
        // Arrange
        $firstRN = $this->createDraftReturnNote();
        $secondRN = $this->createDraftReturnNote();

        // Act
        $confirmedFirst = $this->service->confirm($firstRN);
        $confirmedSecond = $this->service->confirm($secondRN);

        // Assert - second RN should reference first RN's hash
        $this->assertEquals(1, $confirmedFirst->chain_sequence);
        $this->assertEquals(2, $confirmedSecond->chain_sequence);
        $this->assertEquals($confirmedFirst->fiscal_hash, $confirmedSecond->previous_hash);
    }

    public function test_dispatches_return_note_confirmed_event(): void
    {
        // Arrange
        Event::fake([ReturnNoteConfirmed::class]);
        $returnNote = $this->createDraftReturnNote();

        // Act
        $confirmedRN = $this->service->confirm($returnNote);

        // Assert
        Event::assertDispatched(ReturnNoteConfirmed::class, function (ReturnNoteConfirmed $event) use ($confirmedRN) {
            return $event->returnNoteId === $confirmedRN->id
                && $event->companyId === $confirmedRN->company_id
                && $event->documentNumber === $confirmedRN->document_number
                && $event->fiscalHash === $confirmedRN->fiscal_hash;
        });
    }

    public function test_throws_exception_if_not_draft(): void
    {
        // Arrange
        $returnNote = $this->createDraftReturnNote();
        $returnNote->update(['status' => DocumentStatus::Confirmed]);

        // Act & Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only draft return notes can be confirmed');

        $this->service->confirm($returnNote);
    }

    public function test_throws_exception_if_not_return_note(): void
    {
        // Arrange
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'USD',
            'total' => '100.00',
        ]);

        // Act & Assert
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only return notes can be confirmed with this service');

        $this->service->confirm($invoice);
    }

    private function createDraftReturnNote(): Document
    {
        $rn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::ReturnNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'RN-' . now()->format('Y') . '-' . str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT),
            'document_date' => now(),
            'currency' => 'USD',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
        ]);

        // Add line
        DocumentLine::create([
            'document_id' => $rn->id,
            'product_id' => $this->product->id,
            'line_number' => 1,
            'description' => 'Returned Product',
            'quantity' => '10.00',
            'unit_price' => '50.00',
            'tax_rate' => '0.00',
            'line_total' => '500.00',
        ]);

        return $rn->fresh(['lines']);
    }
}
