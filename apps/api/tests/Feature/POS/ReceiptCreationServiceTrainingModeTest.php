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
 * Verifies that ReceiptCreationService::createReceipt() persists training
 * receipts with chain_sequence=NULL (not 0).
 *
 * The pos_receipts_sequence Postgres CHECK constraint, added by migration
 * 2026_05_01_000001_prepare_pos_receipts_for_pending_seal, is
 *   chain_sequence IS NULL OR chain_sequence > 0
 * so chain_sequence=0 violates it in production. Training receipts are
 * excluded from the fiscal chain entirely; NULL is the correct sentinel,
 * matching the offline-sync path closed by PR #103
 * (ReceiptSyncService::syncSingleReceipt sets chain_sequence=null for
 * training payloads).
 *
 * Companion to ReceiptSyncServiceTrainingModeTest (offline path) and
 * ReceiptCreationServicePendingSealTest (online production path).
 */
final class ReceiptCreationServiceTrainingModeTest extends TestCase
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
            'is_training_mode' => true,
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

        StockLevel::create([
            'tenant_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'company_id' => $this->company->id,
            'quantity' => '100.00',
            'reserved_quantity' => '0.00',
        ]);

        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    public function test_training_receipt_persists_chain_sequence_null_not_zero(): void
    {
        $receipt = $this->createReceipt();

        // PG CHECK constraint pos_receipts_sequence rejects chain_sequence=0.
        // Training receipts are excluded from the fiscal chain; NULL is the
        // correct sentinel.
        $this->assertNull($receipt->chain_sequence);
    }

    public function test_training_receipt_is_marked_training_and_fiscalized(): void
    {
        $receipt = $this->createReceipt();

        $this->assertTrue($receipt->is_training);
        $this->assertSame(FiscalStatus::Fiscalized, $receipt->fiscal_status);
    }

    public function test_training_receipt_uses_placeholder_fiscal_hash(): void
    {
        $receipt = $this->createReceipt();

        // Training receipts get a placeholder hash derived from the receipt id
        // (not chained off any previous receipt). previous_hash stays null.
        $this->assertSame(
            hash('sha256', 'TRAINING-'.$receipt->id),
            $receipt->fiscal_hash,
        );
        $this->assertNull($receipt->previous_hash);
    }

    public function test_training_receipt_advances_terminal_sequence(): void
    {
        $sequenceBefore = $this->terminal->current_sequence;

        $this->createReceipt();

        $terminalFresh = Terminal::findOrFail($this->terminal->id);

        // Training receipts share `terminal.current_sequence` with production
        // because the receipt_number column has a UNIQUE index. Skipping the
        // advance would make a second training receipt collide on the same
        // TRN-{loc}-{term}-{year}-{seq} number.
        $this->assertSame($sequenceBefore + 1, $terminalFresh->current_sequence);
    }

    public function test_two_sequential_training_receipts_have_unique_numbers(): void
    {
        $first = $this->createReceipt();

        StockLevel::where('product_id', $this->product->id)
            ->where('location_id', $this->location->id)
            ->increment('quantity', 100);

        $second = $this->createReceipt();

        // Both rows must persist (no UNIQUE index violation on receipt_number)
        // and both must keep chain_sequence NULL — the fiscal chain stays
        // untouched by training receipts.
        $this->assertNotSame($first->receipt_number, $second->receipt_number);
        $this->assertStringStartsWith('TRN-', (string) $first->receipt_number);
        $this->assertStringStartsWith('TRN-', (string) $second->receipt_number);
        $this->assertNull($first->chain_sequence);
        $this->assertNull($second->chain_sequence);
    }

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
