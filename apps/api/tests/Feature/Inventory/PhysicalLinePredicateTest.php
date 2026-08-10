<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Document\Domain\Services\SalesOrderService;
use App\Modules\Inventory\Domain\PhysicalLinePredicate;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockReservation;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use Database\Factories\CompanyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\BuildsWave3ExitFixtures;

/**
 * DPA Wave 3 · sub-wave 3A · **T4 / D-19 — ONE physical predicate**.
 *
 * Before this task the codebase asked "is this line physical?" in two
 * incompatible ways:
 *
 *  - `$line->product === null || ($line->product->is_service ?? false)` at
 *    `DeliveryNoteService`, `ReturnNoteService` and `SalesOrderService`.
 *    `is_service` is a PHANTOM (§0.15): zero migrations, not in `$fillable`,
 *    `$attributes` or `casts()`, no accessor. All four reads are `?? false`, so
 *    the guard reduces to `product === null` and a **non-physical PRODUCT line
 *    moved stock**.
 *  - `$product->is_physical` — a real column — at `DocumentPostingService` and
 *    `InvoiceController`.
 *
 * These tests pin that all five sites now answer with the same predicate, and
 * disclose the behaviour change: a non-physical product line no longer moves
 * stock or reserves it.
 *
 * `Document.php:926` is deliberately NOT adopted (plan T4 risk note): it answers
 * a different question (a totals helper) and touching it widens the blast radius.
 */
