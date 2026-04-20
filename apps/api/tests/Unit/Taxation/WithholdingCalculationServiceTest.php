<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Taxation\Domain\Services\WithholdingCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for WithholdingCalculationService
 *
 * Tests the rule matching logic and calculation accuracy with database.
 */
class WithholdingCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private WithholdingCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(WithholdingCalculationService::class);
    }

    /** @test */
    public function it_calculates_withholding_for_professional_services(): void
    {
        // Arrange
        $partner = $this->createPartner('REGISTERED');
        $rule = $this->createRuleInDatabase('TN_PROF_SERVICES_3', 3.0, 'services', 'REGISTERED');

        // Act
        $calculation = $this->service->calculateForPayment(
            $partner,
            '1000.000',
            'TND',
            'TN',
            'company-123',
            TransactionType::SERVICES
        );

        // Assert
        $this->assertNotNull($calculation);
        $this->assertEquals('1000.000', $calculation->grossAmount);
        $this->assertEquals('0.0300', $calculation->withholdingRate);
        $this->assertEquals('30.000', $calculation->withholdingAmount);
        $this->assertEquals('970.000', $calculation->netAmount);
        $this->assertEquals($rule->id, $calculation->ruleId);
        $this->assertNull($calculation->overrideReason);
    }

    /** @test */
    public function it_calculates_withholding_for_individual_forfait_regime(): void
    {
        // Arrange
        $partner = $this->createPartner('NON_REGISTERED');
        $rule = $this->createRuleInDatabase('TN_PROF_SERVICES_10', 10.0, 'services', 'NON_REGISTERED');

        // Act
        $calculation = $this->service->calculateForPayment(
            $partner,
            '1000.000',
            'TND',
            'TN',
            'company-123',
            TransactionType::SERVICES
        );

        // Assert
        $this->assertNotNull($calculation);
        $this->assertEquals('0.1000', $calculation->withholdingRate);
        $this->assertEquals('100.000', $calculation->withholdingAmount);
        $this->assertEquals('900.000', $calculation->netAmount);
    }

    /** @test */
    public function it_returns_null_when_no_rule_matches(): void
    {
        // Arrange
        $partner = $this->createPartner('REGISTERED');
        // No rules created in database

        // Act
        $calculation = $this->service->calculateForPayment(
            $partner,
            '500.000',
            'TND',
            'TN',
            'company-123',
            TransactionType::SERVICES
        );

        // Assert
        $this->assertNull($calculation);
    }

    /** @test */
    public function it_respects_minimum_amount_threshold(): void
    {
        // Arrange
        $partner = $this->createPartner('REGISTERED');
        $rule = $this->createRuleInDatabase('TN_GOODS_1_5', 1.5, null, 'REGISTERED', '1000.000');

        // Act - Below threshold
        $calculation = $this->service->calculateForPayment(
            $partner,
            '500.000',
            'TND',
            'TN',
            'company-123',
            null
        );

        // Assert
        $this->assertNull($calculation);

        // Act - Above threshold
        $calculation = $this->service->calculateForPayment(
            $partner,
            '1500.000',
            'TND',
            'TN',
            'company-123',
            null
        );

        // Assert
        $this->assertNotNull($calculation);
        $this->assertEquals('1500.000', $calculation->grossAmount);
        $this->assertEquals('22.500', $calculation->withholdingAmount);
    }

    /** @test */
    public function it_allows_manual_override(): void
    {
        // Act
        $calculation = $this->service->calculateWithOverride(
            '1000.000',
            'TND',
            5.0,
            'Special agreement with supplier',
            TransactionType::SERVICES
        );

        // Assert
        $this->assertNotNull($calculation);
        $this->assertEquals('1000.000', $calculation->grossAmount);
        $this->assertEquals('0.0500', $calculation->withholdingRate);
        $this->assertEquals('50.000', $calculation->withholdingAmount);
        $this->assertEquals('950.000', $calculation->netAmount);
        $this->assertNull($calculation->ruleId);
        $this->assertEquals('Special agreement with supplier', $calculation->overrideReason);
    }

    /** @test */
    public function it_selects_most_specific_rule_when_multiple_match(): void
    {
        // Arrange
        $partner = $this->createPartner('REGISTERED');

        // Generic rule (no transaction type)
        $genericRule = $this->createRuleInDatabase('TN_GENERIC', 1.0, null, 'REGISTERED');
        // Specific rule (with transaction type)
        $specificRule = $this->createRuleInDatabase('TN_SERVICES_SPECIFIC', 3.0, 'services', 'REGISTERED');

        // Act
        $calculation = $this->service->calculateForPayment(
            $partner,
            '1000.000',
            'TND',
            'TN',
            'company-123',
            TransactionType::SERVICES
        );

        // Assert - Should use specific rule
        $this->assertNotNull($calculation);
        $this->assertEquals($specificRule->id, $calculation->ruleId);
        $this->assertEquals('0.0300', $calculation->withholdingRate);
    }

    /** @test */
    public function it_handles_decimal_precision_correctly(): void
    {
        // Arrange
        $partner = $this->createPartner('REGISTERED');
        $rule = $this->createRuleInDatabase('TN_PRECISE', 1.5, 'services', 'REGISTERED');

        // Act
        $calculation = $this->service->calculateForPayment(
            $partner,
            '1234.567',
            'TND',
            'TN',
            'company-123',
            TransactionType::SERVICES
        );

        // Assert
        $this->assertNotNull($calculation);
        $this->assertEquals('1234.567', $calculation->grossAmount);
        $this->assertEquals('18.518', $calculation->withholdingAmount); // 1234.567 * 0.015 = 18.518505 → 18.518 (rounded to 3 decimals)
        $this->assertEquals('1216.049', $calculation->netAmount); // 1234.567 - 18.518
    }

    /**
     * Create a mock partner with tax status
     */
    private function createPartner(string $taxStatusValue): Partner
    {
        $partner = new Partner;
        $partner->id = 'partner-123';
        $partner->name = 'Test Partner';
        $partner->country_code = 'TN';
        $partner->tax_status = PartnerTaxStatus::from($taxStatusValue);
        $partner->withholding_exempt = false;

        return $partner;
    }

    /**
     * Create a withholding tax rule in the database
     */
    private function createRuleInDatabase(
        string $code,
        float $ratePercentage,
        ?string $transactionType = null,
        ?string $partnerTaxStatus = null,
        ?string $minAmount = null
    ): WithholdingTaxRule {
        return WithholdingTaxRule::create([
            'country_code' => 'TN',
            'code' => $code,
            'name' => 'Rule '.$code,
            'transaction_type' => $transactionType,
            'partner_tax_status' => $partnerTaxStatus,
            'min_amount' => $minAmount,
            'rate' => $ratePercentage / 100,
            'is_active' => true,
            'effective_from' => now()->subYear(),
            'effective_to' => null,
        ]);
    }
}
