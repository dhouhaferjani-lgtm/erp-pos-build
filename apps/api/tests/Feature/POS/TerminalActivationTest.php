<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Events\TerminalActivated;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TerminalActivationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_terminals', 'sanctum');
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.manage_terminals');

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => false,
            'hardware_identifier' => 'HW-ABC-123',
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_activate_terminal_sets_active_and_dispatches_event(): void
    {
        Event::fake([TerminalActivated::class]);

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}/activate");

        $response->assertStatus(200);

        $this->terminal->refresh();
        $this->assertTrue($this->terminal->is_active);
        $this->assertNotNull($this->terminal->activated_at);

        Event::assertDispatched(TerminalActivated::class, function (TerminalActivated $event) {
            return $event->terminalId === $this->terminal->id
                && $event->terminalCode === $this->terminal->code
                && $event->terminalName === $this->terminal->name
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id;
        });
    }

    public function test_activate_terminal_requires_manage_terminals_permission(): void
    {
        $userWithoutPermission = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $userWithoutPermission->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        $userWithoutPermission->givePermissionTo('pos.operate_terminal');

        Sanctum::actingAs($userWithoutPermission);

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}/activate");

        $response->assertStatus(403);
    }

    public function test_activate_terminal_clears_deactivation_fields(): void
    {
        Event::fake([TerminalActivated::class]);

        // Pre-set deactivation fields
        $this->terminal->update([
            'is_active' => false,
            'deactivated_at' => now()->subDay(),
            'deactivation_reason' => 'Maintenance',
        ]);

        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}/activate");

        $response->assertStatus(200);

        $this->terminal->refresh();
        $this->assertTrue($this->terminal->is_active);
        $this->assertNull($this->terminal->deactivated_at);
        $this->assertNull($this->terminal->deactivation_reason);
    }
}
