<?php

declare(strict_types=1);

namespace Tests\Feature\Precision;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Presentation\Requests\CreateProductRequest;
use App\Modules\Product\Presentation\Requests\UpdateProductRequest;
use App\Modules\Service\Domain\Enums\PricingType;
use App\Modules\Service\Presentation\Requests\CreateServiceRequest;
use App\Modules\Service\Presentation\Requests\UpdateServiceRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class PercentIngressGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        app(CompanyContext::class)->setCompanyId($company->id);
    }

    public function test_service_tax_rate_rejects_three_decimals_before_casting(): void
    {
        $create = Validator::make($this->servicePayload(['tax_rate' => '19.125']), (new CreateServiceRequest)->rules());
        $update = Validator::make(['tax_rate' => '19.125'], (new UpdateServiceRequest)->rules());

        $this->assertArrayHasKey('tax_rate', $create->errors()->toArray());
        $this->assertArrayHasKey('tax_rate', $update->errors()->toArray());
    }

    public function test_service_tax_rate_accepts_two_decimals_before_casting(): void
    {
        $create = Validator::make($this->servicePayload(['tax_rate' => '19.12']), (new CreateServiceRequest)->rules());
        $update = Validator::make(['tax_rate' => '19.12'], (new UpdateServiceRequest)->rules());

        $this->assertEmpty($create->errors()->get('tax_rate'));
        $this->assertEmpty($update->errors()->get('tax_rate'));
    }

    public function test_product_margin_overrides_reject_three_decimals_before_casting(): void
    {
        $create = Validator::make(
            $this->productPayload([
                'target_margin_override' => '35.125',
                'minimum_margin_override' => '20.125',
            ]),
            (new CreateProductRequest(app(CompanyContext::class)))->rules(),
        );
        $update = Validator::make(
            [
                'target_margin_override' => '35.125',
                'minimum_margin_override' => '20.125',
            ],
            (new UpdateProductRequest(app(CompanyContext::class)))->rules(),
        );

        $this->assertArrayHasKey('target_margin_override', $create->errors()->toArray());
        $this->assertArrayHasKey('minimum_margin_override', $create->errors()->toArray());
        $this->assertArrayHasKey('target_margin_override', $update->errors()->toArray());
        $this->assertArrayHasKey('minimum_margin_override', $update->errors()->toArray());
    }

    public function test_product_margin_overrides_accept_two_decimals_before_casting(): void
    {
        $create = Validator::make(
            $this->productPayload([
                'target_margin_override' => '35.12',
                'minimum_margin_override' => '20.12',
            ]),
            (new CreateProductRequest(app(CompanyContext::class)))->rules(),
        );
        $update = Validator::make(
            [
                'target_margin_override' => '35.12',
                'minimum_margin_override' => '20.12',
            ],
            (new UpdateProductRequest(app(CompanyContext::class)))->rules(),
        );

        $this->assertEmpty($create->errors()->get('target_margin_override'));
        $this->assertEmpty($create->errors()->get('minimum_margin_override'));
        $this->assertEmpty($update->errors()->get('target_margin_override'));
        $this->assertEmpty($update->errors()->get('minimum_margin_override'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function servicePayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'OIL-CHANGE',
            'name' => 'Oil Change',
            'pricing_type' => PricingType::FlatRate->value,
            'base_price' => '40.00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Oil Filter',
            'sku' => 'FILTER-001',
        ], $overrides);
    }
}
