<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Application\Services\VirtualAdminTerminalResolver;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class VirtualAdminTerminalResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_creates_one_virtual_admin_terminal_per_company(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        $resolver = app(VirtualAdminTerminalResolver::class);

        $first = $resolver->resolve($tenant->id, $company->id);
        $second = $resolver->resolve($tenant->id, $company->id);

        $this->assertTrue($first->is($second));
        $this->assertSame(TerminalType::VirtualAdmin, $first->type);
        $this->assertSame($location->id, $first->location_id);
        $this->assertTrue($first->isVirtualAdmin());
        $this->assertSame(1, Terminal::query()
            ->where('tenant_id', $tenant->id)
            ->where('company_id', $company->id)
            ->where('type', TerminalType::VirtualAdmin)
            ->count());
    }

    public function test_virtual_admin_terminals_are_excluded_from_claimable_physical_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'type' => TerminalType::VirtualAdmin,
            'hardware_identifier' => null,
        ]);

        $this->assertFalse(Terminal::query()->physical()->whereKey($terminal->id)->exists());
        $this->assertTrue(Terminal::query()->virtualAdmin()->whereKey($terminal->id)->exists());
    }

    public function test_database_rejects_duplicate_virtual_admin_terminal_per_company_on_postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index enforced by PostgreSQL');
        }

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'type' => TerminalType::VirtualAdmin,
            'code' => 'VADMIN-1',
        ]);

        $this->expectException(QueryException::class);
        Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'type' => TerminalType::VirtualAdmin,
            'code' => 'VADMIN-2',
        ]);
    }
}
