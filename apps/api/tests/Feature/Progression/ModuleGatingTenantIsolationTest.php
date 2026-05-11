<?php

declare(strict_types=1);

namespace Tests\Feature\Progression;

use App\Http\Middleware\CompanyContextMiddleware;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Tenant\Application\Services\OnboardingChecklistService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * api.module-gating cluster — RED anchor regression tests.
 *
 * Closes two cluster invariants:
 *   (a-1) data-scoping invariant: companyId controls which records
 *         are read/written. Raw-header trust = direct cross-tenant
 *         data leak. Tested via service-call arg capture.
 *   (a-2) audit-attribution invariant: companyId is recorded in
 *         AuditEvent for forensic reconstruction. Raw-header trust =
 *         audit-trail evidence-tampering risk. Tested via DB-row
 *         assertion on the persisted AuditEvent.
 *
 * Per the kickoff "test honesty" requirement, these tests
 * specifically exercise the CompanyContextMiddleware-BYPASSED flow
 * with a mock CompanyContext returning company A and a malicious
 * X-Company-Id header pointing to company B. The structural
 * protection by the middleware is tested elsewhere; this file's
 * purpose is to prove that controllers pin to the validated
 * CompanyContext source rather than the raw header even WITHOUT
 * middleware help — the regression invariant against future
 * middleware re-ordering.
 *
 * Naive tests that rely on the natural middleware-validated flow
 * pass vacuously today: with middleware running, header reads and
 * context reads return the same value (the validated id). Only the
 * bypassed flow distinguishes the two source paths.
 */
