<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit finding 🔴-4a: the Document model's `$fillable` list was missing
 * `work_order_id`, causing Laravel's mass-assignment guard to silently drop
 * the value set by `DocumentGenerationAdapter::buildDocument()`. Every
 * WO-generated invoice had `documents.work_order_id = NULL` and all
 * reporting that correlated WOs to fiscal documents showed zeros.
 *
 * This test pins the minimum invariant: `Document::create()` with
 * `work_order_id` must persist that value to the row.
 */
final class DocumentFillableAllowsWorkOrderIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_create_persists_work_order_id_via_mass_assignment(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $document = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-TEST-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.000',
            'tax_amount' => '0.000',
            'total' => '0.000',
            'work_order_id' => $workOrder->id,
        ]);

        $this->assertSame($workOrder->id, $document->work_order_id);
        $this->assertSame(
            $workOrder->id,
            (string) Document::query()->where('id', $document->id)->value('work_order_id'),
            'work_order_id must be persisted to the documents row, not silently dropped by the mass-assignment guard.',
        );
    }
}
