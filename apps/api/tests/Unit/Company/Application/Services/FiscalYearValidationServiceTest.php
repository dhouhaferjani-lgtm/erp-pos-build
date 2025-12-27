<?php

declare(strict_types=1);

namespace Tests\Unit\Company\Application\Services;

use App\Modules\Company\Application\Services\FiscalYearCreationService;
use App\Modules\Company\Application\Services\FiscalYearValidationService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Events\FirstTransactionPosted;
use App\Modules\Company\Domain\Events\FiscalYearValidated;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FiscalYearValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalYearValidationService $service;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $fiscalYearCreationService = app(FiscalYearCreationService::class);
        $this->service = new FiscalYearValidationService($fiscalYearCreationService);
    }

    public function test_it_validates_fiscal_year_and_dispatches_event(): void
    {
        Event::fake([FiscalYearValidated::class]);

        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_start_month' => 1,
            'fiscal_year_validated_at' => null,
        ]);

        $this->service->validateFiscalYear($company, 1, $this->user);

        $company->refresh();

        $this->assertNotNull($company->fiscal_year_validated_at);
        $this->assertEquals($this->user->id, $company->fiscal_year_validated_by);
        $this->assertTrue($company->isFiscalYearValidated());

        Event::assertDispatched(FiscalYearValidated::class, function ($event) use ($company) {
            return $event->companyId === $company->id
                && $event->tenantId === $company->tenant_id
                && $event->fiscalYearStartMonth === 1
                && $event->validatedBy === $this->user->id;
        });
    }

    public function test_it_prevents_validation_when_already_validated(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => now(),
            'fiscal_year_validated_by' => $this->user->id,
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Fiscal year already validated');

        $this->service->validateFiscalYear($company, 1, $this->user);
    }

    public function test_it_prevents_validation_when_locked(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_transaction_posted_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Fiscal year is locked and cannot be validated');

        $this->service->validateFiscalYear($company, 1, $this->user);
    }

    public function test_it_recreates_fiscal_years_when_start_month_changes(): void
    {
        Event::fake([FiscalYearValidated::class]);

        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_start_month' => 1,
            'fiscal_year_validated_at' => null,
        ]);

        // Should have 3 fiscal years with January start
        $this->assertCount(3, $company->fiscalYears);
        $oldFiscalYearIds = $company->fiscalYears->pluck('id')->toArray();

        // Validate with different start month
        $this->service->validateFiscalYear($company, 7, $this->user);

        $company->refresh();

        // Should have recreated fiscal years with July start
        $this->assertCount(3, $company->fiscalYears);
        $this->assertEquals(7, $company->fiscal_year_start_month);

        foreach ($company->fiscalYears as $fiscalYear) {
            $this->assertEquals(7, $fiscalYear->start_date->month);
            $this->assertNotContains($fiscalYear->id, $oldFiscalYearIds);
        }
    }

    public function test_it_records_first_transaction_and_locks_fiscal_year(): void
    {
        Event::fake([FirstTransactionPosted::class]);

        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => now(),
            'first_transaction_posted_at' => null,
        ]);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-001',
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $this->service->recordFirstTransaction($company, $document);

        $company->refresh();

        $this->assertNotNull($company->first_transaction_posted_at);
        $this->assertEquals($document->id, $company->first_transaction_document_id);
        $this->assertTrue($company->hasFiscalYearLocked());
        $this->assertFalse($company->canChangeFiscalYear());

        Event::assertDispatched(FirstTransactionPosted::class, function ($event) use ($company, $document) {
            return $event->companyId === $company->id
                && $event->tenantId === $company->tenant_id
                && $event->documentId === $document->id
                && $event->documentNumber === 'INV-2025-001'
                && $event->documentType === $document->type->value;
        });
    }

    public function test_it_prevents_recording_first_transaction_when_not_validated(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => null,
        ]);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-002',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Fiscal year must be validated before posting transactions');

        $this->service->recordFirstTransaction($company, $document);
    }

    public function test_it_prevents_recording_first_transaction_when_already_locked(): void
    {
        $company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_year_validated_at' => now(),
            'first_transaction_posted_at' => now(),
        ]);

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
        ]);

        $document = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-2025-003',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Fiscal year already locked');

        $this->service->recordFirstTransaction($company, $document);
    }
}
