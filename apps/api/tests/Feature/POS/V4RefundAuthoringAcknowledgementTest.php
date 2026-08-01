<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use App\Modules\POS\Domain\Exceptions\LegacyCorrectionRetiredException;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §9.3 Phase 2 — the device's
 * acknowledgement endpoint.
 *
 * **Wave-2 fix-wave finding 9 (fiscal C-6).** The device has been posting
 * `POST /pos/terminals/{id}/acknowledge-v4-refund-authoring` since wave 2
 * (`syncService.ts`), but no such route existed: a repo-wide grep found
 * only the device call site, and the 404 was downgraded to a
 * `console.warn`. `pos_terminals.v4_refund_authoring_acknowledged_at`
 * therefore stayed NULL forever and {@see LegacyCorrectionGuard} — which
 * conditions on exactly that column — could NEVER fire.
 *
 * Consequence: a terminal correctly routed to the v4 flow device-side
 * while the legacy `/return` endpoint stayed open for it PERMANENTLY —
 * the exact endpoint whose chain-corruption failure mode (§1) this entire
 * lane exists to lock out. Any other client (web POS, a stale device
 * build, a manual call) could reproduce §1 against a live v4 terminal, and
 * §9.5 step 4 of the rollout could never be marked complete. §9.3 budgeted
 * "one ordinary sync cycle" of exposure; as shipped it was unbounded and
 * unobservable.
 */
final class V4RefundAuthoringAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->for($this->company)->create();

        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => MembershipStatus::Active,
        ]);

        // SetPermissionsTeam scopes Spatie's team to the user's TENANT.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->cashier->givePermissionTo('pos.operate_terminal');

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    private function makeTerminal(bool $enabled, ?string $acknowledgedAt = null): Terminal
    {
        return Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'v4_refund_authoring_enabled' => $enabled,
            'v4_refund_authoring_acknowledged_at' => $acknowledgedAt,
        ]);
    }

    private function acknowledge(Terminal $terminal): TestResponse
    {
        return $this->postJson(
            "/api/v1/pos/terminals/{$terminal->id}/acknowledge-v4-refund-authoring"
        );
    }

    public function test_acknowledgement_stamps_the_column_and_activates_the_legacy_correction_guard(): void
    {
        $terminal = $this->makeTerminal(enabled: true);
        Sanctum::actingAs($this->cashier);

        // BEFORE: the guard is inert — the legacy correction path is open.
        $guard = app(LegacyCorrectionGuard::class);
        $guard->assertLegacyCorrectionAllowed($terminal->fresh());
        self::assertNull($terminal->fresh()->v4_refund_authoring_acknowledged_at);

        $response = $this->acknowledge($terminal);

        $response->assertOk();
        $fresh = $terminal->fresh();
        self::assertNotNull($fresh->v4_refund_authoring_acknowledged_at);
        $response->assertJsonPath(
            'data.v4_refund_authoring_acknowledged_at',
            $fresh->v4_refund_authoring_acknowledged_at?->toISOString(),
        );

        // AFTER: the guard fires — the legacy correction path is retired
        // for this terminal. THIS is the assertion the whole endpoint
        // exists for.
        $this->expectException(LegacyCorrectionRetiredException::class);
        $guard->assertLegacyCorrectionAllowed($fresh);
    }

    public function test_acknowledgement_is_idempotent_and_never_moves_an_existing_timestamp(): void
    {
        $terminal = $this->makeTerminal(enabled: true);
        Sanctum::actingAs($this->cashier);

        $this->acknowledge($terminal)->assertOk();
        $first = $terminal->fresh()->v4_refund_authoring_acknowledged_at;
        self::assertNotNull($first);

        // The device re-acknowledges on EVERY successful pull while
        // `enabled` stays true (§9.3), so a second call must be a no-op —
        // never a moving target for the rollout audit trail.
        $this->acknowledge($terminal)->assertOk();

        self::assertSame(
            $first->toISOString(),
            $terminal->fresh()->v4_refund_authoring_acknowledged_at?->toISOString(),
        );
    }

    public function test_acknowledging_a_terminal_that_was_never_offered_the_capability_is_refused(): void
    {
        // Phase 2 acknowledges Phase 1. A device cannot retire its own
        // legacy correction path without the server having offered the
        // capability first — that would be a client-driven retirement of a
        // server-owned rollout gate.
        $terminal = $this->makeTerminal(enabled: false);
        Sanctum::actingAs($this->cashier);

        $this->acknowledge($terminal)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'V4_REFUND_AUTHORING_NOT_OFFERED');

        self::assertNull($terminal->fresh()->v4_refund_authoring_acknowledged_at);
        // The guard stays inert — the legacy path is still available.
        app(LegacyCorrectionGuard::class)->assertLegacyCorrectionAllowed($terminal->fresh());
    }

    public function test_the_route_exists_and_requires_authentication(): void
    {
        // Guards the regression this whole test file exists for: the route
        // must be REACHABLE. An anonymous caller gets 401 from
        // auth:sanctum — never the 404 that silently disabled the entire
        // two-phase protocol.
        $terminal = $this->makeTerminal(enabled: true);

        $this->acknowledge($terminal)->assertStatus(401);
    }

    public function test_a_terminal_from_another_company_is_not_found(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherLocation = Location::factory()->for($otherCompany)->create();
        $foreign = Terminal::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
            'v4_refund_authoring_enabled' => true,
        ]);

        Sanctum::actingAs($this->cashier);

        $this->acknowledge($foreign)->assertStatus(404);
        self::assertNull($foreign->fresh()->v4_refund_authoring_acknowledged_at);
    }
}
