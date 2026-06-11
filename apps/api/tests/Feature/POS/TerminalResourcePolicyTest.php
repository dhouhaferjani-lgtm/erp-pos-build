<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TerminalResourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_resource_exposes_policy_and_location_address(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_stock_policy' => PosStockPolicy::Warn,
        ]);
        $location = Location::factory()->for($company)->create([
            'tax_id' => 'TN-BR-001',
            'address_street' => '12 Rue de Marseille',
            'address_city' => 'Tunis',
            'address_postal_code' => '1001',
            'address_country' => 'TN',
        ]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $payload = TerminalResource::make($terminal->load(['location', 'company']))->resolve();

        self::assertSame('warn', $payload['pos_stock_policy']);
        self::assertSame('12 Rue de Marseille', $payload['location']['address_street']);
        self::assertSame('Tunis', $payload['location']['address_city']);
        self::assertSame('1001', $payload['location']['address_postal_code']);
        self::assertSame('TN', $payload['location']['address_country']);
    }

    public function test_terminal_with_default_policy_exposes_block(): void
    {
        $tenant = Tenant::factory()->create();
        // Company::factory() defaults pos_stock_policy to 'block' (DB column default)
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->for($company)->create();
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $payload = TerminalResource::make($terminal->load(['location', 'company']))->resolve();

        self::assertSame('block', $payload['pos_stock_policy']);
    }

    public function test_terminal_location_with_null_address_fields_exposes_nulls(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->for($company)->create([
            'address_street' => null,
            'address_city' => null,
            'address_postal_code' => null,
            'address_country' => null,
        ]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $payload = TerminalResource::make($terminal->load(['location', 'company']))->resolve();

        self::assertArrayHasKey('address_street', $payload['location']);
        self::assertArrayHasKey('address_city', $payload['location']);
        self::assertArrayHasKey('address_postal_code', $payload['location']);
        self::assertArrayHasKey('address_country', $payload['location']);
        self::assertNull($payload['location']['address_street']);
        self::assertNull($payload['location']['address_city']);
        self::assertNull($payload['location']['address_postal_code']);
        self::assertNull($payload['location']['address_country']);
    }
}
