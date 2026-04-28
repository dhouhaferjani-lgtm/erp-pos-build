<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Application\DTOs\FraudSettingsDTO;
use App\Modules\POS\Application\Services\CashCountValidationService;
use App\Modules\POS\Domain\DTOs\CashCountInputDTO;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Domain\Enums\VarianceDirection;
use App\Shared\Domain\Enums\VarianceSeverity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CashCountValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FraudSettingsDTO $settings;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->settings = new FraudSettingsDTO(
            companyId: $this->company->id,
            cashVarianceOverSoft: '1.0000',
            cashVarianceOverHard: '20.0000',
            cashVarianceUnderSoft: '1.0000',
            cashVarianceUnderHard: '20.0000',
            requireBlindCashCount: false,
            requireManagerPinAboveHard: true,
            cashVarianceEmailSeverity: 'none',
        );
    }

    /**
     * Test 1: Happy path EUR balanced — exact match, no variance.
     */
    public function test_happy_path_balanced_returns_info_no_flags(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '100.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(VarianceSeverity::Info, $result->severity);
        $this->assertFalse($result->needsReason);
        $this->assertFalse($result->needsManagerPin);
        $this->assertSame('0.0000', $result->aggregateVariance->amount);
        $this->assertSame(VarianceDirection::Balanced, $result->aggregateVariance->direction());
        $this->assertCount(1, $result->perTender);
    }

    /**
     * Test 2: Over-soft (INFO) — over by exactly soft threshold.
     */
    public function test_over_by_soft_threshold_returns_info(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '101.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(VarianceSeverity::Info, $result->severity);
        $this->assertFalse($result->needsReason);
        $this->assertFalse($result->needsManagerPin);
        $this->assertSame('1.0000', $result->aggregateVariance->amount);
        $this->assertSame(VarianceDirection::Over, $result->aggregateVariance->direction());
    }

    /**
     * Test 3: Over-warning — over by (soft + 0.0001).
     */
    public function test_over_by_soft_plus_one_unit_returns_warning(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '101.0001',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(VarianceSeverity::Warning, $result->severity);
        $this->assertTrue($result->needsReason);
        $this->assertFalse($result->needsManagerPin);
    }

    /**
     * Test 4: Over-critical — over by (hard + 1.0000). needsPin=true when require_manager_pin_above_hard=true.
     */
    public function test_over_by_hard_plus_one_returns_critical_with_pin(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        // expected=100, actual=121 → variance=+21, hard=20 → Critical
        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '121.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(VarianceSeverity::Critical, $result->severity);
        $this->assertTrue($result->needsReason);
        $this->assertTrue($result->needsManagerPin);
        $this->assertSame(VarianceDirection::Over, $result->aggregateVariance->direction());
    }

    /**
     * Test 5: Under-critical — under by (hard + 1.0000).
     */
    public function test_under_by_hard_plus_one_returns_critical(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        // expected=100, actual=79 → variance=-21, abs=21>20 → Critical
        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '79.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(VarianceSeverity::Critical, $result->severity);
        $this->assertTrue($result->needsReason);
        $this->assertTrue($result->needsManagerPin);
        $this->assertSame(VarianceDirection::Under, $result->aggregateVariance->direction());
    }

    /**
     * Test 6: Critical without manager_pin_required — needsPin=false even at Critical.
     */
    public function test_critical_without_require_manager_pin_does_not_need_pin(): void
    {
        $settings = new FraudSettingsDTO(
            companyId: $this->company->id,
            cashVarianceOverSoft: '1.0000',
            cashVarianceOverHard: '20.0000',
            cashVarianceUnderSoft: '1.0000',
            cashVarianceUnderHard: '20.0000',
            requireBlindCashCount: false,
            requireManagerPinAboveHard: false,
            cashVarianceEmailSeverity: 'none',
        );

        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '121.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(VarianceSeverity::Critical, $result->severity);
        $this->assertTrue($result->needsReason);
        $this->assertFalse($result->needsManagerPin);
    }

    /**
     * Test 7: TND scale precision — aggregate variance -0.0050 stays under soft=1.0000 → Info.
     */
    public function test_tnd_scale_precision_small_variance_is_info(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        // TND: 3-dp currency stored at scale 4.
        // expected=100.0000, actual=99.9950 → variance=-0.0050 < soft=1.0000 → Info
        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'TND',
            actualAmount: '99.9950',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'TND',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(VarianceSeverity::Info, $result->severity);
        $this->assertFalse($result->needsReason);
        $this->assertFalse($result->needsManagerPin);
        $this->assertSame('-0.0050', $result->aggregateVariance->amount);
        $this->assertSame(VarianceDirection::Under, $result->aggregateVariance->direction());
    }

    /**
     * Test 8a: Validation error — currency mismatch.
     */
    public function test_currency_mismatch_produces_validation_error(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'USD',   // mismatch — shift is EUR
            actualAmount: '100.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors);
        $this->assertSame('currency_mismatch', $result->errors[0]->code);
    }

    /**
     * Test 8b: Validation error — duplicate payment_method_id.
     */
    public function test_duplicate_payment_method_id_produces_validation_error(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input1 = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '100.0000',
        );
        $input2 = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '50.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input1, $input2],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors);
        $this->assertSame('duplicate_payment_method', $result->errors[0]->code);
    }

    /**
     * Test 8c: Validation error — non-physical payment method.
     */
    public function test_non_physical_payment_method_produces_validation_error(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => false,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '100.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors);
        $this->assertSame('method_not_physical', $result->errors[0]->code);
    }

    /**
     * Test 8d: Validation error — malformed actualAmount (too many decimal places).
     */
    public function test_malformed_amount_scale5_produces_validation_error(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: '1.23456',   // 5 decimal places — invalid
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors);
        $this->assertSame('amount_format', $result->errors[0]->code);
    }

    /**
     * Test 8e: Validation error — malformed actualAmount (non-numeric).
     */
    public function test_malformed_amount_non_numeric_produces_validation_error(): void
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_physical' => true,
        ]);

        $input = new CashCountInputDTO(
            paymentMethodId: $method->id,
            currencyCode: 'EUR',
            actualAmount: 'abc',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$method->id => '100.0000'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors);
        $this->assertSame('amount_format', $result->errors[0]->code);
    }

    /**
     * Test 8f: Validation error — unknown payment method id.
     */
    public function test_unknown_payment_method_id_produces_validation_error(): void
    {
        $unknownId = '00000000-0000-0000-0000-000000000000';

        $input = new CashCountInputDTO(
            paymentMethodId: $unknownId,
            currencyCode: 'EUR',
            actualAmount: '100.0000',
        );

        $service = app(CashCountValidationService::class);
        $result = $service->validate(
            inputs: [$input],
            settings: $this->settings,
            currencyCode: 'EUR',
            expectedPerMethod: [$unknownId => '100.0000'],
        );

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors);
        $this->assertSame('method_not_found', $result->errors[0]->code);
    }
}
