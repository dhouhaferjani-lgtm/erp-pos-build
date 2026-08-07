<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting\Reports;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ticket 2026-08-06-l3-cash-scope-residuals.md (b), P2.
 *
 * `agedReceivables` / `agedPayables` / `upcomingPayments` used to call
 * `reportLocationScope()` INSIDE their `catch (\Exception $e)` block.
 * `LocationScopeResolver::resolve()` throws `AuthorizationException` (which
 * extends `\Exception`) for a location outside the principal's grant, so the
 * refusal was swallowed into a REPORT_GENERATION_ERROR 500 that also echoed
 * the resolver's internal message text in the response body. `cashMovements`
 * already gets this right — its `reportLocationScope()` call is deliberately
 * outside the catch — so this suite pins the same clean 403 contract on the
 * other three.
 */
final class ReportLocationScopeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const LEAKED_RESOLVER_MESSAGE = 'Requested location is outside your allowed scope.';

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $locationA;

    private Location $locationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->locationA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Shop A']);
        $this->locationB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Shop B']);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
            'allowed_location_ids' => [$this->locationA->id],
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('reports.operational', 'sanctum');
        $this->user->givePermissionTo('reports.operational');

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_aged_receivables_refuses_an_out_of_scope_location_with_a_clean_403(): void
    {
        $this->assertCleanForbidden(
            "/api/v1/reports/aged-receivables?location_ids[]={$this->locationB->id}"
        );
    }

    public function test_aged_payables_refuses_an_out_of_scope_location_with_a_clean_403(): void
    {
        $this->assertCleanForbidden(
            "/api/v1/reports/aged-payables?location_ids[]={$this->locationB->id}"
        );
    }

    public function test_upcoming_payments_refuses_an_out_of_scope_location_with_a_clean_403(): void
    {
        $this->assertCleanForbidden(
            "/api/v1/reports/upcoming-payments?location_ids[]={$this->locationB->id}"
        );
    }

    private function assertCleanForbidden(string $url): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson($url);

        $response->assertForbidden();
        $response->assertJsonStructure(['error' => ['code', 'message', 'ability']]);
        $response->assertJsonPath('error.code', 'FORBIDDEN');

        $body = $response->getContent();
        self::assertIsString($body);
        self::assertStringNotContainsString(
            self::LEAKED_RESOLVER_MESSAGE,
            $body,
            'The 403 body must not echo LocationScopeResolver\'s internal exception message.',
        );
        self::assertStringNotContainsString(
            'REPORT_GENERATION_ERROR',
            $body,
            'An out-of-scope location must never surface as a 500 REPORT_GENERATION_ERROR.',
        );
    }
}
