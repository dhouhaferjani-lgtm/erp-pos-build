<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that ReceiptCreationService::createReceipt() produces a pending_seal
 * draft receipt without advancing the terminal's fiscal hash chain.
 *
 * The fiscal hash and terminal last_hash advance are deferred to
 * ReceiptFinalizationService::finalize() (Task 5), called later in the flow
 * by ReceiptPaymentService once the receipt is fully tendered.
 */
final class ReceiptCreationServicePendingSealTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'EUR',
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'fiscal_schema_version' => 2,
            'last_hash' => null,
            'current_sequence' => 1,
        ]);

        $this->shift = Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'status' => ShiftStatus::Open,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0.0000',
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sale_price' => '12.50',
            'tax_rate' => '20.00',
        ]);

        // Stock required for sale line
        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '100.00',
            'reserved_quantity' => '0.00',
        ]);

        // Bind company context so the service can resolve it
        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Core draft-first assertions
    // ─────────────────────────────────────────────────────────────────────────

    public function test_create_writes_pending_seal_receipt_without_fiscal_hash(): void
    {
        $receipt = $this->createReceipt();

        $this->assertSame(FiscalStatus::PendingSeal, $receipt->fiscal_status);
        $this->assertNull($receipt->fiscal_hash);
        $this->assertNull($receipt->chain_sequence);
        $this->assertNull($receipt->previous_hash);
    }

    public function test_create_does_not_advance_terminal_last_hash(): void
    {
        $lastHashBefore = $this->terminal->last_hash; // null

        $this->createReceipt();

        $terminalFresh = Terminal::findOrFail($this->terminal->id);

        // last_hash must NOT change — that is deferred to finalize()
        $this->assertSame($lastHashBefore, $terminalFresh->last_hash);
    }

    public function test_create_advances_terminal_current_sequence_for_receipt_number_uniqueness(): void
    {
        $sequenceBefore = $this->terminal->current_sequence; // 1

        $this->createReceipt();

        $terminalFresh = Terminal::findOrFail($this->terminal->id);

        // current_sequence increments so the next receipt gets a distinct number
        $this->assertSame($sequenceBefore + 1, $terminalFresh->current_sequence);
    }

    public function test_create_persists_lines_and_vat_breakdown_correctly(): void
    {
        $receipt = $this->createReceipt();

        // Lines must exist even though fiscal_hash is null
        $this->assertCount(1, $receipt->lines);

        $line = $receipt->lines->first();
        $this->assertNotNull($line);
        $this->assertSame($this->product->id, $line->product_id);

        // VAT details must be persisted
        $this->assertNotEmpty($receipt->vatDetails);

        // vat_breakdown_hash and payment_methods_hash must be set
        // (they are stored at create time and used as inputs to finalize())
        $this->assertNotNull($receipt->vat_breakdown_hash);
        $this->assertNotNull($receipt->payment_methods_hash);
    }

    public function test_two_sequential_creates_produce_unique_receipt_numbers(): void
    {
        $first = $this->createReceipt();

        // Add more stock for the second receipt
        StockLevel::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->increment('quantity', 100);

        $second = $this->createReceipt();

        $this->assertNotSame($first->receipt_number, $second->receipt_number);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Call createReceipt() with a minimal single-product line.
     */
    private function createReceipt(): Receipt
    {
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        return $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_price' => '12.50',
                ],
            ],
        );
    }
}
