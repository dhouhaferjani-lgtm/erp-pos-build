<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury cluster — cross-COMPANY-within-tenant isolation regression coverage.
 *
 * The companion {@see TreasuryTenantIsolationTest} proves cross-TENANT ids are
 * rejected on every Treasury surface. These tests cover the parallel cross-
 * COMPANY-within-the-same-tenant axis, where the sweep inventory (post-2026-05-13
 * regenerate) surfaced 10 callsites on 5 controllers + 1 service whose lookup
 * was tenant-scoped only:
 *
 *   api.treasury.067  PaymentRepositoryController::show
 *   api.treasury.068  PaymentRepositoryController::update
 *   api.treasury.069  PaymentRepositoryController::balance
 *   api.treasury.070  PaymentRepositoryController::transactions
 *   api.treasury.071  PaymentRefundController::findPaymentOrFail (refund/reverse/etc.)
 *   api.treasury.072  PaymentRefundController::findDocumentOrFail (refund-prepayment)
 *   api.treasury.073  PaymentMethodController::show
 *   api.treasury.074  PaymentMethodController::update
 *   api.treasury.075  PaymentController::show
 *
 * Treasury is **company-scoped** (per-company payment repositories, payment
 * methods, payments); consolidated multi-company views would be a separate
 * explicit feature, not a defense-in-depth gap. A tenant-only `findOrFail`
 * therefore returns a foreign-company row to the caller — for the controller
 * callsites that means leaking data (GET) or operating on the wrong company's
 * resource (PATCH / refund). The service callsite (063) is fronted by a
 * `ScopedExists::tenantAndCompany` validator at the controller, so it is
 * defense-in-depth — but a programmatic caller bypassing the validator would
 * still get the foreign row without the company scope.
 *
 * Each cross-company test is RED before this branch's fix, GREEN after. A few
 * positive same-company controls accompany the cross-company assertions so a
 * passes-for-the-wrong-reason cannot sneak in.
 */
