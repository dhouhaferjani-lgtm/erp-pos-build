<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\POS\Application\Services\ReceiptCreationService;
use App\Modules\POS\Application\Services\ReceiptFinalizationService;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\ReceiptCreated;
use App\Modules\POS\Domain\Events\ReceiptDrafted;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptPayment;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies the two-phase event lifecycle introduced by Phase A drift fix.
 *
 *   Phase 1: ReceiptCreationService::createReceipt()  → dispatches ReceiptDrafted
 *   Phase 2: ReceiptFinalizationService::finalize()   → dispatches ReceiptCreated
 *
 * Asserts Rule #8 compliance:
 *   - ReceiptCreated carries non-null fiscalHash and chainSequence (post-seal).
 *   - ReceiptDrafted fires from createReceipt() with the pending_seal signature.
 *   - ReceiptCreated does NOT fire from createReceipt(); it fires from finalize().
 */
final class ReceiptEventLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private Shift $shift;

    private Product $product;

    private PaymentMethod $cashMethod;

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

        $cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);

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
            'sale_price' => '10.00',
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

        $this->cashMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        /** @var CompanyContext $ctx */
        $ctx = app(CompanyContext::class);
        $ctx->setCompanyId($this->company->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReceiptCreationService fires ReceiptDrafted, NOT ReceiptCreated
    // ─────────────────────────────────────────────────────────────────────────

    public function test_receipt_drafted_fires_from_creation_service(): void
    {
        Event::fake([ReceiptDrafted::class, ReceiptCreated::class]);

        $this->createReceipt();

        Event::assertDispatched(ReceiptDrafted::class, function (ReceiptDrafted $event): bool {
            return $event->companyId === $this->company->id
                && $event->terminalId === $this->terminal->id
                && $event->total !== ''
                && $event->currency === 'EUR';
        });

        Event::assertNotDispatched(ReceiptCreated::class);
    }

    public function test_receipt_drafted_carries_receipt_id_and_cashier_id(): void
    {
        Event::fake([ReceiptDrafted::class]);

        $receipt = $this->createReceipt();

        Event::assertDispatched(ReceiptDrafted::class, function (ReceiptDrafted $event) use ($receipt): bool {
            return $event->receiptId === $receipt->id
                && $event->cashierId === $receipt->cashier_id;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ReceiptFinalizationService fires ReceiptCreated AFTER sealing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_receipt_created_fires_from_finalization_service_with_non_null_fiscal_data(): void
    {
        // Arrange: pending_seal receipt (skipping createReceipt to avoid event interference)
        $receipt = $this->makePendingSealReceiptWithPayment();

        Event::fake([ReceiptCreated::class, ReceiptDrafted::class]);

        /** @var ReceiptFinalizationService $service */
        $service = app(ReceiptFinalizationService::class);
        $service->finalize($receipt);

        Event::assertDispatched(ReceiptCreated::class, function (ReceiptCreated $event): bool {
            // Rule #8: fiscalHash and chainSequence must be non-null strings/ints
            return $event->companyId === $this->company->id
                && $event->terminalId === $this->terminal->id
                && $event->fiscalHash !== ''
                && $event->chainSequence > 0;
        });

        // ReceiptDrafted must NOT fire from finalize
        Event::assertNotDispatched(ReceiptDrafted::class);
    }

    public function test_receipt_created_fiscal_hash_matches_persisted_value(): void
    {
        $receipt = $this->makePendingSealReceiptWithPayment();

        $dispatchedHash = null;
        Event::listen(ReceiptCreated::class, function (ReceiptCreated $event) use (&$dispatchedHash): void {
            $dispatchedHash = $event->fiscalHash;
        });

        /** @var ReceiptFinalizationService $service */
        $service = app(ReceiptFinalizationService::class);
        $finalized = $service->finalize($receipt);

        $this->assertNotNull($dispatchedHash, 'ReceiptCreated event must have been dispatched');
        $this->assertSame($finalized->fiscal_hash, $dispatchedHash);
        $this->assertSame($finalized->chain_sequence, (int) Receipt::findOrFail($finalized->id)->chain_sequence);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createReceipt(): Receipt
    {
        /** @var ReceiptCreationService $service */
        $service = app(ReceiptCreationService::class);

        return $service->createReceipt(
            terminalId: $this->terminal->id,
            lines: [[
                'product_id' => $this->product->id,
                'quantity' => '1',
                'unit_price' => '10.00',
            ]],
        );
    }

    /**
     * Build a pending_seal receipt with a VAT detail and payment row so that
     * ReceiptFinalizationService::finalize() can seal it successfully.
     */
    private function makePendingSealReceiptWithPayment(): Receipt
    {
        $receipt = Receipt::factory()->pendingSeal()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $this->terminal->id,
            'total' => '12.000',
            'subtotal' => '10.000',
            'tax_amount' => '2.000',
            'currency' => 'EUR',
        ]);

        ReceiptVatDetail::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'tax_rate' => '20.00',
            'net_amount' => '10.000',
            'vat_amount' => '2.000',
            'gross_amount' => '12.000',
        ]);

        ReceiptPayment::create([
            'id' => Str::uuid()->toString(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashMethod->id,
            'payment_type' => 'Cash',
            'amount' => '12.000',
        ]);

        $receipt->setRelation('terminal', $this->terminal);

        return $receipt;
    }
}
