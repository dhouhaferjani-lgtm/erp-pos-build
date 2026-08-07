<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Route gating (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md
 * #3, §106-174): the withholding CERTIFICATE routes carried no authorization
 * middleware at all, and the sibling withholding RULES group was equally
 * ungated — `deactivate`/`destroy` had NO authorization check whatsoever
 * (any authenticated tenant user could deactivate/delete a tax-rate rule).
 * This is the deny-path coverage for BOTH groups, per role, matching
 * docs/conventions/03-AUTHORIZATION.md's `can:` middleware pattern already
 * used by the neighbouring `sales-withholding` and `taxation/configurations`
 * groups.
 *
 * Every mutating probe deliberately submits an INVALID payload (missing
 * required fields / too-short reason) so a 403 here proves refusal happened
 * BEFORE the FormRequest ever validated it — the same methodology the
 * ticket itself used to prove the pre-fix absence of a gate.
 *
 * Assertions pin `error.code === 'FORBIDDEN'`, NOT `error.ability`: the
 * global handler (bootstrap/app.php) only populates `error.ability` when the
 * denial was raised via the `AuthorizesAbility` trait's `PermissionDeniedException`
 * (e.g. `UomController`) — route-level `can:` middleware (the pattern used
 * here, matching `sales-withholding`/`taxation/configurations`) throws
 * Laravel's plain `AuthorizationException`, for which `error.ability` is
 * always `null` by design.
 */
final class WithholdingRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private WithholdingCertificate $certificate;

    private WithholdingTaxRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Withholding Authz Tenant',
            'slug' => 'withholding-authz-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Withholding Authz Co',
            'legal_name' => 'Withholding Authz Co LLC',
            'tax_id' => 'TAX-WHT-AUTHZ',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Withholding Authz Partner',
            'type' => PartnerType::Supplier,
            'is_active' => true,
        ]);

        $this->certificate = WithholdingCertificate::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'direction' => WithholdingDirection::PURCHASE,
            'currency' => 'TND',
            'gross_amount' => '1000.000',
            'certificate_number' => 'WHT-AUTHZ-'.substr(Str::uuid()->toString(), 0, 8),
            'withholding_rate' => '0.1000',
            'year' => (int) now()->year,
            'withholding_amount' => '100.000',
            'net_amount' => '900.000',
            'status' => CertificateStatus::DRAFT,
        ]);

        $this->rule = WithholdingTaxRule::create([
            'id' => Str::uuid()->toString(),
            'country_code' => 'TN',
            'company_id' => $this->company->id,
            'code' => 'AUTHZ_TN_10',
            'name' => 'Authz Tunisia 10%',
            'rate' => '10.00',
            'effective_from' => now()->subYear(),
            'is_active' => true,
        ]);
    }

    private function userWithRole(string $role, string $email): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => ucfirst($role).' Probe User',
            'email' => $email,
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::from($role),
        ]);

        return $user;
    }

    private function actingAsRole(string $role, string $email): self
    {
        $user = $this->userWithRole($role, $email);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id);
    }

    // ────────────────────────────────────────────────────────────────
    // Certificates group — cashier has NO withholding.* permission at all
    // (mirrors the ticket's own `cashier@pharmabio.tn` reproduction).
    // ────────────────────────────────────────────────────────────────

    public function test_cashier_is_refused_reading_the_certificate_list(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-index@example.com')
            ->getJson('/api/v1/withholding/certificates');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_reading_a_certificate(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-show@example.com')
            ->getJson("/api/v1/withholding/certificates/{$this->certificate->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_the_batch_tej_export(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-batch@example.com')
            ->getJson('/api/v1/withholding/certificates/export-tej-batch');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_downloading_the_certificate_pdf(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-pdf@example.com')
            ->getJson("/api/v1/withholding/certificates/{$this->certificate->id}/download-pdf");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_downloading_the_certificate_tej_xml(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-xml@example.com')
            ->getJson("/api/v1/withholding/certificates/{$this->certificate->id}/download-tej-xml");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_creating_a_certificate_before_validation_runs(): void
    {
        // Deliberately empty/invalid payload — a `can:` gate must 403 BEFORE
        // CreateWithholdingCertificateRequest ever validates it.
        $response = $this->actingAsRole('cashier', 'cashier-cert-store@example.com')
            ->postJson('/api/v1/withholding/certificates', []);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertDatabaseCount('withholding_certificates', 1); // only the seeded fixture
    }

    public function test_cashier_is_refused_issuing_a_certificate(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-issue@example.com')
            ->postJson("/api/v1/withholding/certificates/{$this->certificate->id}/issue");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame(CertificateStatus::DRAFT, $this->certificate->fresh()?->status);
    }

    public function test_cashier_is_refused_voiding_a_certificate_before_validation_runs(): void
    {
        // Deliberately too-short reason (VoidCertificateRequest requires
        // min:10) — a `can:` gate must 403 BEFORE that validation runs.
        $response = $this->actingAsRole('cashier', 'cashier-cert-void@example.com')
            ->postJson("/api/v1/withholding/certificates/{$this->certificate->id}/void", [
                'reason' => 'short',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame(CertificateStatus::DRAFT, $this->certificate->fresh()?->status);
    }

    public function test_cashier_is_refused_submitting_a_certificate_to_tej(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-tej@example.com')
            ->postJson("/api/v1/withholding/certificates/{$this->certificate->id}/submit-tej", []);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_deleting_a_certificate(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-cert-destroy@example.com')
            ->deleteJson("/api/v1/withholding/certificates/{$this->certificate->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertNotNull($this->certificate->fresh(), 'certificate must NOT have been deleted');
    }

    // ────────────────────────────────────────────────────────────────
    // Certificates group — granular grants (manager: view only; accountant:
    // view/create/update but NOT delete) must be honoured, not collapsed to
    // all-or-nothing.
    // ────────────────────────────────────────────────────────────────

    public function test_manager_can_read_the_certificate_list_but_not_create_or_delete(): void
    {
        $index = $this->actingAsRole('manager', 'manager-cert-index@example.com')
            ->getJson('/api/v1/withholding/certificates');
        $index->assertStatus(200);

        $create = $this->actingAsRole('manager', 'manager-cert-store@example.com')
            ->postJson('/api/v1/withholding/certificates', []);
        $create->assertStatus(403);
        $create->assertJsonPath('error.code', 'FORBIDDEN');

        $delete = $this->actingAsRole('manager', 'manager-cert-destroy@example.com')
            ->deleteJson("/api/v1/withholding/certificates/{$this->certificate->id}");
        $delete->assertStatus(403);
        $delete->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_accountant_can_read_and_issue_but_not_delete_a_certificate(): void
    {
        $index = $this->actingAsRole('accountant', 'accountant-cert-index@example.com')
            ->getJson('/api/v1/withholding/certificates');
        $index->assertStatus(200);

        $issue = $this->actingAsRole('accountant', 'accountant-cert-issue@example.com')
            ->postJson("/api/v1/withholding/certificates/{$this->certificate->id}/issue");
        $issue->assertStatus(200);

        $delete = $this->actingAsRole('accountant', 'accountant-cert-destroy@example.com')
            ->deleteJson("/api/v1/withholding/certificates/{$this->certificate->id}");
        $delete->assertStatus(403);
        $delete->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // ────────────────────────────────────────────────────────────────
    // Rules group (§153-174 scope widening) — `deactivate`/`destroy` had NO
    // authorization check at all pre-fix. cashier AND manager both lack
    // `taxation.withholding_rules.manage`.
    // ────────────────────────────────────────────────────────────────

    public function test_cashier_is_refused_reading_the_rules_list(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-rule-index@example.com')
            ->getJson('/api/v1/withholding/rules');

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_reading_a_rule(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-rule-show@example.com')
            ->getJson("/api/v1/withholding/rules/{$this->rule->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_creating_a_rule_before_validation_runs(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-rule-store@example.com')
            ->postJson('/api/v1/withholding/rules', []);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_cashier_is_refused_updating_a_rule(): void
    {
        $response = $this->actingAsRole('cashier', 'cashier-rule-update@example.com')
            ->patchJson("/api/v1/withholding/rules/{$this->rule->id}", [
                'rate' => '0.20',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertSame('10.0000', $this->rule->fresh()?->rate, 'rule rate must NOT have been mutated');
    }

    public function test_cashier_is_refused_deactivating_a_rule(): void
    {
        // Pre-fix (ticket §153-174): `deactivate` had NO authorization check
        // at all — ANY authenticated tenant user could deactivate a rule.
        $response = $this->actingAsRole('cashier', 'cashier-rule-deactivate@example.com')
            ->postJson("/api/v1/withholding/rules/{$this->rule->id}/deactivate");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertTrue(
            (bool) $this->rule->fresh()?->is_active,
            'rule must NOT have been deactivated'
        );
    }

    public function test_cashier_is_refused_deleting_a_rule(): void
    {
        // Pre-fix (ticket §153-174): `destroy` had NO authorization check at
        // all — ANY authenticated tenant user could delete a tax-rate rule.
        $response = $this->actingAsRole('cashier', 'cashier-rule-destroy@example.com')
            ->deleteJson("/api/v1/withholding/rules/{$this->rule->id}");

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertNotNull($this->rule->fresh(), 'rule must NOT have been deleted');
    }

    public function test_manager_is_also_refused_deactivating_and_deleting_a_rule(): void
    {
        // The manager role has broad operational permissions but never had
        // (and must not get) `taxation.withholding_rules.manage` — proving
        // the gate isn't just "cashier happens to be excluded".
        $deactivate = $this->actingAsRole('manager', 'manager-rule-deactivate@example.com')
            ->postJson("/api/v1/withholding/rules/{$this->rule->id}/deactivate");
        $deactivate->assertStatus(403);
        $deactivate->assertJsonPath('error.code', 'FORBIDDEN');

        $destroy = $this->actingAsRole('manager', 'manager-rule-destroy@example.com')
            ->deleteJson("/api/v1/withholding/rules/{$this->rule->id}");
        $destroy->assertStatus(403);
        $destroy->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertTrue((bool) $this->rule->fresh()?->is_active);
        $this->assertNotNull($this->rule->fresh());
    }

    // ────────────────────────────────────────────────────────────────
    // Positive control — accountant DOES hold
    // `taxation.withholding_rules.manage` and must keep working.
    // ────────────────────────────────────────────────────────────────

    public function test_accountant_can_still_read_the_rules_list(): void
    {
        $response = $this->actingAsRole('accountant', 'accountant-rule-index@example.com')
            ->getJson('/api/v1/withholding/rules');

        $response->assertStatus(200);
    }
}
