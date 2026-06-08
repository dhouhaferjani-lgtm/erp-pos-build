<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TerminalResourceBranchTaxTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_includes_location_tax_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create([
            'company_id' => $company->id,
            'tax_id' => 'BRANCH-TAX',
            'vat_number' => 'BR-VAT',
            'legal_identifiers' => ['siret' => 'BR-SIRET'],
        ]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $terminal->load('location');
        $array = (new TerminalResource($terminal))->toArray(Request::create('/'));

        $this->assertSame('BRANCH-TAX', $array['location']['tax_id']);
        $this->assertSame('BR-VAT', $array['location']['vat_number']);
        $this->assertSame('BR-SIRET', $array['location']['legal_identifiers']['siret']);
    }
}
