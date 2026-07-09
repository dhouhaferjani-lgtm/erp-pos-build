<?php

declare(strict_types=1);

namespace Tests\Unit\Taxation;

use App\Modules\Taxation\Application\DTOs\WithholdingCalculationData;
use App\Modules\Taxation\Application\DTOs\WithholdingRuleData;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\ValueObjects\WithholdingCalculation;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6.3: getRateAsPercentage() must return a numeric-string (e.g. '1.00')
 * rather than laundering the bcmul result through a (float) cast.
 */
final class WithholdingRateAsPercentageTest extends TestCase
{
    #[Test]
    public function entity_returns_percentage_as_string(): void
    {
        $rule = new WithholdingTaxRule;
        $rule->setRawAttributes(['rate' => '0.0100'], true);

        $result = $rule->getRateAsPercentage();

        // assertSame against a string literal proves both the value and the type.
        $this->assertSame('1.00', $result);
    }

    #[Test]
    public function dto_returns_percentage_as_string(): void
    {
        $data = new WithholdingRuleData(
            id: '00000000-0000-0000-0000-000000000001',
            countryCode: 'TN',
            companyId: null,
            code: 'WHT-1',
            name: 'Test rule',
            description: null,
            transactionType: null,
            partnerTaxStatus: null,
            minAmount: null,
            rate: '0.0100',
            effectiveFrom: Carbon::parse('2026-01-01'),
            effectiveTo: null,
            isActive: true,
            createdAt: null,
            updatedAt: null,
        );

        $result = $data->getRateAsPercentage();

        // assertSame against a string literal proves both the value and the type.
        $this->assertSame('1.00', $result);
        $this->assertSame('1.00', $data->toArray()['rate_percentage']);
    }

    /**
     * Regression: WithholdingCalculationData::$ratePercentage was typed `float`
     * while getRateAsPercentage() returns a string, so fromValueObject() threw a
     * TypeError under strict_types and 500'd the /withholding/preview endpoint.
     */
    #[Test]
    public function calculation_dto_from_value_object_returns_percentage_as_string(): void
    {
        $calculation = new WithholdingCalculation(
            grossAmount: '1000.000',
            withholdingRate: '0.0100',
            withholdingAmount: '10.000',
            netAmount: '990.000',
            currency: 'TND',
        );

        $data = WithholdingCalculationData::fromValueObject($calculation);

        $this->assertSame('1.00', $data->ratePercentage);
        $this->assertSame('1.00', $data->toArray()['rate_percentage']);
    }
}
