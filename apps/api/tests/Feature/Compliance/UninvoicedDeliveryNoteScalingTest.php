<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\UninvoicedDeliveryNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.3 — UninvoicedDeliveryNoteService scale propagation.
 *
 * Gold standard: 100 × TND delivery note with total='1234.567'
 * → calculateUninvoicedTotals()['total'] = '123456.700'
 * (NOT '123456.70' which is the scale-2 truncation)
 */
class UninvoicedDeliveryNoteScalingTest extends TestCase
{
    use RefreshDatabase;

    private UninvoicedDeliveryNoteService $service;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(UninvoicedDeliveryNoteService::class);

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
    public function uninvoiced_totals_preserves_third_decimal_for_tnd(): void
    {
        // Create 100 confirmed TND delivery notes with total = '1234.567'
        for ($i = 1; $i <= 100; $i++) {
            Document::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->partner->id,
                'type' => DocumentType::DeliveryNote,
                'status' => DocumentStatus::Confirmed,
                'document_number' => "DN-SCALE-{$i}",
                'document_date' => now(),
                'currency' => 'TND',
                'subtotal' => '1234.567',
                'discount_amount' => '0.000',
                'tax_amount' => '0.000',
                'total' => '1234.567',
                // No 'invoiced_at' in payload → qualifies as uninvoiced
                'payload' => null,
                'is_historical' => false,
            ]);
        }

        $totals = $this->service->calculateUninvoicedTotals($this->company->id);

        // 100 × 1234.567 = 123456.700 at scale 3 — NOT 123456.70 (scale-2 truncation)
        $this->assertSame(
            '123456.700',
            $totals['total'],
            'UninvoicedDeliveryNoteService must accumulate totals at scale 3 for TND; got truncated value'
        );

        $this->assertSame(100, $totals['count']);
    }
}