final class ModuleGatingTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-mg',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-mg',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-MG',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-MG',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->adminA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Admin A',
            'email' => 'admin-a-mg@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->adminA->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->adminA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
    }

    /**
     * Bypass CompanyContextMiddleware AND bind a CompanyContext mock
     * that returns the validated companyA + tenantA. This isolates
     * controller source-pin behavior from middleware help — the
     * regression test for future middleware re-ordering.
     */
    private function bindMockCompanyContext(): void
    {
        $this->withoutMiddleware([CompanyContextMiddleware::class]);

        $mock = $this->createMock(CompanyContext::class);
        $mock->method('requireCompanyId')->willReturn($this->companyA->id);
        $mock->method('requireTenantId')->willReturn($this->tenantA->id);
        $mock->method('getCompanyId')->willReturn($this->companyA->id);
        $mock->method('hasCompany')->willReturn(true);
        $this->app->instance(CompanyContext::class, $mock);
    }

    /**
     * Bind a Growth Advisor client mock that asserts it received the
     * validated companyId arg (companyA), not the malicious header
     * value (companyB). On dev tip, the controller forwards the raw
     * header to the service which forwards to the client — the
     * `with()` predicate fails. On fixed tip, the controller pulls
     * from CompanyContext — the mock receives companyA as expected.
     *
     * @return MockObject&GrowthAdvisorClientInterface
     */
    private function bindMockGrowthAdvisorClient(string $expectedCompanyId): MockObject
    {
        $mock = $this->createMock(GrowthAdvisorClientInterface::class);
        $mock->method('isCircuitOpen')->willReturn(false);
        $this->app->instance(GrowthAdvisorClientInterface::class, $mock);

        return $mock;
    }

    // ──────────────────────────────────────────────────────────────────
    // Progression controllers (a-1) — 8 method-level callsites
    // ──────────────────────────────────────────────────────────────────

    public function test_module_readiness_index_pins_company_id_to_company_context_not_header(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);
        $client->expects($this->once())
            ->method('getModules')
            ->with($this->companyA->id) // MUST be A (context), NOT B (header)
            ->willReturn([]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id) // malicious
            ->getJson('/api/v1/progression/modules');

        $response->assertOk();
    }

    public function test_module_readiness_activate_pins_company_id_to_company_context_not_header(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);
        $client->expects($this->once())
            ->method('activateModule')
            ->with($this->companyA->id, 'mod-pos')
            ->willReturn([
                'id' => 'mod-pos', 'name' => 'POS', 'description' => 'Point of Sale',
                'icon' => 'pos', 'status' => 'active', 'readiness_percent' => 100,
                'stage' => 'launch', 'discount_percent' => 0, 'requirements' => [],
            ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/progression/modules/mod-pos/activate');

        $response->assertOk();
    }

    public function test_recommendation_index_pins_company_id_to_company_context_not_header(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);
        $client->expects($this->once())
            ->method('getRecommendations')
            ->with($this->companyA->id)
            ->willReturn([]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->getJson('/api/v1/progression/recommendations');

        $response->assertOk();
    }

    public function test_recommendation_accept_pins_company_id_to_company_context_not_header(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);
        $client->expects($this->once())
            ->method('acceptRecommendation')
            ->with($this->companyA->id, 'rec-1')
            ->willReturn([
                'id' => 'rec-1', 'title' => 'T', 'description' => 'D',
                'priority' => 'high', 'action_label' => 'Go', 'action_route' => '/x',
                'status' => 'accepted',
            ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/progression/recommendations/rec-1/accept');

        $response->assertOk();
    }

    public function test_recommendation_dismiss_pins_company_id_to_company_context_not_header(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);
        $client->expects($this->once())
            ->method('dismissRecommendation')
            ->with($this->companyA->id, 'rec-1')
            ->willReturn([
                'id' => 'rec-1', 'title' => 'T', 'description' => 'D',
                'priority' => 'high', 'action_label' => 'Go', 'action_route' => '/x',
                'status' => 'dismissed',
            ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/progression/recommendations/rec-1/dismiss');

        $response->assertOk();
    }

    public function test_company_progression_show_pins_company_id_to_company_context_not_header(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);
        $client->expects($this->once())
            ->method('getCompanyProfile')
            ->with($this->companyA->id)
            ->willReturn([
                'id' => $this->companyA->id, // matches expected — no response-id throw
                'tenant_id' => $this->tenantA->id,
                'vertical' => 'pos', 'country' => 'FR',
                'current_stage' => 'launch', 'stage_progress_percent' => 50,
                'total_milestones' => 10, 'completed_milestones' => 5,
            ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->getJson('/api/v1/progression/profile');

        $response->assertOk();
    }

    public function test_company_progression_register_pins_both_ids_to_company_context_not_headers(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);

        // CompanyProgressionController::register reads BOTH X-Company-Id (line 45)
        // AND X-Tenant-Id (line 48). Both must derive from CompanyContext post-fix.
        $client->expects($this->once())
            ->method('registerCompany')
            ->with($this->callback(function (array $data): bool {
                return ($data['company_id'] ?? null) === $this->companyA->id
                    && ($data['tenant_id'] ?? null) === $this->tenantA->id;
            }))
            ->willReturn([
                'id' => $this->companyA->id,
                'tenant_id' => $this->tenantA->id,
                'vertical' => 'pos', 'country' => 'FR',
                'current_stage' => 'launch', 'stage_progress_percent' => 0,
                'total_milestones' => 10, 'completed_milestones' => 0,
            ]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeaders([
            'X-Company-Id' => $this->companyB->id, // malicious company
            'X-Tenant-Id' => $this->tenantB->id,   // malicious tenant
        ])->postJson('/api/v1/progression/register');

        $response->assertCreated();
    }

    public function test_company_progression_milestones_pins_company_id_to_company_context_not_header(): void
    {
        $this->bindMockCompanyContext();
        $client = $this->bindMockGrowthAdvisorClient($this->companyA->id);
        $client->expects($this->once())
            ->method('getMilestones')
            ->with($this->companyA->id)
            ->willReturn([]);

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->getJson('/api/v1/progression/milestones');

        $response->assertOk();
    }

    // ──────────────────────────────────────────────────────────────────
    // Tenant/Onboarding (a-1) — 1 callsite
    // ──────────────────────────────────────────────────────────────────

    public function test_onboarding_status_pins_company_id_to_company_context_not_header(): void
    {
        // OnboardingChecklistService is final and cannot be doubled by either
        // Mockery or PHPUnit 11's createMock. Behavioral path instead:
        // delete companyB so the malicious-header read resolves to a null
        // Company in OnboardingChecklistService::checkCompanyInfo; companyA
        // remains and has both name + tax_id set, so the company_info step's
        // `completed` flag differs between dev (header=B → null company →
        // false) and fixed (context=A → companyA → true).
        UserCompanyMembership::where('company_id', $this->companyB->id)->delete();
        $maliciousId = $this->companyB->id;
        $this->companyB->delete();

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $maliciousId) // malicious + now-deleted
            ->getJson('/api/v1/onboarding/status');

        $response->assertOk();

        $companyInfoStep = collect($response->json('data'))->firstWhere('step', 'company_info');
        $this->assertNotNull($companyInfoStep, 'company_info step must be present in response.');
        $this->assertTrue(
            (bool) $companyInfoStep['completed'],
            'company_info step must reflect companyA (CompanyContext-resolved with name+tax_id), not the malicious header pointing to a deleted company.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Tenant/CompanySettings (a-2) — 3 callsites; audit-attribution
    // ──────────────────────────────────────────────────────────────────

    public function test_company_settings_update_audit_records_validated_company_id_not_malicious_header(): void
    {
        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id) // malicious
            ->patchJson('/api/v1/settings/company', [
                'name' => 'Renamed Tenant A',
            ]);

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'tenant.settings_updated')
            ->latest()
            ->first();

        $this->assertNotNull($audit, 'tenant.settings_updated audit row must be created.');
        $this->assertSame(
            $this->companyA->id,
            $audit->company_id,
            'AuditEvent.company_id must reflect CompanyContext (companyA), not malicious header (companyB).',
        );
    }

    public function test_company_settings_upload_logo_audit_records_validated_company_id_not_malicious_header(): void
    {
        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/settings/company/logo', [
                'logo' => $file,
            ]);

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'tenant.logo_updated')
            ->latest()
            ->first();

        $this->assertNotNull($audit, 'tenant.logo_updated audit row must be created.');
        $this->assertSame(
            $this->companyA->id,
            $audit->company_id,
            'AuditEvent.company_id must reflect CompanyContext (companyA), not malicious header (companyB).',
        );
    }

    public function test_company_settings_delete_logo_audit_records_validated_company_id_not_malicious_header(): void
    {
        // Pre-seed a logo so deleteLogo has something to delete.
        $this->tenantA->update(['logo_path' => 'logos/'.$this->tenantA->id.'/seed.png']);

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->deleteJson('/api/v1/settings/company/logo');

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'tenant.logo_deleted')
            ->latest()
            ->first();

        $this->assertNotNull($audit, 'tenant.logo_deleted audit row must be created.');
        $this->assertSame(
            $this->companyA->id,
            $audit->company_id,
            'AuditEvent.company_id must reflect CompanyContext (companyA), not malicious header (companyB).',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Identity/UserController (a-2) — 8 callsites; audit-attribution
    // Representative coverage: store, update, destroy, activate, deactivate,
    // setPosPin (set + clear), resetPassword. One test per method.
    // ──────────────────────────────────────────────────────────────────

    private function makeTargetUser(UserStatus $status = UserStatus::Active): User
    {
        $email = 'target-'.uniqid('', true).'@example.com';
        $target = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Target User',
            'email' => $email,
            'password' => 'password123',
            'status' => $status,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $target->assignRole('cashier');

        return $target;
    }

    public function test_user_store_audit_records_validated_company_id_not_malicious_header(): void
    {
        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/users', [
                'name' => 'New Cashier',
                'email' => 'new-cashier-mg@example.com',
                'role' => 'cashier',
            ]);

        $response->assertCreated();

        $audit = AuditEvent::where('event_type', 'user.created')->latest()->first();
        $this->assertNotNull($audit, 'user.created audit row must be created.');
        $this->assertSame(
            $this->companyA->id,
            $audit->company_id,
            'AuditEvent.company_id must reflect CompanyContext (companyA), not malicious header (companyB).',
        );
    }

    public function test_user_update_audit_records_validated_company_id_not_malicious_header(): void
    {
        $target = $this->makeTargetUser();

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->patchJson('/api/v1/users/'.$target->id, [
                'name' => 'Renamed Target',
            ]);

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'user.updated')
            ->where('aggregate_id', $target->id)
            ->latest()
            ->first();
        $this->assertNotNull($audit, 'user.updated audit row must be created.');
        $this->assertSame($this->companyA->id, $audit->company_id);
    }

    public function test_user_destroy_audit_records_validated_company_id_not_malicious_header(): void
    {
        $target = $this->makeTargetUser();

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->deleteJson('/api/v1/users/'.$target->id);

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'user.deleted')
            ->where('aggregate_id', $target->id)
            ->latest()
            ->first();
        $this->assertNotNull($audit, 'user.deleted audit row must be created.');
        $this->assertSame($this->companyA->id, $audit->company_id);
    }

    public function test_user_activate_audit_records_validated_company_id_not_malicious_header(): void
    {
        $target = $this->makeTargetUser(UserStatus::PendingVerification);

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/users/'.$target->id.'/activate');

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'user.activated')
            ->where('aggregate_id', $target->id)
            ->latest()
            ->first();
        $this->assertNotNull($audit, 'user.activated audit row must be created.');
        $this->assertSame($this->companyA->id, $audit->company_id);
    }

    public function test_user_deactivate_audit_records_validated_company_id_not_malicious_header(): void
    {
        $target = $this->makeTargetUser();

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/users/'.$target->id.'/deactivate');

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'user.deactivated')
            ->where('aggregate_id', $target->id)
            ->latest()
            ->first();
        $this->assertNotNull($audit, 'user.deactivated audit row must be created.');
        $this->assertSame($this->companyA->id, $audit->company_id);
    }

    public function test_user_set_pos_pin_audit_records_validated_company_id_not_malicious_header(): void
    {
        $target = $this->makeTargetUser();

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->patchJson('/api/v1/users/'.$target->id.'/pos-pin', [
                'pin' => '1234',
            ]);

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'user.pos_pin_set')
            ->where('aggregate_id', $target->id)
            ->latest()
            ->first();
        $this->assertNotNull($audit, 'user.pos_pin_set audit row must be created.');
        $this->assertSame($this->companyA->id, $audit->company_id);
    }

    public function test_user_clear_pos_pin_audit_records_validated_company_id_not_malicious_header(): void
    {
        $target = $this->makeTargetUser();
        $target->update(['pos_pin' => Hash::make('5678')]);

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->patchJson('/api/v1/users/'.$target->id.'/pos-pin', [
                'pin' => null,
            ]);

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'user.pos_pin_cleared')
            ->where('aggregate_id', $target->id)
            ->latest()
            ->first();
        $this->assertNotNull($audit, 'user.pos_pin_cleared audit row must be created.');
        $this->assertSame($this->companyA->id, $audit->company_id);
    }

    public function test_user_reset_password_audit_records_validated_company_id_not_malicious_header(): void
    {
        $target = $this->makeTargetUser();

        // ResetPassword notification's toMail() resolves route('password.reset', …),
        // which is not registered on the api-only test boot. Faking notifications
        // captures the dispatch without rendering the URL. Audit-event recording
        // happens before the notify() call, so the AuditEvent assertion is
        // unaffected by the fake.
        Notification::fake();

        $this->bindMockCompanyContext();

        Sanctum::actingAs($this->adminA);

        $response = $this->withHeader('X-Company-Id', $this->companyB->id)
            ->postJson('/api/v1/users/'.$target->id.'/reset-password');

        $response->assertOk();

        $audit = AuditEvent::where('event_type', 'user.password_reset_triggered')
            ->where('aggregate_id', $target->id)
            ->latest()
            ->first();
        $this->assertNotNull($audit, 'user.password_reset_triggered audit row must be created.');
        $this->assertSame($this->companyA->id, $audit->company_id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