final class PhysicalLinePredicateTest extends TestCase
{
    use BuildsWave3ExitFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWave3ExitFixtures();
    }

    // =================================================================
    // The predicate itself
    // =================================================================

    public function test_the_predicate_answers_the_three_populations(): void
    {
        $physical = $this->physicalProduct(costPrice: '50.000000');
        $nonPhysical = $this->nonPhysicalProduct();

        $document = $this->draftDocument(DocumentType::DeliveryNote, $physical, '1.0000', 'DN');
        /** @var DocumentLine $physicalLine */
        $physicalLine = $document->lines->first();

        $nonPhysicalLine = $this->addLine($document, productId: $nonPhysical->id, serviceId: null);
        $serviceLine = $this->addLine($document, productId: null, serviceId: $this->service()->id);

        self::assertTrue(PhysicalLinePredicate::forLine($this->reload($physicalLine)));
        self::assertFalse(PhysicalLinePredicate::forLine($this->reload($nonPhysicalLine)));
        self::assertFalse(PhysicalLinePredicate::forLine($this->reload($serviceLine)));

        self::assertNotNull(PhysicalLinePredicate::physicalProductFor($this->reload($physicalLine)));
        self::assertNull(PhysicalLinePredicate::physicalProductFor($this->reload($nonPhysicalLine)));
        self::assertNull(PhysicalLinePredicate::physicalProductFor($this->reload($serviceLine)));

        // …and the tenant-scoped form agrees, while a FOREIGN scope refuses the
        // physical line (the api.document.010 guard the predicate carries).
        self::assertTrue(
            PhysicalLinePredicate::forLine($this->reload($physicalLine), $this->tenantId, $this->companyId),
        );
        self::assertFalse(
            PhysicalLinePredicate::forLine($this->reload($physicalLine), $this->tenantId, Str::uuid()->toString()),
            'a foreign company scope must not resolve the product',
        );
    }

    public function test_the_query_scope_selects_exactly_the_lines_the_row_predicate_accepts(): void
    {
        $physical = $this->physicalProduct(costPrice: '50.000000');
        $nonPhysical = $this->nonPhysicalProduct();

        $document = $this->draftDocument(DocumentType::DeliveryNote, $physical, '1.0000', 'DN');
        $this->addLine($document, productId: $nonPhysical->id, serviceId: null);
        $this->addLine($document, productId: null, serviceId: $this->service()->id);

        $viaScope = PhysicalLinePredicate::physical(
            DocumentLine::query()->where('document_id', $document->id),
            $this->tenantId,
            $this->companyId,
        )->pluck('id')->all();

        /** @var Document $reloaded */
        $reloaded = $document->fresh(['lines.product']);
        $viaRow = $reloaded->lines
            ->filter(fn (DocumentLine $line): bool => PhysicalLinePredicate::forLine($line, $this->tenantId, $this->companyId))
            ->pluck('id')
            ->all();

        sort($viaScope);
        sort($viaRow);

        self::assertSame($viaRow, $viaScope, 'the scope and the row predicate must never disagree');
        self::assertCount(1, $viaScope);
    }

    /**
     * Fix round 1 · inv gate F-5 — the QUERY form must be scoped, and the scope is
     * MANDATORY.
     *
     * `Product` carries no global tenant/company scope and the tenant database is
     * per-TENANT, not per-COMPANY, so an unscoped `whereHas('product')` happily
     * matches a product belonging to a sibling company. The row form has taken an
     * optional `(tenantId, companyId)` pair since T4 precisely to stop a forged
     * `line.product_id` resolving across that boundary (api.document.010); the
     * query form had no equivalent. D-19 hands this method to 3C's detector, which
     * would have inherited the hole.
     *
     * The pair is REQUIRED rather than optional: there are zero production callers
     * today, so making it mandatory costs nothing and cannot be forgotten later.
     */
    public function test_the_query_scope_refuses_a_foreign_companys_product(): void
    {
        $physical = $this->physicalProduct(costPrice: '50.000000');
        $document = $this->draftDocument(DocumentType::DeliveryNote, $physical, '1.0000', 'DN');

        // A physical product owned by ANOTHER company in the same tenant database,
        // referenced by a line on THIS company's document.
        /** @var Company $foreignCompany */
        $foreignCompany = CompanyFactory::new()->create(['tenant_id' => $this->tenantId]);
        $foreignProduct = Product::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $foreignCompany->id,
            'sku' => 'FOREIGN-'.Str::upper(Str::random(6)),
            'name' => 'Foreign Widget',
            'type' => ProductType::Part,
            'unit' => 'piece',
            'cost_price' => '50.000000',
            'sale_price' => '100.00',
            'tax_rate' => 0,
            'is_active' => true,
            'is_physical' => true,
        ]);
        $foreignLine = $this->addLine($document, productId: $foreignProduct->id, serviceId: null);

        $viaScope = PhysicalLinePredicate::physical(
            DocumentLine::query()->where('document_id', $document->id),
            $this->tenantId,
            $this->companyId,
        )->pluck('id')->all();

        self::assertNotContains(
            $foreignLine->id,
            $viaScope,
            'the query form resolved a product belonging to a sibling company',
        );
        self::assertCount(1, $viaScope);

        // …and the row form, given the same scope, agrees.
        self::assertFalse(
            PhysicalLinePredicate::forLine($this->reload($foreignLine), $this->tenantId, $this->companyId),
        );
    }

    // =================================================================
    // DISCLOSED BEHAVIOUR CHANGE — the three `is_service` sites
    // =================================================================

    public function test_a_non_physical_product_line_no_longer_issues_stock_on_a_delivery_note(): void
    {
        // BEFORE T4: `is_service` is phantom, so this line reduced to
        // "product !== null" and DID issue stock — a non-physical product was
        // destocked and (post-3C) would have booked COGS.
        $nonPhysical = $this->nonPhysicalProduct();
        $stock = $this->seedStock($nonPhysical->id, '10.0000');

        $deliveryNote = $this->confirmedDeliveryNoteFor($nonPhysical, quantity: '2.0000');

        self::assertSame(
            0,
            StockMovement::query()->where('reference_id', $deliveryNote->id)->count(),
            'a non-physical product must not move stock',
        );
        $stock->refresh();
        self::assertSame('10.0000', $this->numericString($stock->quantity));
        self::assertSame(DocumentStatus::Confirmed, $deliveryNote->status);
    }

    public function test_a_non_physical_product_line_no_longer_receives_stock_on_a_return_note(): void
    {
        $nonPhysical = $this->nonPhysicalProduct();
        $stock = $this->seedStock($nonPhysical->id, '10.0000');

        $returnNote = $this->confirmedReturnNoteFor($nonPhysical, quantity: '2.0000');

        self::assertSame(
            0,
            StockMovement::query()->where('reference_id', $returnNote->id)->count(),
        );
        $stock->refresh();
        self::assertSame('10.0000', $this->numericString($stock->quantity));
    }

    public function test_a_non_physical_product_line_no_longer_reserves_stock_on_a_sales_order(): void
    {
        $nonPhysical = $this->nonPhysicalProduct();
        $this->seedStock($nonPhysical->id, '10.0000');

        $salesOrder = $this->draftDocument(DocumentType::SalesOrder, $nonPhysical, '2.0000', 'SO');
        $this->app->make(SalesOrderService::class)->confirm($salesOrder);

        self::assertSame(
            0,
            StockReservation::query()->where('source_id', $salesOrder->id)->count(),
            'a non-physical product must not reserve stock',
        );
    }

    /**
     * Fix round 1 · fiscal gate P3-6(a) — the disclosed behaviour change in
     * `SalesOrderService`, driven rather than asserted.
     *
     * Pre-T4 the loop skipped `product_id === null` lines only AFTER the
     * no-location throw (`SalesOrderService` at `93824dade^`, `:130-137`), so
     * confirming a sales order that carried a pure service line and no location
     * anywhere raised *"Cannot reserve stock: no location specified"* — a refusal
     * for a line that was never going to reserve anything. The predicate subsumes
     * that skip and hoists it above the throw. Report §6 item 3 disclosed it; this
     * test drives it.
     */
    public function test_a_product_less_line_with_no_location_is_skipped_rather_than_refused(): void
    {
        $salesOrder = Document::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'partner_id' => $this->customer->id,
            'location_id' => null, // …and no document-level fallback either.
            'document_number' => 'SO-W3A-NOLOC-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => '80.00',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $salesOrder->id,
            'line_number' => 1,
            'product_id' => null,
            'service_id' => $this->service()->id,
            'location_id' => null,
            'description' => 'Pure service line',
            'quantity' => '1.0000',
            'unit_price' => '80.00',
            'tax_rate' => 0,
            'line_total' => '80.00',
        ]);

        /** @var Document $fresh */
        $fresh = $salesOrder->fresh(['lines']);

        $confirmed = $this->app->make(SalesOrderService::class)->confirm($fresh);

        self::assertSame(DocumentStatus::Confirmed, $confirmed->status);
        self::assertSame(
            0,
            StockReservation::query()->where('source_id', $salesOrder->id)->count(),
            'a product-less line reserves nothing — and must not refuse the confirm either',
        );
    }

    // =================================================================
    // Unchanged populations — the predicate must not over-reach
    // =================================================================

    public function test_a_physical_line_still_moves_and_reserves_stock(): void
    {
        $physical = $this->physicalProduct(costPrice: '50.000000');
        $stock = $this->seedStock($physical->id, '10.0000');

        $salesOrder = $this->draftDocument(DocumentType::SalesOrder, $physical, '2.0000', 'SO');
        $this->app->make(SalesOrderService::class)->confirm($salesOrder);
        self::assertSame(1, StockReservation::query()->where('source_id', $salesOrder->id)->count());

        $deliveryNote = $this->confirmedDeliveryNoteFor($physical, quantity: '2.0000');
        self::assertSame(1, StockMovement::query()->where('reference_id', $deliveryNote->id)->count());

        $stock->refresh();
        self::assertSame('8.0000', $this->numericString($stock->quantity));
    }

    public function test_a_service_only_invoice_still_posts_without_a_delivery_note(): void
    {
        // DocumentPostingService::validateDeliveryCompliance already read the
        // REAL column, so adopting the predicate there is a refactor: a
        // non-physical invoice must still bypass the delivery gate.
        $nonPhysical = $this->nonPhysicalProduct();

        $salesOrder = $this->draftDocument(DocumentType::SalesOrder, $nonPhysical, '1.0000', 'SO');
        $salesOrder->update(['status' => DocumentStatus::Confirmed]);

        $invoice = $this->draftDocument(DocumentType::Invoice, $nonPhysical, '1.0000', 'INV');
        $invoice->update([
            'status' => DocumentStatus::Confirmed,
            'source_document_id' => $salesOrder->id,
        ]);

        /** @var Document $confirmed */
        $confirmed = $invoice->fresh(['lines']);
        $posted = $this->app->make(DocumentPostingService::class)->post($confirmed);

        self::assertSame(DocumentStatus::Posted, $posted->status);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function reload(DocumentLine $line): DocumentLine
    {
        /** @var DocumentLine $fresh */
        $fresh = $line->fresh();

        return $fresh;
    }

    private function service(): Service
    {
        return Service::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ]);
    }

    private function addLine(Document $document, ?string $productId, ?string $serviceId): DocumentLine
    {
        return DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'line_number' => (int) DocumentLine::query()->where('document_id', $document->id)->count() + 1,
            'product_id' => $productId,
            'service_id' => $serviceId,
            'location_id' => $this->locationId,
            'description' => $productId !== null ? 'Non-physical product line' : 'Pure service line',
            'quantity' => '1.0000',
            'unit_price' => '10.00',
            'tax_rate' => 0,
            'line_total' => '10.00',
        ]);
    }
}
