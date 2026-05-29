<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Presentation\Resources\DocumentTaxBreakdownResource;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Database\Seeders\CountriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6.1: DocumentTaxBreakdownResource must format the zero discount value
 * at the request-bound company's currency scale, not a hardcoded '0.00'.
 */
final class DocumentTaxBreakdownResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountriesSeeder::class);
    }

    #[Test]
    public function it_formats_discount_at_tnd_scale_3(): void
    {
        $company = Company::factory()->tunisia()->create(['tenant_id' => $this->makeTenant()->id]);
        app(CompanyContext::class)->setCompanyId($company->id);

        $result = $this->makeResult();
        $resource = new DocumentTaxBreakdownResource(
            $result,
            app(CurrencyScaleResolverInterface::class),
        );

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame('0.000', $array['discount']);
    }

    #[Test]
    public function it_formats_discount_at_eur_scale_2(): void
    {
        $company = Company::factory()->create(['tenant_id' => $this->makeTenant()->id]); // EUR default → scale 2
        app(CompanyContext::class)->setCompanyId($company->id);

        $result = $this->makeResult();
        $resource = new DocumentTaxBreakdownResource(
            $result,
            app(CurrencyScaleResolverInterface::class),
        );

        $array = $resource->toArray(Request::create('/'));

        $this->assertSame('0.00', $array['discount']);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Test Tenant '.uniqid(),
            'slug' => 'test-tenant-'.uniqid(),
            'country_code' => 'TN',
            'currency_code' => 'TND',
            'status' => 'active',
            'plan' => 'professional',
        ]);
    }

    private function makeResult(): TaxCalculationResult
    {
        return new TaxCalculationResult(
            taxes: [],
            subtotal: '100.000',
            lineItemsTaxTotal: '19.000',
            documentTaxTotal: '0.000',
            totalTax: '19.000',
            total: '119.000',
        );
    }
}
