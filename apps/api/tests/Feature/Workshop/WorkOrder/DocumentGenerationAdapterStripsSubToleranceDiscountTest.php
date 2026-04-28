<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Enums\Vertical;
use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentLineDiscountStrippedAtConversion;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * M1 — Workshop DocumentGenerationAdapter must NOT bypass Phase 4 sub-tolerance
 * discount strip.
 *
 * The adapter previously built an Invoice directly and called
 * DocumentPostingService::post without ever invoking
 * StripSubToleranceDiscountsService. A WO Quote line carrying a sub-tolerance
 * discount (e.g. €0.20) would survive into the fiscal hash chain, defeating
 * spec §7's anti-abuse rule.
 *
 * This regression suite asserts the strip runs on the WO → Invoice path:
 *   - The DocumentLine's discount_amount is zeroed (not null) post-strip
 *   - line_total is recomputed from the now-zero discount
 *   - DocumentLineDiscountStrippedAtConversion is dispatched
 *   - Above-tolerance discounts pass through unchanged
 */
final class DocumentGenerationAdapterStripsSubToleranceDiscountTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

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

        // Tolerance margin: 0.5% capped at €0.50 — a €0.20 line discount on a
        // €100.00 line subtotal lands BELOW the margin, triggering the strip.
        CountryPaymentSettings::firstOrCreate(
            ['country_code' => 'FR'],
            [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => '0.0050',
                'max_payment_tolerance_amount' => '0.500',
            ],
        );

        // Detach InvoicePosted listeners — GL/audit pipeline is not in scope.
        Event::forget(InvoicePosted::class);
    }

    public function test_wo_invoice_strips_sub_tolerance_line_discount(): void
    {
        Event::fake([DocumentLineDiscountStrippedAtConversion::class]);

        $wo = $this->buildCompletedWorkOrderWithDiscount(discountPercent: '0.20');

        $invoiceId = $this->transitionToInvoiced($wo);

        /** @var Document $invoice */
        $invoice = Document::with('lines')->findOrFail($invoiceId);
        $line = $invoice->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);

        // Strip must zero discount_amount (not null) and discount_percent.
        $this->assertNotNull($line->discount_amount);
        $this->assertSame(0, bccomp((string) $line->discount_amount, '0', 4));
        $this->assertNotNull($line->discount_percent);
        $this->assertSame(0, bccomp((string) $line->discount_percent, '0', 4));

        // line_total recomputed from now-zero discount: qty * unit_price = 100.00
        $this->assertSame(0, bccomp((string) $line->line_total, '100.000', 3));

        // Audit event dispatched.
        Event::assertDispatched(DocumentLineDiscountStrippedAtConversion::class, function ($event) use ($invoice, $line): bool {
            return $event->lineId === (string) $line->id
                && $event->targetDocumentId === (string) $invoice->id
                && $event->targetType === DocumentType::Invoice->value
                && bccomp($event->originalDiscountAmount, '0.20', 4) === 0;
        });
    }

    public function test_wo_invoice_preserves_above_tolerance_line_discount(): void
    {
        Event::fake([DocumentLineDiscountStrippedAtConversion::class]);

        // 5% on €100.00 = €5.00 — far above the €0.50 cap; must NOT be stripped.
        $wo = $this->buildCompletedWorkOrderWithDiscount(discountPercent: '5.00');

        $invoiceId = $this->transitionToInvoiced($wo);

        /** @var Document $invoice */
        $invoice = Document::with('lines')->findOrFail($invoiceId);
        $line = $invoice->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);

        $this->assertNotNull($line->discount_amount);
        $this->assertSame(0, bccomp((string) $line->discount_amount, '5.000', 3));

        Event::assertNotDispatched(DocumentLineDiscountStrippedAtConversion::class);
    }

    private function buildCompletedWorkOrderWithDiscount(string $discountPercent): WorkOrder
    {
        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'currency' => 'EUR',
        ]);

        // qty=1, unit_price=100, discount_percent → adapter must compute
        // discount_amount on the DocumentLine so the strip service can act on it.
        WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
            'display_name' => 'Brake pad replacement',
            'description' => null,
            'quantity' => '1.000',
            'unit_price' => '100.000',
            'tax_rate' => '20.000',
            'discount_percent' => $discountPercent,
            // Pre-discount baseline values; the adapter is the only writer of
            // the resulting DocumentLine, so the strip must operate on whatever
            // discount_amount the adapter derives.
            'line_total_excl_tax' => '100.000',
            'line_total_tax' => '20.000',
            'line_total_incl_tax' => '120.000',
        ]);

        return $wo;
    }

    private function transitionToInvoiced(WorkOrder $wo): string
    {
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
        $this->assertNotNull($invoiceId, 'WO→Invoice transition must write invoice_document_id.');

        return $invoiceId;
    }
}
