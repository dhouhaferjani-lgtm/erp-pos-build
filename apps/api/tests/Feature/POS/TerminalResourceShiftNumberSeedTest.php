<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\TerminalResource;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Offline-first shifts Phase 6.1 — the terminal payload carries the server's
 * current `MAX(pos_shifts.shift_number)` so a freshly-installed device can seed
 * its per-terminal `shift_number` counter and continue numbering monotonically
 * (rather than restarting at 1 and colliding with server-projected numbers from
 * a prior install).
 */
final class TerminalResourceShiftNumberSeedTest extends TestCase
{
    use RefreshDatabase;

    private function makeTerminal(): Terminal
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);

        return Terminal::factory()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
    }

    #[Test]
    public function it_reports_zero_max_shift_number_for_a_terminal_with_no_shifts(): void
    {
        $terminal = $this->makeTerminal();

        $array = (new TerminalResource($terminal))->toArray(Request::create('/'));

        $this->assertSame(0, $array['max_shift_number']);
    }

    #[Test]
    public function it_reports_the_highest_shift_number_across_the_terminal_shifts(): void
    {
        $terminal = $this->makeTerminal();
        $cashier = User::factory()->create(['tenant_id' => $terminal->tenant_id]);

        foreach ([1, 2, 3] as $number) {
            Shift::create([
                'terminal_id' => $terminal->id,
                'cashier_id' => $cashier->id,
                'shift_number' => $number,
                'status' => ShiftStatus::Closed,
                'opened_at' => now(),
                'closed_at' => now(),
                'opening_cash' => '100.0000',
            ]);
        }

        $array = (new TerminalResource($terminal))->toArray(Request::create('/'));

        $this->assertSame(3, $array['max_shift_number']);
    }

    #[Test]
    public function the_show_endpoint_exposes_max_shift_number_via_the_eager_aggregate(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('pos.manage_terminals', 'sanctum');
        $user->givePermissionTo('pos.manage_terminals');
        Sanctum::actingAs($user);

        foreach ([10, 11] as $number) {
            Shift::create([
                'terminal_id' => $terminal->id,
                'cashier_id' => $user->id,
                'shift_number' => $number,
                'status' => ShiftStatus::Closed,
                'opened_at' => now(),
                'closed_at' => now(),
                'opening_cash' => '100.0000',
            ]);
        }

        $response = $this->getJson("/api/v1/pos/terminals/{$terminal->id}");

        $response->assertStatus(200);
        $this->assertSame(11, $response->json('data.max_shift_number'));
    }
}