final class TreasuryCompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $user;

    private PaymentRepository $repositoryA;

    private PaymentRepository $repositoryB;

    private PaymentMethod $methodA;

    private PaymentMethod $methodB;

    private Payment $paymentA;

    private Payment $paymentB;

    private Document $purchaseOrderB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Co-Isolation Tenant',
            'slug' => 'co-isolation-'.Str::random(6),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cross-Company Admin',
            'email' => 'cross-company-admin-'.Str::random(6).'@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        // payments.refund lives in PermissionSeeder (not RolesAndPermissionsSeeder),
        // so create + grant it explicitly so the refund endpoint's `can:` middleware
        // doesn't 403 ahead of the findPaymentOrFail callsite under test.
        Permission::findOrCreate('payments.refund', 'sanctum');
        $this->user->givePermissionTo('payments.refund');

        // The user has membership in BOTH companies — the isolation must come
        // from the request's active CompanyContext (X-Company-Id header), not
        // from missing membership.
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        [, $this->methodA, $this->repositoryA, , $this->paymentA]
            = $this->seedCompanyResources($this->companyA);
        [, $this->methodB, $this->repositoryB, $this->purchaseOrderB, $this->paymentB]
            = $this->seedCompanyResources($this->companyB);
    }

    // ---------------------------------------------------------------------
    // PaymentRepository — 067 / 068 / 069 / 070
    // ---------------------------------------------------------------------

    public function test_show_payment_repository_refuses_cross_company_id(): void
    {
        // Cross-company: act as company A, request company B's repository id.
        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payment-repositories/'.$this->repositoryB->id)
            ->assertNotFound();

        // Same-company control: company A → its own repository succeeds.
        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payment-repositories/'.$this->repositoryA->id)
            ->assertOk();
    }

    public function test_update_payment_repository_refuses_cross_company_id(): void
    {
        $this->actingAsForCompany($this->companyA)
            ->patchJson('/api/v1/payment-repositories/'.$this->repositoryB->id, ['name' => 'Renamed By A'])
            ->assertNotFound();

        // Sanity: company B's repository name is unchanged.
        $this->repositoryB->refresh();
        $this->assertSame('Repo B', $this->repositoryB->name);
    }

    public function test_payment_repository_balance_refuses_cross_company_id(): void
    {
        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payment-repositories/'.$this->repositoryB->id.'/balance')
            ->assertNotFound();
    }

    public function test_payment_repository_transactions_refuses_cross_company_id(): void
    {
        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payment-repositories/'.$this->repositoryB->id.'/transactions')
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // PaymentMethod — 073 / 074
    // ---------------------------------------------------------------------

    public function test_index_payment_methods_returns_only_the_active_company_rows(): void
    {
        $response = $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payment-methods')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertSame([$this->methodA->id], $ids);
        $this->assertNotContains($this->methodB->id, $ids);
    }

    public function test_store_payment_method_allows_a_code_used_only_by_a_sibling_company(): void
    {
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'code' => 'SIBLING-CARD',
        ]);

        $response = $this->actingAsForCompany($this->companyA)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'SIBLING-CARD',
                'name' => 'Company A Card',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('payment_methods', [
            'id' => $response->json('data.id'),
            'company_id' => $this->companyA->id,
            'code' => 'SIBLING-CARD',
        ]);
    }

    public function test_update_payment_method_allows_a_code_used_only_by_a_sibling_company(): void
    {
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'code' => 'SIBLING-WIRE',
        ]);

        $this->actingAsForCompany($this->companyA)
            ->patchJson('/api/v1/payment-methods/'.$this->methodA->id, [
                'code' => 'SIBLING-WIRE',
            ])
            ->assertOk()
            ->assertJsonPath('data.code', 'SIBLING-WIRE');

        $this->methodA->refresh();
        $this->assertSame('SIBLING-WIRE', $this->methodA->code);
    }

    public function test_show_payment_method_refuses_cross_company_id(): void
    {
        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payment-methods/'.$this->methodB->id)
            ->assertNotFound();

        // Same-company control.
        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payment-methods/'.$this->methodA->id)
            ->assertOk();
    }

    public function test_update_payment_method_refuses_cross_company_id(): void
    {
        $this->actingAsForCompany($this->companyA)
            ->patchJson('/api/v1/payment-methods/'.$this->methodB->id, ['name' => 'Renamed By A'])
            ->assertNotFound();

        $this->methodB->refresh();
        $this->assertSame('Cash B', $this->methodB->name);
    }

    // ---------------------------------------------------------------------
    // Payment — 075 (show), 071 (refund — exercises findPaymentOrFail)
    // ---------------------------------------------------------------------

    public function test_show_payment_refuses_cross_company_id(): void
    {
        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payments/'.$this->paymentB->id)
            ->assertNotFound();

        $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payments/'.$this->paymentA->id)
            ->assertOk();
    }

    public function test_refund_payment_refuses_cross_company_id(): void
    {
        // POST /payments/{payment}/refund exercises PaymentRefundController::
        // findPaymentOrFail (api.treasury.071). A tenant-only lookup would
        // happily refund company B's payment under company A's context.
        $this->actingAsForCompany($this->companyA)
            ->postJson('/api/v1/payments/'.$this->paymentB->id.'/refund', ['reason' => 'cross-company smoke'])
            ->assertNotFound();

        // Sanity: company B's payment is not in a refund state.
        $this->paymentB->refresh();
        $this->assertSame(PaymentStatus::Completed, $this->paymentB->status);
    }

    // ---------------------------------------------------------------------
    // Document — 072 (refund-prepayment route param exercises findDocumentOrFail)
    // ---------------------------------------------------------------------

    public function test_refund_prepayment_refuses_cross_company_document_id(): void
    {
        // POST /documents/{document}/refund-prepayment — the route param
        // $documentId is NOT covered by RefundPrepaymentRequest (which only
        // validates the body). The findDocumentOrFail (api.treasury.072) is
        // therefore the only gate on the URL document id.
        $this->actingAsForCompany($this->companyA)
            ->postJson('/api/v1/documents/'.$this->purchaseOrderB->id.'/refund-prepayment', [
                'payment_method_id' => $this->methodA->id,
                'repository_id' => $this->repositoryA->id,
                'amount' => '10.00',
                'reason' => 'cross-company smoke',
            ])
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{0: Partner, 1: PaymentMethod, 2: PaymentRepository, 3: Document, 4: Payment}
     */
    private function seedCompanyResources(Company $company): array
    {
        $suffix = $company->id === $this->companyA->id ? 'A' : 'B';

        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
        ]);

        $method = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => 'CSH-'.$suffix,
            'name' => 'Cash '.$suffix,
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $repository = PaymentRepository::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'code' => 'REPO-'.$suffix,
            'name' => 'Repo '.$suffix,
            'type' => RepositoryType::CashRegister,
            'balance' => '0.00',
            'is_active' => true,
        ]);

        $purchaseOrder = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::PurchaseOrder,
            'document_number' => 'PO-'.$suffix.'-'.Str::random(4),
            'document_date' => now()->toDateString(),
            'partner_id' => $partner->id,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'balance_due' => '100.00',
            'currency' => 'EUR',
            // VendorRefundService requires Confirmed; any other status short-circuits
            // with a domain error and masks the findDocumentOrFail callsite under test.
            'status' => DocumentStatus::Confirmed,
        ]);

        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'repository_id' => $repository->id,
            'amount' => '25.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PMT-'.$suffix.'-'.Str::random(4),
        ]);

        return [$partner, $method, $repository, $purchaseOrder, $payment];
    }

    public function test_index_filters_by_search_reference_or_partner_name(): void
    {
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'name' => 'Zenith Wholesale Ltd',
        ]);

        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $this->methodA->id,
            'repository_id' => $this->repositoryA->id,
            'amount' => '10.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'INV-SEARCH-9911',
        ]);

        // Match by reference.
        $byRef = $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payments?search=SEARCH-9911');
        $byRef->assertOk();
        $refIds = array_column($byRef->json('data'), 'id');
        $this->assertContains($payment->id, $refIds);
        $this->assertNotContains($this->paymentA->id, $refIds);

        // Match by partner name.
        $byName = $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payments?search=Zenith');
        $byName->assertOk();
        $this->assertContains($payment->id, array_column($byName->json('data'), 'id'));

        // No match.
        $none = $this->actingAsForCompany($this->companyA)
            ->getJson('/api/v1/payments?search=zzzNoSuchPayment');
        $none->assertOk();
        $this->assertCount(0, $none->json('data'));
    }

    private function actingAsForCompany(Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        /** @var self */
        return $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
