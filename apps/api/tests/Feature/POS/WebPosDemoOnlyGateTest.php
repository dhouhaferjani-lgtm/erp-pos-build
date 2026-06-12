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
use Tests\TestCase;

/**
 * Web POS demo-only gate (owner decision 2026-06-11 — see
 * docs/superpowers/tickets/2026-06-11-web-pos-demo-only-gate.md).
 *
 * Browser callers may use the fiscal-mutating POS routes ONLY when the
 * tenant is flagged `is_demo`; Tauri device callers (X-Client-Type:
 * pos-tauri) always pass — they mirror device-authored shifts to the same
 * routes. Read-only views stay open for everyone.
 */
final class WebPosDemoOnlyGateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $cashier;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Membership pin so CompanyContextMiddleware passes (NO_COMPANY_ACCESS otherwise).
        UserCompanyMembership::firstOrCreate([
            'user_id' => $this->cashier->id,
            'company_id' => $company->id,
        ], ['role' => 'admin']);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
    }

    public function test_is_demo_defaults_to_false(): void
    {
        $this->assertFalse($this->tenant->fresh()?->is_demo);
    }

    public function test_browser_shift_open_is_rejected_for_real_tenants(): void
    {
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/pos/shifts/open', [
            'terminal_code' => $this->terminal->code,
            'opening_cash' => '100.00',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'WEB_POS_DEMO_ONLY');
    }

    public function test_browser_drawer_and_report_routes_are_rejected_for_real_tenants(): void
    {
        Sanctum::actingAs($this->cashier);

        foreach ([
            ['/api/v1/pos/cash-drawer/deposit', ['amount' => '10.00']],
            ['/api/v1/pos/cash-drawer/payout', ['amount' => '10.00']],
            ['/api/v1/pos/reports/x', []],
            ['/api/v1/pos/reports/z', []],
        ] as [$uri, $body]) {
            $this->postJson($uri, $body)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'WEB_POS_DEMO_ONLY');
        }
    }

    public function test_browser_shift_open_passes_for_demo_tenants(): void
    {
        $this->tenant->update(['is_demo' => true]);
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/pos/shifts/open', [
            'terminal_code' => $this->terminal->code,
            'opening_cash' => '100.00',
        ]);

        // The gate must NOT fire (deeper layers — permissions etc. — may
        // still respond; the contract under test is only the demo gate).
        $this->assertNotSame('WEB_POS_DEMO_ONLY', $response->json('error.code'));
    }

    public function test_tauri_device_passes_regardless_of_demo_flag(): void
    {
        Sanctum::actingAs($this->cashier);

        $response = $this->postJson('/api/v1/pos/shifts/open', [
            'terminal_code' => $this->terminal->code,
            'opening_cash' => '100.00',
        ], ['X-Client-Type' => 'pos-tauri']);

        $this->assertNotSame('WEB_POS_DEMO_ONLY', $response->json('error.code'));
    }

    public function test_read_only_shift_views_are_not_gated_for_real_tenants(): void
    {
        Sanctum::actingAs($this->cashier);

        $response = $this->getJson('/api/v1/pos/reports/z');

        // The demo gate must not apply to read-only views.
        $this->assertNotSame('WEB_POS_DEMO_ONLY', $response->json('error.code'));
    }
}
