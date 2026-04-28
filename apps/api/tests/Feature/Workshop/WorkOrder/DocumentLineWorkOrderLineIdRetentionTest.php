<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Enums\Vertical;
use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Services\Conversion\Converters\QuoteToSalesOrderConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToInvoiceConverter;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * H2 — DocumentLine.work_order_line_id must round-trip end-to-end.
 *
 * Two layers were silently dropping the column:
 *   1. DocumentLine::$fillable — mass-assignment in DocumentGenerationAdapter
 *      (WO → Invoice direct path) was guarded out under default mass-assignment
 *      protection, so the column never persisted on WO-generated invoices.
 *   2. CopiesDocumentData::copyLine() whitelist — the conversion path
 *      (Quote → SalesOrder, SalesOrder → Invoice, etc.) omits the column,
 *      so any WO-originated line that flowed through the converter chain
 *      lost its lineage.
 *
 * Together these broke WO ↔ DocumentLine traceability for any flow that
 * crossed even a single conversion boundary.
 *
 * This regression suite locks down both layers:
 *   - WO Quote → adapter writes DocumentLine with work_order_line_id set
 *   - Quote → SalesOrder converter retains the FK
 *   - SalesOrder → Invoice converter retains the FK
 *   - WO direct-Invoice (no Quote stage) also retains the FK
 */
final class DocumentLineWorkOrderLineIdRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);

        Country::firstOrCreate(
            ['code' => 'FR'],
            ['name' => 'France', 'currency_code' => 'EUR', 'currency_symbol' => '€'],
        );

        CountryPaymentSettings::firstOrCreate(
            ['country_code' => 'FR'],
            [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => '0.0050',
                'max_payment_tolerance_amount' => '0.500',
            ],
        );

        Event::forget(InvoicePosted::class);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'WO Customer',
            'type' => PartnerType::Customer,
        ]);
    }

    public function test_wo_quote_dl_persists_work_order_line_id(): void
    {
        [$wo, $woLine] = $this->makeWoWithLine(state: 'diagnosed');

        $service = $this->app->make(WorkOrderTransitionService::class);
        $updated = $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Quoted,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            context: null,
        ));

        $quoteId = $updated->quote_document_id;
        $this->assertNotNull($quoteId);

        $line = DocumentLine::query()->where('document_id', $quoteId)->firstOrFail();

        $this->assertSame(
            $woLine->id,
            $line->getAttribute('work_order_line_id'),
            'WO Quote DocumentLine must persist work_order_line_id (DocumentLine::$fillable gap).',
        );
    }

    public function test_wo_invoice_dl_persists_work_order_line_id(): void
    {
        [$wo, $woLine] = $this->makeWoWithLine(state: 'completed');

        $service = $this->app->make(WorkOrderTransitionService::class);
        $updated = $service->transition(new TransitionStatusCommand(
            work_order_id: $wo->id,
            to_status: WorkOrderStatus::Invoiced,
            reason_code: null,
            triggered_by_user_id: null,
            occurred_at: new \DateTimeImmutable,
            context: null,
        ));

        $invoiceId = $updated->invoice_document_id;
        $this->assertNotNull($invoiceId);

        $line = DocumentLine::query()->where('document_id', $invoiceId)->firstOrFail();

        $this->assertSame(
            $woLine->id,
            $line->getAttribute('work_order_line_id'),
            'WO Invoice DocumentLine must persist work_order_line_id (DocumentLine::$fillable gap).',
        );
    }

    public function test_quote_to_sales_order_conversion_carries_work_order_line_id(): void
    {
        // Seed a Quote document directly with a WO line id, simulating a
        // WO-originated quote that's now being converted via the document
        // converter chain (e.g. quote → sales-order before invoicing).
        $woLineId = (string) Str::uuid();
        $quote = $this->seedQuoteDocumentWithWoLine($woLineId);

        $converter = $this->app->make(QuoteToSalesOrderConverter::class);
        $order = $converter->convert($quote);

        $orderLine = $order->lines()->first();
        $this->assertNotNull($orderLine);
        $this->assertSame(
            $woLineId,
            $orderLine->getAttribute('work_order_line_id'),
            'CopiesDocumentData::copyLine() must whitelist work_order_line_id.',
        );
    }

    public function test_sales_order_to_invoice_conversion_carries_work_order_line_id(): void
    {
        $woLineId = (string) Str::uuid();
        $order = $this->seedConfirmedOrderDocumentWithWoLine($woLineId);

        $converter = $this->app->make(SalesOrderToInvoiceConverter::class);
        $invoice = $converter->convert($order);

        $invoiceLine = $invoice->lines()->first();
        $this->assertNotNull($invoiceLine);
        $this->assertSame(
            $woLineId,
            $invoiceLine->getAttribute('work_order_line_id'),
            'CopiesDocumentData::copyLine() must whitelist work_order_line_id.',
        );
    }

    /**
     * @param  'diagnosed'|'completed'  $state
     * @return array{0: WorkOrder, 1: WorkOrderLine}
     */
    private function makeWoWithLine(string $state): array
    {
        $factory = $state === 'diagnosed'
            ? WorkOrder::factory()->diagnosed()
            : WorkOrder::factory()->completed();

        $wo = $factory->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'currency' => 'EUR',
        ]);

        $woLine = WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
            'display_name' => 'Brake pads',
            'description' => null,
            'quantity' => '1.000',
            'unit_price' => '50.000',
            'tax_rate' => '20.000',
            'discount_percent' => '0.00',
            'line_total_excl_tax' => '50.000',
            'line_total_tax' => '10.000',
            'line_total_incl_tax' => '60.000',
        ]);

        return [$wo, $woLine];
    }

    private function seedQuoteDocumentWithWoLine(string $woLineId): Document
    {
        /** @phpstan-ignore-next-line argument.type */
        $quote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-WO-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '50.000',
            'tax_amount' => '10.000',
            'total' => '60.000',
        ]);

        DocumentLine::create([
            'document_id' => $quote->id,
            'line_number' => 1,
            'description' => 'Brake pads',
            'quantity' => '1.000',
            'unit_price' => '50.000',
            'tax_rate' => '20.000',
            'line_total' => '50.000',
            'work_order_line_id' => $woLineId,
        ]);

        $fresh = $quote->fresh(['lines']);
        $this->assertNotNull($fresh);

        // Pre-condition: the seed itself must persist the column. If this
        // assertion fails first, the DocumentLine::$fillable fix is still
        // needed before the converter test is meaningful.
        $line = $fresh->lines->first();
        $this->assertNotNull($line);
        $this->assertSame(
            $woLineId,
            $line->getAttribute('work_order_line_id'),
            'Seed pre-condition: DocumentLine must persist work_order_line_id (fillable gap).',
        );

        return $fresh;
    }

    private function seedConfirmedOrderDocumentWithWoLine(string $woLineId): Document
    {
        /** @phpstan-ignore-next-line argument.type */
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-WO-0001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '50.000',
            'tax_amount' => '10.000',
            'total' => '60.000',
        ]);

        DocumentLine::create([
            'document_id' => $order->id,
            'line_number' => 1,
            'description' => 'Brake pads',
            'quantity' => '1.000',
            'unit_price' => '50.000',
            'tax_rate' => '20.000',
            'line_total' => '50.000',
            'work_order_line_id' => $woLineId,
        ]);

        $fresh = $order->fresh(['lines']);
        $this->assertNotNull($fresh);

        return $fresh;
    }
}
