<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentSequence;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\RefundService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.2 — RefundService scale propagation.
 *
 * Gold standard: partial credit note with 3 TND lines
 *   12.347 + 5.123 + 8.999 = 26.469 at scale 3
 *   (NOT 26.46 which is the scale-2 truncation)
 */
class RefundServiceScalingTest extends TestCase
{
    use RefreshDatabase;

    private RefundService $service;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RefundService::class);
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
    public function partial_credit_note_total_preserves_third_decimal_for_tnd(): void
    {
        // Create a posted TND invoice
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-TND-001',
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'TND',
            'subtotal' => '26.469',
            'discount_amount' => '0.000',
            'tax_amount' => '0.000',
            'total' => '26.469',
            'balance_due' => '26.469',
            'is_historical' => false,
            'fiscal_hash' => hash('sha256', 'INV-TND-001'),
            'chain_sequence' => 1,
        ]);

        // Partial credit note: 3 lines with TND sub-cent amounts
        $lineItems = [
            [
                'description' => 'Line 1',
                'quantity' => '1.000',
                'unit_price' => '12.347',
                'discount_percent' => '0.000',
                'discount_amount' => '0.000',
                'tax_rate' => '0.000',
                'tax_amount' => '0.000',
                'line_total' => '12.347',
                'line_number' => 1,
            ],
            [
                'description' => 'Line 2',
                'quantity' => '1.000',
                'unit_price' => '5.123',
                'discount_percent' => '0.000',
                'discount_amount' => '0.000',
                'tax_rate' => '0.000',
                'tax_amount' => '0.000',
                'line_total' => '5.123',
                'line_number' => 2,
            ],
            [
                'description' => 'Line 3',
                'quantity' => '1.000',
                'unit_price' => '8.999',
                'discount_percent' => '0.000',
                'discount_amount' => '0.000',
                'tax_rate' => '0.000',
                'tax_amount' => '0.000',
                'line_total' => '8.999',
                'line_number' => 3,
            ],
        ];

        $creditNote = $this->service->createPartialCreditNote(
            $invoice,
            $lineItems,
            'Scale precision test',
        );

        // 12.347 + 5.123 + 8.999 = 26.469 at scale 3 — NOT 26.46 (scale-2 truncation)
        $this->assertSame(
            '26.469',
            $creditNote->total,
            'RefundService must accumulate line totals at scale 3 for TND; got truncated value'
        );
        $this->assertNull($creditNote->document_number);
        $this->assertSame(0, DocumentSequence::query()->where('type', DocumentType::CreditNote->value)->count());
    }

    /** @test */
    public function full_credit_note_is_an_unnumbered_draft(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-FULL-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '10.000',
            'tax_amount' => '0.000',
            'total' => '10.000',
        ]);
        $invoice->lines()->create([
            'line_number' => 1,
            'description' => 'Full credit line',
            'quantity' => '1.0000',
            'unit_price' => '10.000',
            'tax_rate' => '0',
            'line_total' => '10.000',
        ]);

        $creditNote = $this->service->createFullCreditNote(
            $invoice->fresh('lines'),
            'Full credit',
        );

        $this->assertNull($creditNote->document_number);
        $this->assertSame(0, DocumentSequence::query()->where('type', DocumentType::CreditNote->value)->count());
    }
}
