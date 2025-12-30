<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Taxation\Domain\Services\StampDutyService;
use Database\Seeders\TunisiaStampDutySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StampDutyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed countries first (required for foreign key constraint)
        $this->seed(\Database\Seeders\CountriesSeeder::class);

        // Seed Tunisia stamp duty rules
        $this->seed(TunisiaStampDutySeeder::class);
    }

    public function test_it_retrieves_correct_stamp_duty_rule_for_tunisia(): void
    {
        // Arrange
        $service = app(StampDutyService::class);

        // Act: Calculate stamp duty for Tunisia TAX_INVOICE
        $result = $service->calculateStampDuty(
            countryCode: 'TN',
            documentType: DocumentType::Invoice,
            fiscalCategory: FiscalCategory::TaxInvoice,
            documentDate: now()
        );

        // Assert: Tunisia TAX_INVOICE should have 1.000 TND stamp duty
        $this->assertNotNull($result, 'Tunisia should have stamp duty rule');
        $this->assertEquals('1.000', $result->amount, 'Tunisia TAX_INVOICE stamp duty should be 1.000 TND');
        $this->assertEquals('Stamp Duty', $result->name);
        $this->assertEquals('TN', $result->countryCode);
        $this->assertNotNull($result->ruleId, 'Should return the stamp duty rule ID');
    }

    public function test_it_returns_null_for_countries_without_stamp_duty(): void
    {
        // Arrange
        $service = app(StampDutyService::class);

        // Act: Calculate stamp duty for France (no stamp duty rules seeded)
        $result = $service->calculateStampDuty(
            countryCode: 'FR',
            documentType: DocumentType::Invoice,
            fiscalCategory: FiscalCategory::TaxInvoice,
            documentDate: now()
        );

        // Assert: France should have no stamp duty
        $this->assertNull($result, 'France should have no stamp duty rule');
    }

    public function test_it_retrieves_correct_stamp_duty_for_fiscal_receipt(): void
    {
        // Arrange
        $service = app(StampDutyService::class);

        // Act: Calculate stamp duty for Tunisia FISCAL_RECEIPT
        $result = $service->calculateStampDuty(
            countryCode: 'TN',
            documentType: DocumentType::Invoice,
            fiscalCategory: FiscalCategory::FiscalReceipt,
            documentDate: now()
        );

        // Assert: Tunisia FISCAL_RECEIPT should have 0.100 TND stamp duty
        $this->assertNotNull($result, 'Tunisia should have stamp duty rule for receipts');
        $this->assertEquals('0.100', $result->amount, 'Tunisia FISCAL_RECEIPT stamp duty should be 0.100 TND');
    }

    public function test_it_respects_effective_date_ranges(): void
    {
        // Arrange
        $service = app(StampDutyService::class);

        // Act: Try to calculate stamp duty for a date before the rule's effective date
        $result = $service->calculateStampDuty(
            countryCode: 'TN',
            documentType: DocumentType::Invoice,
            fiscalCategory: FiscalCategory::TaxInvoice,
            documentDate: now()->subYears(10) // Way before 2020-01-01
        );

        // Assert: Should return null for dates outside the effective range
        $this->assertNull($result, 'Should return null for dates before effective_from');
    }
}
