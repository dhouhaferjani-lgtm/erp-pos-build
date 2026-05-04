<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Payment;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.taxation cluster) — tenant-isolation regression coverage.
 *
 * The api.taxation cluster has 13 inventoried callsites:
 *
 *   FormRequest validators (6 inline `exists:` rules across 3 FormRequests):
 *     - api.taxation.001  CreateWithholdingCertificateRequest  partner_id  -> partners
 *     - api.taxation.002  CreateWithholdingCertificateRequest  document_id -> documents
 *     - api.taxation.003  CreateWithholdingCertificateRequest  payment_id  -> payments
 *     - api.taxation.004  RecordSalesWithholdingRequest        customer_id -> partners
 *     - api.taxation.005  RecordSalesWithholdingRequest        payment_id  -> payments
 *     - api.taxation.006  CalculateWithholdingRequest          partner_id  -> partners
 *
 *   TaxConfiguration callsites (4; tax_configurations is a country-scoped
 *   GLOBAL REFERENCE table with no tenant_id / company_id columns —
 *   `country_code` is the only viable scope; cross-tenant rows in the SAME
 *   country are by-design shareable across tenants):
 *     - api.taxation.007  TaxConfigurationController::reorder  inline validator -> tax_configurations
 *     - api.taxation.008  TaxConfigurationController::show     TaxConfiguration::findOrFail
 *     - api.taxation.009  TaxConfigurationController::update   TaxConfiguration::findOrFail
 *     - api.taxation.010  TaxConfigurationController::destroy  TaxConfiguration::findOrFail
 *
 *   Per-tenant findOrFail (3):
 *     - api.taxation.011  SalesWithholdingTrackingController::recordWithholding  Document::findOrFail
 *     - api.taxation.012  WithholdingPreviewController::preview                  Partner::findOrFail
 *     - api.taxation.013  WithholdingCertificateController::store                Partner::findOrFail
 *
 * Schema:
 *   - partners:           tenant_id + company_id (BOTH required predicates)
 *   - documents:          tenant_id + company_id
 *   - payments:           tenant_id + company_id
 *   - tax_configurations: country_code only (GLOBAL REFERENCE; per
 *                         master-plan kickoff brief: annotate as
 *                         structurally_protected_by_country_scoped_reference
 *                         rather than forcing a tenant_id predicate that
 *                         would break FK semantics)
 */
