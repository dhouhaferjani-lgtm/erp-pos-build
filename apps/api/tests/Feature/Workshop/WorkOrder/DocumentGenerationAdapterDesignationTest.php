<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\WorkOrder;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Application\Commands\TransitionStatusCommand;
use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * T9: WorkOrder → Document adapter must split display_name + description
 * into separate designation / notes fields instead of concatenating with em-dash.
 */
final class DocumentGenerationAdapterDesignationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Mechanic]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        // Detach InvoicePosted listeners that require GL setup (not scope of this test)
        Event::forget(InvoicePosted::class);
    }

    public function test_invoice_line_description_is_display_name_only(): void
    {
        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
            'display_name' => 'Brake pad replacement',
            'description' => '2.5h × 60/hr by John',
        ]);

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

        /** @var Document $invoice */
        $invoice = Document::with('lines')->findOrFail($invoiceId);
        $line = $invoice->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);

        $this->assertSame(
            'Brake pad replacement',
            $line->description,
            'description must be display_name only — no em-dash concatenation.'
        );

        $this->assertStringNotContainsString(
            ' — ',
            (string) $line->description,
            'description must NOT contain em-dash separator.'
        );

        $this->assertSame(
            '2.5h × 60/hr by John',
            $line->notes,
            'notes must carry the WO line description.'
        );

        $this->assertSame(
            'Brake pad replacement',
            $line->designation_default_snapshot,
            'designation_default_snapshot must be the display_name.'
        );
    }

    public function test_invoice_line_notes_is_null_when_wo_line_description_is_null(): void
    {
        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
            'display_name' => 'Oil change',
            'description' => null,
        ]);

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

        /** @var Document $invoice */
        $invoice = Document::with('lines')->findOrFail($invoiceId);
        $line = $invoice->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);

        $this->assertSame('Oil change', $line->description);
        $this->assertNull($line->notes);
        $this->assertSame('Oil change', $line->designation_default_snapshot);
    }

    public function test_designation_snapshot_is_truncated_to_500_chars_for_very_long_display_names(): void
    {
        $longName = str_repeat('A', 600);

        $wo = WorkOrder::factory()->completed()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        WorkOrderLine::factory()->part()->create([
            'tenant_id' => $wo->tenant_id,
            'work_order_id' => $wo->id,
            'display_name' => $longName,
            'description' => null,
        ]);

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

        /** @var Document $invoice */
        $invoice = Document::with('lines')->findOrFail($invoiceId);
        $line = $invoice->lines->first();
        $this->assertInstanceOf(DocumentLine::class, $line);

        $this->assertSame(500, mb_strlen((string) $line->designation_default_snapshot));
    }
}
