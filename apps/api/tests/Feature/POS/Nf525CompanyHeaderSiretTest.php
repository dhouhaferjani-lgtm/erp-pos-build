<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Application\Services\Nf525DataProvider;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Compliance\DTOs\Nf525CompanyHeaderData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class Nf525CompanyHeaderSiretTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_header_emits_siret_from_legal_identifiers_or_tax_id(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'NF525 Company',
            'tax_id' => 'COMPANY-TAX',
            'legal_identifiers' => ['siret' => '73282932000074'],
            'address_street' => '10 Rue de la Paix',
            'address_postal_code' => '75002',
            'address_city' => 'Paris',
        ]);

        $ref = new ReflectionClass(Nf525DataProvider::class);
        $provider = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('buildCompanyHeader');
        $data = $method->invoke($provider, $company);

        $this->assertInstanceOf(Nf525CompanyHeaderData::class, $data);
        $this->assertSame('73282932000074', $data->siret);
        $this->assertSame('10 Rue de la Paix 75002 Paris', $data->address);
    }

    public function test_company_header_falls_back_to_tax_id_when_siret_identifier_is_missing(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'NF525 Company',
            'tax_id' => 'COMPANY-TAX',
            'legal_identifiers' => [],
        ]);

        $ref = new ReflectionClass(Nf525DataProvider::class);
        $provider = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('buildCompanyHeader');
        $data = $method->invoke($provider, $company);

        $this->assertInstanceOf(Nf525CompanyHeaderData::class, $data);
        $this->assertSame('COMPANY-TAX', $data->siret);
    }
}