final class TaxationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Partner $partnerA;

    private Partner $partnerB;

    private Document $documentA;

    private Document $documentB;

    private Payment $paymentB;

    private TaxConfiguration $taxConfigFR;

    private TaxConfiguration $taxConfigTN;

    protected function setUp(): void
    {
        parent::setUp();

        // tax_configurations.country_code FK requires countries seeded.
        $this->seed(CountriesSeeder::class);

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-taxation-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-taxation-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-TAXATION',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        // Tenant-B in a different country so the country-scoped
        // TaxConfiguration test pins SAME-country leakage (NOT cross-country).
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-TAXATION',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        // The taxation.* permissions are not in the canonical seeder —
        // register what the routes need so the test exercises isolation,
        // not a missing-permission gate.
        Permission::findOrCreate('taxation.view', 'sanctum');
        Permission::findOrCreate('taxation.manage', 'sanctum');
        Permission::findOrCreate('withholding.view', 'sanctum');
        Permission::findOrCreate('withholding.manage', 'sanctum');
        Permission::findOrCreate('tax-config.view', 'sanctum');
        Permission::findOrCreate('tax-config.manage', 'sanctum');
        Permission::findOrCreate('taxation.withholding_rules.manage', 'sanctum');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        Permission::findOrCreate('taxation.view', 'sanctum');
        Permission::findOrCreate('taxation.manage', 'sanctum');
        Permission::findOrCreate('withholding.view', 'sanctum');
        Permission::findOrCreate('withholding.manage', 'sanctum');
        Permission::findOrCreate('tax-config.view', 'sanctum');
        Permission::findOrCreate('tax-config.manage', 'sanctum');
        Permission::findOrCreate('taxation.withholding_rules.manage', 'sanctum');

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-taxation-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');
        $this->userA->givePermissionTo([
            'taxation.view', 'taxation.manage',
            'withholding.view', 'withholding.manage',
            'tax-config.view', 'tax-config.manage',
            'taxation.withholding_rules.manage',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->partnerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Partner A',
            'type' => 'customer',
        ]);
        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Partner B',
            'type' => 'customer',
        ]);

        $this->documentA = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $this->partnerA->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-A-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'status' => DocumentStatus::Posted,
        ]);
        $this->documentB = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-B-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'status' => DocumentStatus::Posted,
        ]);

        $this->paymentB = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            'payment_date' => now(),
            'amount' => '100.00',
            'currency' => 'TND',
        ]);

        $this->taxConfigFR = TaxConfiguration::create([
            'id' => Str::uuid()->toString(),
            'country_code' => 'FR',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA Standard',
            'code' => 'TVA_FR_20',
            'percentage_rate' => '20.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'is_active' => true,
        ]);
        $this->taxConfigTN = TaxConfiguration::create([
            'id' => Str::uuid()->toString(),
            'country_code' => 'TN',
            'tax_type' => 'PERCENTAGE',
            'name' => 'TVA Tunisia',
            'code' => 'TVA_TN_19',
            'percentage_rate' => '19.00',
            'applies_to' => 'LINE_ITEMS',
            'sequence_order' => 1,
            'stacks_on' => 'SUBTOTAL',
            'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest validators (api.taxation.001 - .006)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_withholding_certificate_rejects_cross_tenant_partner_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/withholding/certificates', [
                'direction' => 'outbound',
                'partner_id' => $this->partnerB->id,
                'currency' => 'EUR',
                'gross_amount' => '100.00',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);
    }

    public function test_create_withholding_certificate_rejects_cross_tenant_document_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/withholding/certificates', [
                'direction' => 'outbound',
                'partner_id' => $this->partnerA->id,
                'document_id' => $this->documentB->id,
                'currency' => 'EUR',
                'gross_amount' => '100.00',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('document_id', $cross->json('error.errors') ?? []);
    }

    public function test_create_withholding_certificate_rejects_cross_tenant_payment_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/withholding/certificates', [
                'direction' => 'outbound',
                'partner_id' => $this->partnerA->id,
                'payment_id' => $this->paymentB->id,
                'currency' => 'EUR',
                'gross_amount' => '100.00',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('payment_id', $cross->json('error.errors') ?? []);
    }

    public function test_record_sales_withholding_rejects_cross_tenant_customer_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->documentA->id}/record-withholding", [
                'customer_id' => $this->partnerB->id,
                'invoice_amount' => '100.00',
                'withholding_rate' => '0.10',
                'withholding_amount' => '10.00',
                'expected_receivable' => '90.00',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('customer_id', $cross->json('error.errors') ?? []);
    }

    public function test_record_sales_withholding_rejects_cross_tenant_payment_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->documentA->id}/record-withholding", [
                'customer_id' => $this->partnerA->id,
                'invoice_amount' => '100.00',
                'withholding_rate' => '0.10',
                'withholding_amount' => '10.00',
                'expected_receivable' => '90.00',
                'payment_id' => $this->paymentB->id,
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('payment_id', $cross->json('error.errors') ?? []);
    }

    public function test_calculate_withholding_rejects_cross_tenant_partner_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/withholding/preview', [
                'partner_id' => $this->partnerB->id,
                'amount' => '100.00',
                'currency' => 'EUR',
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // TaxConfiguration country-scoped findOrFails (api.taxation.008-010)
    //   Cross-COUNTRY lookups must 404. Cross-tenant SAME-country is
    //   by-design shareable (TaxConfiguration is a global reference
    //   keyed only by country_code; per master-plan kickoff brief,
    //   annotate as structurally_protected_by_country_scoped_reference
    //   rather than forcing tenant_id which doesn't exist on the table).
    // ──────────────────────────────────────────────────────────────────

    public function test_show_tax_configuration_rejects_cross_country_id(): void
    {
        // tenant-A is in FR; taxConfigTN belongs to TN. Cross-country -> 404.
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/taxation/configurations/{$this->taxConfigTN->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/taxation/configurations/{$this->taxConfigFR->id}");
        $same->assertStatus(200);
    }

    public function test_update_tax_configuration_rejects_cross_country_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/taxation/configurations/{$this->taxConfigTN->id}", [
                'name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $this->assertSame(
            'TVA Tunisia',
            $this->taxConfigTN->fresh()?->name,
            'Cross-country tax_configuration must remain unchanged.',
        );
    }

    public function test_destroy_tax_configuration_rejects_cross_country_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/taxation/configurations/{$this->taxConfigTN->id}");
        $cross->assertStatus(404);

        $this->assertTrue(
            (bool) $this->taxConfigTN->fresh()?->is_active,
            'Cross-country tax_configuration must NOT have been deactivated.',
        );
    }

    public function test_reorder_tax_configurations_rejects_cross_country_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/taxation/configurations/reorder', [
                'order' => [
                    ['id' => $this->taxConfigTN->id, 'sequence_order' => 99],
                ],
            ]);
        $cross->assertStatus(422);
        $this->assertArrayHasKey('order.0.id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // Per-tenant findOrFail (api.taxation.011-013)
    // ──────────────────────────────────────────────────────────────────

    public function test_record_withholding_rejects_cross_tenant_document_id(): void
    {
        // api.taxation.011: SalesWithholdingTrackingController::recordWithholding.
        // Pre-fix: Document::findOrFail loaded foreign-tenant document then 403'd
        // post-load. Post-fix: 404 from the read tier (cluster invariant).
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->documentB->id}/record-withholding", [
                'customer_id' => $this->partnerA->id,
                'invoice_amount' => '100.00',
                'withholding_rate' => '0.10',
                'withholding_amount' => '10.00',
                'expected_receivable' => '90.00',
            ]);
        $cross->assertStatus(404);
    }

    public function test_preview_withholding_rejects_cross_tenant_partner_id(): void
    {
        // api.taxation.012: WithholdingPreviewController::preview.
        // Validator now scopes partner_id (api.taxation.006), so this hits
        // 422 from the validator tier — the service-tier findOrFail
        // (line 35) is defense-in-depth.
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/withholding/preview', [
                'partner_id' => $this->partnerB->id,
                'amount' => '100.00',
                'currency' => 'EUR',
            ]);
        $cross->assertStatus(422);
    }

    public function test_create_withholding_certificate_rejects_cross_tenant_partner_at_service_tier(): void
    {
        // api.taxation.013: WithholdingCertificateController::store.
        // Validator now scopes partner_id (api.taxation.001), so this hits
        // 422 from the validator tier — the controller findOrFail (line 104)
        // is defense-in-depth.
        // Round-2 Opus Finding 6 honesty fix: pin error.errors.partner_id so
        // the test can't pass for an unrelated 422 reason (e.g. service-tier
        // DomainException for missing rule). Pre-fix the validator did not
        // catch the cross-tenant partner_id, so the request would 201 (not
        // 422), failing the assertion. Without this body-pin, a downstream
        // 422 would silently mask a regression.
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/withholding/certificates', [
                'direction' => 'outbound',
                'partner_id' => $this->partnerB->id,
                'currency' => 'EUR',
                'gross_amount' => '100.00',
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('partner_id', $cross->json('error.errors') ?? []);
    }

    // ──────────────────────────────────────────────────────────────────
    // Round-2 fixes (Opus Findings 1-4)
    // ──────────────────────────────────────────────────────────────────

    public function test_show_certificate_rejects_cross_tenant_id(): void
    {
        // Opus Finding 2 (CRITICAL): seeded foreign-tenant certificate
        // must 404 on /api/v1/withholding/certificates/{id}.
        $foreignCert = WithholdingCertificate::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            'direction' => WithholdingDirection::PURCHASE,
            'currency' => 'TND',
            'gross_amount' => '100.00',
            'certificate_number' => 'WT-FOREIGN-'.substr(Str::uuid()->toString(), 0, 8),
            'withholding_rate' => '0.10',
            'year' => 2026,
            'withholding_amount' => '10.00',
            'net_amount' => '90.00',
            'status' => CertificateStatus::DRAFT,
            'issued_at' => now(),
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/withholding/certificates/{$foreignCert->id}");
        $cross->assertStatus(404);
    }

    public function test_issue_certificate_rejects_cross_tenant_id(): void
    {
        // Opus Finding 1 (CRITICAL): fiscal hash chain integrity. Pre-fix
        // tenant-A admin could POST /issue against tenant-B's draft cert
        // and bake tenant-B's chain sequence into a tenant-A-attributed
        // fiscal action. Post-fix: 404.
        $foreignCert = WithholdingCertificate::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            'direction' => WithholdingDirection::PURCHASE,
            'currency' => 'TND',
            'gross_amount' => '100.00',
            'certificate_number' => 'WT-FOREIGN-'.substr(Str::uuid()->toString(), 0, 8),
            'withholding_rate' => '0.10',
            'year' => 2026,
            'withholding_amount' => '10.00',
            'net_amount' => '90.00',
            'status' => CertificateStatus::DRAFT,
            'issued_at' => now(),
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/withholding/certificates/{$foreignCert->id}/issue");
        $cross->assertStatus(404);

        // Post-condition: foreign certificate's status MUST remain Draft
        // (fiscal chain not entered).
        $this->assertSame(
            CertificateStatus::DRAFT,
            $foreignCert->fresh()?->status,
            'Cross-tenant certificate status must NOT have advanced to Issued.',
        );
    }

    public function test_destroy_certificate_rejects_cross_tenant_id(): void
    {
        // Opus Finding 2 (CRITICAL): destroy hard-deleted draft certs.
        $foreignCert = WithholdingCertificate::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'partner_id' => $this->partnerB->id,
            'direction' => WithholdingDirection::PURCHASE,
            'currency' => 'TND',
            'gross_amount' => '100.00',
            'certificate_number' => 'WT-FOREIGN-'.substr(Str::uuid()->toString(), 0, 8),
            'withholding_rate' => '0.10',
            'year' => 2026,
            'withholding_amount' => '10.00',
            'net_amount' => '90.00',
            'status' => CertificateStatus::DRAFT,
            'issued_at' => now(),
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/withholding/certificates/{$foreignCert->id}");
        $cross->assertStatus(404);

        // Post-condition: foreign certificate must still exist.
        $this->assertNotNull(
            $foreignCert->fresh(),
            'Cross-tenant certificate must NOT have been deleted.',
        );
    }

    public function test_show_company_specific_withholding_rule_rejects_cross_tenant(): void
    {
        // Opus Finding 4 (IMPORTANT): WithholdingTaxRuleController::show.
        // A foreign-tenant company-specific rule must 404; a global rule
        // (company_id IS NULL) is by-design shareable.
        $foreignRule = WithholdingTaxRule::create([
            'id' => Str::uuid()->toString(),
            'country_code' => 'TN',
            'company_id' => $this->companyB->id,
            'code' => 'PRIVATE_TN_15',
            'name' => 'Private Tunisia 15%',
            'rate' => '15.00',
            'effective_from' => now(),
            'is_active' => true,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/withholding/rules/{$foreignRule->id}");
        $cross->assertStatus(404);
    }

    public function test_update_company_specific_withholding_rule_rejects_cross_tenant(): void
    {
        $foreignRule = WithholdingTaxRule::create([
            'id' => Str::uuid()->toString(),
            'country_code' => 'TN',
            'company_id' => $this->companyB->id,
            'code' => 'PRIVATE_TN_15_U',
            'name' => 'Private Tunisia 15% Updatable',
            'rate' => '15.00',
            'effective_from' => now(),
            'is_active' => true,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/withholding/rules/{$foreignRule->id}", [
                'rate' => '0.99', // valid (0-1 range); test pins tenant scope.
            ]);
        $cross->assertStatus(404);

        // Post-condition: foreign rule's rate must NOT have been mutated.
        $this->assertNotSame(
            '0.9900',
            $foreignRule->fresh()?->rate,
            'Cross-tenant withholding rule rate must NOT have been mutated.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants (bar-raising pattern)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_certificate_validator_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        // We don't care about the response status here — capture the
        // validator SQL even if the request fails post-validation.
        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/withholding/certificates', [
                'direction' => 'outbound',
                'partner_id' => $this->partnerA->id,
                'currency' => 'EUR',
                'gross_amount' => '100.00',
            ]);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $partnersValidatorQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "partners"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $partnersValidatorQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $partnersValidatorQuery,
            'Partners exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $partnersValidatorQuery,
            'CreateWithholdingCertificateRequest partner_id validator must filter by tenant_id. Got SQL: '.$partnersValidatorQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $partnersValidatorQuery,
            'CreateWithholdingCertificateRequest partner_id validator must filter by company_id. Got SQL: '.$partnersValidatorQuery,
        );
    }

    public function test_show_tax_configuration_query_includes_country_predicate(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/taxation/configurations/{$this->taxConfigFR->id}")
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $taxConfigQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "tax_configurations"')
                && str_contains($sql, '"id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $taxConfigQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $taxConfigQuery,
            'TaxConfiguration lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"country_code"',
            $taxConfigQuery,
            'TaxConfiguration route-anchored lookup must filter by country_code (global reference table; '.
            'structurally_protected_by_country_scoped_reference). Got SQL: '.$taxConfigQuery,
        );
    }

    /**
     * Authenticate `$user` and pin the company context header to `$company`.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
