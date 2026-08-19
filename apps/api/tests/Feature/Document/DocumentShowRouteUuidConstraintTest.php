<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `GET /api/v1/documents/{document}` binds a raw string straight into
 * `Document::find()` against a PostgreSQL `uuid` primary key. A non-UUID
 * segment therefore raises SQLSTATE[22P02] (invalid text representation) —
 * a 500 — rather than the controller's NOT_FOUND branch. SQLite compares the
 * text happily and hides the divergence, so this test asserts on *route
 * resolution* instead of on the status code alone: the request must never
 * reach the controller, which is observable in the response body.
 *
 * The concrete path that made this reachable is the retired
 * `/inventory/delivery-notes/consolidate` URL, which now falls through to the
 * delivery-note detail page and issues `GET /documents/consolidate`.
 */
final class DocumentShowRouteUuidConstraintTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Document Show Constraint Tenant',
            'slug' => 'document-show-constraint-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Show Constraint Company',
            'legal_name' => 'Document Show Constraint Company SARL',
            'tax_id' => 'DSC-TAX',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Document Show Constraint User',
            'email' => 'document-show-constraint@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        $this->user->givePermissionTo(['documents.view']);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_non_uuid_document_segment_is_rejected_by_the_route_and_never_reaches_the_controller(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/documents/consolidate');

        $response->assertNotFound();

        // The controller's own miss returns `error.code = NOT_FOUND`. Its absence
        // is the proof that the route constraint rejected the segment first, so
        // no non-UUID string is ever compared against the `uuid` column.
        $this->assertArrayNotHasKey(
            'error',
            (array) $response->json(),
            'GET /api/v1/documents/consolidate reached DocumentController::showAny; on PostgreSQL that is a 500, not a 404.',
        );
    }

    public function test_a_well_formed_uuid_still_reaches_the_controller_not_found_branch(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/documents/'.Str::uuid()->toString());

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
