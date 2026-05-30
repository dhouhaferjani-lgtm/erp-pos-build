<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\AgedReceivablesService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.1 — AgedReceivablesService scale propagation.
 *
 * Gold standard: 5 × TND invoice with balance_due='1234.567'
 * → bucket total = '6172.835' (NOT '6172.80' which is truncated to scale 2).
 */
class AgedReceivablesScalingTest extends TestCase
{
    use RefreshDatabase;

    private AgedReceivablesService $service;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AgedReceivablesService::class);

        $this->tenant = Tenant::factory()->create();

        // TND company — resolver must return scale 3
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Bind company context so the resolver resolves TND scale 3
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function aged_receivables_bucket_total_preserves_third_decimal_for_tnd(): void
    {
        // Create 5 posted invoices with balance_due = '1234.567' (TND sub-cent amount)
        for ($i = 1; $i <= 5; $i++) {
            // All invoices are current (due_date in the future)
            Document::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->partner->id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Posted,
                'document_number' => "INV-SCALE-{$i}",
                'document_date' => now()->subDays(5),
                'due_date' => now()->addDays(30), // not overdue → 'current' bucket
                'currency' => 'TND',
                'subtotal' => '1234.567',
                'discount_amount' => '0.000',
                'tax_amount' => '0.000',
                'total' => '1234.567',
                'balance_due' => '1234.567',
                'is_historical' => false,
                'fiscal_hash' => hash('sha256', "INV-SCALE-{$i}"),
                'chain_sequence' => $i,
            ]);
        }

        $report = $this->service->generateReport($this->company->id);

        // 5 × 1234.567 = 6172.835 at scale 3 — NOT 6172.80 (truncated at scale 2)
        $this->assertSame(
            '6172.835',
            $report['summary']['current'],
            'AgedReceivablesService must use scale-3 accumulation for TND; got truncated value'
        );

        $this->assertSame(
            '6172.835',
            $report['total_outstanding'],
            'total_outstanding must also reflect TND scale 3'
        );
    }
}
