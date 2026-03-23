<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class TerminalDiscountSettingsTest extends TestCase
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
        $this->user->givePermissionTo('pos.manage_terminals');

        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_update_terminal_discount_settings(): void
    {
        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}", [
            'max_discount_percent' => 50.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => false,
        ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(50, $data['max_discount_percent']);
        $this->assertTrue($data['allow_line_discounts']);
        $this->assertFalse($data['allow_transaction_discounts']);

        $this->terminal->refresh();
        $this->assertEquals(50.0, $this->terminal->max_discount_percent);
        $this->assertTrue($this->terminal->allow_line_discounts);
        $this->assertFalse($this->terminal->allow_transaction_discounts);
    }

    public function test_discount_settings_appear_in_terminal_response(): void
    {
        $this->terminal->update([
            'max_discount_percent' => 25.00,
            'allow_line_discounts' => false,
            'allow_transaction_discounts' => true,
        ]);

        $response = $this->getJson("/api/v1/pos/terminals/{$this->terminal->id}");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(25, $data['max_discount_percent']);
        $this->assertFalse($data['allow_line_discounts']);
        $this->assertTrue($data['allow_transaction_discounts']);
    }

    public function test_max_discount_percent_rejects_values_above_100(): void
    {
        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}", [
            'max_discount_percent' => 101,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.max_discount_percent.0', 'The max discount percent field must not be greater than 100.');
    }

    public function test_max_discount_percent_rejects_negative_values(): void
    {
        $response = $this->patchJson("/api/v1/pos/terminals/{$this->terminal->id}", [
            'max_discount_percent' => -5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.max_discount_percent.0', 'The max discount percent field must be at least 0.');
    }
}
