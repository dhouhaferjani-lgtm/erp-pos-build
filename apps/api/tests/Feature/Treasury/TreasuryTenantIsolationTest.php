<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
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
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Tests\Traits\AssertsApiValidation;

/**
 * Section 7 (Treasury cluster) — tenant-isolation regression coverage for the
 * 48 callsites flagged by the sweep inventory.
 *
 * Each test exercises a Treasury HTTP endpoint by passing a tenant-A user a
 * resource id that belongs to tenant B and asserts the request is rejected
 * (typically 422 from validation, 404 from a scoped findOrFail, or 403 when
 * authorization gates fire). A same-tenant control assertion accompanies each
 * cross-tenant assertion to prove the test setup itself is sensitive enough
 * to catch real leaks (so a "passes-for-the-wrong-reason" never sneaks in).
 *
 * The tests are grouped by the Presentation surface they exercise so partial
 * fixes are obvious in the report:
 *
 *  - FormRequest rule path (RefundPrepaymentRequest)
 *  - Controller `$request->validate(...)` inline path (PaymentMethodController,
 *    BankReconciliationController, PaymentController, PaymentInstrumentController,
 *    SmartPaymentController, MultiPaymentController)
 *  - Service-layer `Model::find()` / `Model::findOrFail()` path
 *    (PaymentAllocationService, PaymentRefundService, VendorRefundService,
 *    plus Controller-embedded find calls)
 *
 * Cross-references the inventory at:
 *   docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
 *   (api.treasury.001 .. api.treasury.048)
 */
final class TreasuryTenantIsolationTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    // ---------- Resources owned by tenant A ----------
    private Partner $partnerA;

    private PaymentMethod $paymentMethodA;

    private PaymentRepository $repositoryA;

    private PaymentInstrument $instrumentA;

    private Account $accountA;

    private Document $invoiceA;

    private Document $purchaseOrderA;

    private Payment $paymentA;

    // ---------- Resources owned by tenant B ----------
    private Partner $partnerB;

    private PaymentMethod $paymentMethodB;

    private PaymentRepository $repositoryB;

    private PaymentRepository $bankRepositoryB;

    private PaymentInstrument $instrumentB;

    private Account $accountB;

    private Document $invoiceB;

    private Document $purchaseOrderB;

    private Payment $paymentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('tenant-a');
        $this->tenantB = $this->makeTenant('tenant-b');

        // Seed Spatie permissions per tenant team scope (sanctum guard)
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Companies (one per tenant)
        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenantB->id]);

        // Admin users with full Treasury permissions
        $this->userA = $this->makeUser($this->tenantA, 'user-a@example.com');
        $this->userB = $this->makeUser($this->tenantB, 'user-b@example.com');

        // Per-tenant resources
        [$this->partnerA, $this->paymentMethodA, $this->repositoryA,
            $this->instrumentA, $this->accountA, $this->invoiceA,
            $this->purchaseOrderA, $this->paymentA]
            = $this->seedTenantResources($this->tenantA, $this->companyA, $this->partnerA ?? null);

        [$this->partnerB, $this->paymentMethodB, $this->repositoryB,
            $this->instrumentB, $this->accountB, $this->invoiceB,
            $this->purchaseOrderB, $this->paymentB]
            = $this->seedTenantResources($this->tenantB, $this->companyB, $this->partnerB ?? null);

        // A second tenant-B repository with type=bank_account so the
        // PaymentInstrumentController::deposit cross-tenant test can reach the
        // bare exists validator instead of being short-circuited by the
        // INVALID_REPOSITORY guard (which fires for non-bank repositories).
        $this->bankRepositoryB = PaymentRepository::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'code' => strtoupper(Str::random(4)),
            'name' => 'B Bank',
            'type' => RepositoryType::BankAccount,
            'balance' => '0.00',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Surface 1 — FormRequest rule path
    // =========================================================================

    /**
     * Inventory: api.treasury.001 (payment_methods), api.treasury.002 (payment_repositories).
     * Surface: RefundPrepaymentRequest::rules() exists rules.
     * Endpoint: POST /api/v1/documents/{document}/refund-prepayment
     */
    public function test_refund_prepayment_refuses_cross_tenant_payment_method_via_form_request(): void
    {
        // Cross-tenant: tenant-A user submits tenant-B payment_method_id → 422
        $crossResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->purchaseOrderA->id}/refund-prepayment", [
                'amount' => '10.00',
                'payment_method_id' => $this->paymentMethodB->id,
                'repository_id' => $this->repositoryA->id,
            ]);
        $this->assertApiValidationErrors($crossResponse, ['payment_method_id']);

        // Same-tenant control: same call with tenant-A's payment_method_id must NOT trip the validator
        $sameResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->purchaseOrderA->id}/refund-prepayment", [
                'amount' => '10.00',
                'payment_method_id' => $this->paymentMethodA->id,
                'repository_id' => $this->repositoryA->id,
            ]);
        // Validator must accept; downstream may still 422 for unrelated reasons
        // (e.g. service-tier "refund exceeds allocated"). The key assertion is
        // that the FormRequest rule itself did NOT flag payment_method_id.
        $this->assertNoValidationErrorFor($sameResponse, 'payment_method_id');
    }

    public function test_refund_prepayment_refuses_cross_tenant_repository_via_form_request(): void
    {
        $crossResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->purchaseOrderA->id}/refund-prepayment", [
                'amount' => '10.00',
                'payment_method_id' => $this->paymentMethodA->id,
                'repository_id' => $this->repositoryB->id,
            ]);
        $this->assertApiValidationErrors($crossResponse, ['repository_id']);

        $sameResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->purchaseOrderA->id}/refund-prepayment", [
                'amount' => '10.00',
                'payment_method_id' => $this->paymentMethodA->id,
                'repository_id' => $this->repositoryA->id,
            ]);
        $this->assertNoValidationErrorFor($sameResponse, 'repository_id');
    }

    // =========================================================================
    // Surface 2 — Controller `$request->validate(...)` inline rule path
    // =========================================================================

    /**
     * Inventory: api.treasury.003 (payment_repositories).
     * Endpoint: POST /api/v1/bank-reconciliations  → BankReconciliationController::store
     */
    public function test_bank_reconciliation_store_refuses_cross_tenant_repository_id(): void
    {
        $crossResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/bank-reconciliations', [
                'repository_id' => $this->repositoryB->id,
                'statement_date' => now()->toDateString(),
                'statement_balance' => '0.00',
            ]);
        // After the fix, the bare exists-validator on repository_id will reject
        // the cross-tenant id at the validation layer with 422 + a structured
        // error. Today (RED), the bare exists accepts the cross-tenant id and
        // the controller proceeds into the service, which 404s via a separately
        // scoped findOrFail in BankReconciliationService — meaning the bare
        // exists is currently masked end-to-end but still a defense-in-depth
        // gap (other controllers use the same pattern without a service guard).
        // We assert 422 here so the test goes RED → GREEN as the fix lands.
        $this->assertApiValidationErrors($crossResponse, ['repository_id']);

        $sameResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/bank-reconciliations', [
                'repository_id' => $this->repositoryA->id,
                'statement_date' => now()->toDateString(),
                'statement_balance' => '0.00',
            ]);
        $this->assertNotSame(
            422,
            $sameResponse->status(),
            'Same-tenant repository_id must pass validation. Got: '.$sameResponse->getContent(),
        );
    }

    /**
     * Inventory: api.treasury.004 / 005 (default_account_id, fee_account_id on accounts).
     * Endpoint: POST /api/v1/payment-methods → PaymentMethodController::store
     */
    public function test_payment_method_store_refuses_cross_tenant_default_account_id(): void
    {
        $crossResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'X1',
                'name' => 'Test',
                'default_account_id' => $this->accountB->id,
            ]);
        $this->assertApiValidationErrors($crossResponse, ['default_account_id']);
    }

    public function test_payment_method_store_refuses_cross_tenant_fee_account_id(): void
    {
        $crossResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'X2',
                'name' => 'Test',
                'fee_account_id' => $this->accountB->id,
            ]);
        $this->assertApiValidationErrors($crossResponse, ['fee_account_id']);
    }

    /**
     * Same-tenant control proving accounts validator passes for legit ids.
     */
    public function test_payment_method_store_accepts_same_tenant_account_ids(): void
    {
        $sameResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'OK',
                'name' => 'Test',
                'default_account_id' => $this->accountA->id,
                'fee_account_id' => $this->accountA->id,
            ]);
        $this->assertNotSame(
            422,
            $sameResponse->status(),
            'Same-tenant account ids must pass validation. Got: '.$sameResponse->getContent(),
        );
    }

    /**
     * Inventory: api.treasury.006 / 007 — PaymentMethodController::update.
     */
    public function test_payment_method_update_refuses_cross_tenant_default_account_id(): void
    {
        $method = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'UPDA',
            'name' => 'Update target',
            'is_active' => true,
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $crossResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/payment-methods/{$method->id}", [
                'default_account_id' => $this->accountB->id,
            ]);
        $this->assertApiValidationErrors($crossResponse, ['default_account_id']);
    }

    public function test_payment_method_update_refuses_cross_tenant_fee_account_id(): void
    {
        $method = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'UPDB',
            'name' => 'Update target',
            'is_active' => true,
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $crossResponse = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/payment-methods/{$method->id}", [
                'fee_account_id' => $this->accountB->id,
            ]);
        $this->assertApiValidationErrors($crossResponse, ['fee_account_id']);
    }

    /**
     * Inventory: api.treasury.008–011 — PaymentController::store inline validate().
     */
    public function test_payments_store_refuses_cross_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->paymentStorePayload([
                'partner_id' => $this->partnerB->id,
            ]));
        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_payments_store_refuses_cross_tenant_payment_method_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->paymentStorePayload([
                'payment_method_id' => $this->paymentMethodB->id,
            ]));
        $this->assertApiValidationErrors($response, ['payment_method_id']);
    }

    public function test_payments_store_refuses_cross_tenant_repository_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->paymentStorePayload([
                'repository_id' => $this->repositoryB->id,
            ]));
        $this->assertApiValidationErrors($response, ['repository_id']);
    }

    public function test_payments_store_refuses_cross_tenant_allocation_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->paymentStorePayload([
                'allocations' => [[
                    'document_id' => $this->invoiceB->id,
                    'amount' => '5.00',
                ]],
            ]));
        $this->assertApiValidationErrors($response, ['allocations.0.document_id']);
    }

    /**
     * Inventory: api.treasury.012–016 — PaymentController::storeMultiple inline validate().
     * Triggered when `payments` array is present in the body.
     */
    public function test_payments_store_multiple_refuses_cross_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->multiPaymentStorePayload([
                'partner_id' => $this->partnerB->id,
            ]));
        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_payments_store_multiple_refuses_cross_tenant_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->multiPaymentStorePayload([
                'document_id' => $this->invoiceB->id,
            ]));
        $this->assertApiValidationErrors($response, ['document_id']);
    }

    public function test_payments_store_multiple_refuses_cross_tenant_payment_method_id(): void
    {
        $payload = $this->multiPaymentStorePayload();
        $payload['payments'][0]['payment_method_id'] = $this->paymentMethodB->id;

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $payload);
        $this->assertApiValidationErrors($response, ['payments.0.payment_method_id']);
    }

    public function test_payments_store_multiple_refuses_cross_tenant_repository_id(): void
    {
        $payload = $this->multiPaymentStorePayload();
        $payload['payments'][0]['repository_id'] = $this->repositoryB->id;

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $payload);
        $this->assertApiValidationErrors($response, ['payments.0.repository_id']);
    }

    public function test_payments_store_multiple_refuses_cross_tenant_excess_allocation_document_id(): void
    {
        $payload = $this->multiPaymentStorePayload();
        $payload['excess_allocation_method'] = 'manual';
        $payload['excess_allocations'] = [[
            'document_id' => $this->invoiceB->id,
            'amount' => '5.00',
        ]];

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $payload);
        $this->assertApiValidationErrors($response, ['excess_allocations.0.document_id']);
    }

    /**
     * Inventory: api.treasury.017–020 — SmartPaymentController.
     */
    public function test_smart_payment_preview_refuses_cross_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->partnerB->id,
                'payment_amount' => '10.00',
                'allocation_method' => 'fifo',
            ]);
        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_smart_payment_preview_refuses_cross_tenant_manual_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/smart-payment/preview-allocation', [
                'partner_id' => $this->partnerA->id,
                'payment_amount' => '10.00',
                'allocation_method' => 'manual',
                'manual_allocations' => [[
                    'document_id' => $this->invoiceB->id,
                    'amount' => '5.00',
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['manual_allocations.0.document_id']);
    }

    public function test_smart_payment_apply_refuses_cross_tenant_payment_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/smart-payment/apply-allocation', [
                'payment_id' => $this->paymentB->id,
                'allocation_method' => 'fifo',
            ]);
        $this->assertApiValidationErrors($response, ['payment_id']);
    }

    public function test_smart_payment_apply_refuses_cross_tenant_manual_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/smart-payment/apply-allocation', [
                'payment_id' => $this->paymentA->id,
                'allocation_method' => 'manual',
                'manual_allocations' => [[
                    'document_id' => $this->invoiceB->id,
                    'amount' => '5.00',
                ]],
            ]);
        $this->assertApiValidationErrors($response, ['manual_allocations.0.document_id']);
    }

    /**
     * Inventory: api.treasury.021–023 — PaymentInstrumentController::store.
     */
    public function test_payment_instrument_store_refuses_cross_tenant_payment_method_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-instruments', $this->instrumentStorePayload([
                'payment_method_id' => $this->paymentMethodB->id,
            ]));
        $this->assertApiValidationErrors($response, ['payment_method_id']);
    }

    public function test_payment_instrument_store_refuses_cross_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-instruments', $this->instrumentStorePayload([
                'partner_id' => $this->partnerB->id,
            ]));
        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    public function test_payment_instrument_store_refuses_cross_tenant_repository_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-instruments', $this->instrumentStorePayload([
                'repository_id' => $this->repositoryB->id,
            ]));
        $this->assertApiValidationErrors($response, ['repository_id']);
    }

    /**
     * Inventory: api.treasury.024 — PaymentInstrumentController::deposit (inline validate).
     */
    public function test_payment_instrument_deposit_refuses_cross_tenant_repository_id(): void
    {
        // Reload the instrument fresh so its status is Received (factory default).
        // Tenant-A user posts a tenant-B repository_id. After the fix the bare
        // `exists:payment_repositories,id` validator becomes ScopedExists and
        // returns 422 with a structured 'repository_id' validation error.
        // Today (RED), the bare exists accepts the cross-tenant id and the
        // controller proceeds into PaymentRepository::findOrFail() which —
        // although it 404s for a wholly missing id — passes for any existing
        // bank_account in any tenant. Critical: the cross-tenant repository
        // is then assigned to the instrument as `deposited_to_id`.
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/payment-instruments/{$this->instrumentA->id}/deposit", [
                'repository_id' => $this->bankRepositoryB->id,
            ]);
        // After the fix: 422 with structured validation error on repository_id.
        // Today (RED): the bare exists accepts the cross-tenant id, the
        // controller's INVALID_REPOSITORY guard does not fire (we deliberately
        // chose a bank_account repository for tenant B), and the deposit
        // succeeds against tenant B's bank repository. That is the leak.
        $this->assertApiValidationErrors($response, ['repository_id']);
    }

    /**
     * Inventory: api.treasury.025 — PaymentInstrumentController::transfer (inline validate).
     */
    public function test_payment_instrument_transfer_refuses_cross_tenant_repository_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/payment-instruments/{$this->instrumentA->id}/transfer", [
                'to_repository_id' => $this->repositoryB->id,
            ]);
        $this->assertApiValidationErrors($response, ['to_repository_id']);
    }

    // =========================================================================
    // Surface 3 — Service / Controller `Model::find()` & `findOrFail()` path
    // =========================================================================

    /**
     * Inventory: api.treasury.033 — MultiPaymentController::createSplitPayment
     * (Document::findOrFail at line 38).
     *
     * Crafted to hit `Document::findOrFail($documentId)` with a tenant-B id —
     * after the fix this should 404, today (RED) it returns 200/422 because
     * the find is unscoped.
     */
    public function test_multi_payment_create_split_refuses_cross_tenant_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->invoiceB->id}/split-payment", [
                'splits' => [
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                ],
            ]);

        $this->assertContains(
            $response->status(),
            [403, 404],
            'Cross-tenant document_id must NOT be findable. Got status '.$response->status().' body: '.$response->getContent(),
        );
    }

    /**
     * Inventory: api.treasury.034 + 035 — MultiPaymentController::applyDeposit
     * (Payment::findOrFail at line 118 + Document::findOrFail at line 120).
     */
    public function test_multi_payment_apply_deposit_refuses_cross_tenant_payment_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/payments/{$this->paymentB->id}/apply-deposit", [
                'document_id' => $this->invoiceA->id,
                'amount' => '5.00',
            ]);
        $this->assertContains(
            $response->status(),
            [403, 404],
            'Cross-tenant payment_id must NOT be findable. Got status '.$response->status().' body: '.$response->getContent(),
        );
    }

    public function test_multi_payment_apply_deposit_refuses_cross_tenant_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/payments/{$this->paymentA->id}/apply-deposit", [
                'document_id' => $this->invoiceB->id,
                'amount' => '5.00',
            ]);
        $this->assertContains(
            $response->status(),
            [403, 404, 422],
            'Cross-tenant document_id must NOT be findable. Got status '.$response->status().' body: '.$response->getContent(),
        );
    }

    /**
     * Inventory: api.treasury.031 + 032 — VendorRefundService inside refundPrepayment closure
     * (Document::lockForUpdate()->findOrFail + PaymentRepository::lockForUpdate()->find).
     *
     * Endpoint: POST /api/v1/documents/{document}/refund-prepayment.
     * The Controller's `findDocumentOrFail` already 404s on cross-tenant document
     * (so for tenant-B PO a 404 fires before the service). To hit the service
     * find()s directly we use a same-tenant PO + a cross-tenant repository_id.
     * That tests `PaymentRepository::lockForUpdate()->find($repositoryId)` at
     * VendorRefundService.php:104 — after the fix, the repo must come back null
     * and the GL reversal must be skipped. Today, before the fix, the unscoped
     * find pulls a tenant-B repository and the GL reversal is wired against it
     * (potential cross-tenant write).
     *
     * Note: bare `exists:` on repository_id (api.treasury.002) is in the SAME
     * request and will trip first — so this test exercises the FormRequest fix
     * AND simultaneously locks down the service-tier find at line 104.
     */
    public function test_refund_prepayment_refuses_cross_tenant_repository_at_service_tier(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->purchaseOrderA->id}/refund-prepayment", [
                'amount' => '10.00',
                'payment_method_id' => $this->paymentMethodA->id,
                'repository_id' => $this->repositoryB->id,
            ]);
        $this->assertContains(
            $response->status(),
            [403, 404, 422],
            'Cross-tenant repository_id must be rejected. Got status '.$response->status().' body: '.$response->getContent(),
        );
    }

    /**
     * Cross-tenant document route-param control: tenant-A user POSTs against
     * tenant-B's purchase order id. The controller's
     * `Document::findDocumentOrFail` chain is already tenant-scoped, so the
     * route hits a 404 before any service work runs. Locks that scoping in.
     */
    public function test_refund_prepayment_refuses_cross_tenant_document_route_param(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->purchaseOrderB->id}/refund-prepayment", [
                'amount' => '10.00',
                'payment_method_id' => $this->paymentMethodA->id,
                'repository_id' => $this->repositoryA->id,
            ]);
        $this->assertContains(
            $response->status(),
            [403, 404],
            'Cross-tenant document route-param must be rejected by scoped find. Got status '
            .$response->status().' body: '.$response->getContent(),
        );
    }

    // =========================================================================
    // Surface 4 — Newly-discovered callsites (Codex+Opus Treasury 2026-05-04 review)
    //
    // ExistsRuleVisitor pipe-form fix (Block 1) + GUARDED_TABLES expansion
    // (payment_instruments, journals, users) surfaced 14 additional Treasury
    // bare exists callsites the original Section 7 sweep missed:
    //
    //   - MultiPaymentController × 9 (lines 33,35,36,75,76,79,80,122,190 — pipe-form)
    //   - PaymentController.php:112 (instrument_id, payment_instruments table)
    //   - PaymentMethodController.php × 2 (lines 77,148 — default_journal_id, journals)
    //   - PaymentRepositoryController.php × 2 (lines 75,131 — responsible_user_id, users)
    //
    // Inventory: api.treasury.049..062.
    // =========================================================================

    /**
     * Inventory: api.treasury.049 — MultiPaymentController::createSplitPayment
     * (splits.*.payment_method_id, payment_methods).
     */
    public function test_split_payment_refuses_cross_tenant_split_payment_method_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->invoiceA->id}/split-payment", [
                'splits' => [
                    [
                        'payment_method_id' => $this->paymentMethodB->id,
                        'amount' => '5.00',
                    ],
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                ],
            ]);
        $this->assertApiValidationErrors($response, ['splits.0.payment_method_id']);
    }

    /**
     * Inventory: api.treasury.050 — MultiPaymentController::createSplitPayment
     * (splits.*.repository_id, payment_repositories).
     */
    public function test_split_payment_refuses_cross_tenant_split_repository_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->invoiceA->id}/split-payment", [
                'splits' => [
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                        'repository_id' => $this->repositoryB->id,
                    ],
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                ],
            ]);
        $this->assertApiValidationErrors($response, ['splits.0.repository_id']);
    }

    /**
     * Inventory: api.treasury.058 — MultiPaymentController::createSplitPayment
     * (splits.*.instrument_id, payment_instruments).
     */
    public function test_split_payment_refuses_cross_tenant_split_instrument_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->invoiceA->id}/split-payment", [
                'splits' => [
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                        'instrument_id' => $this->instrumentB->id,
                    ],
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                ],
            ]);
        $this->assertApiValidationErrors($response, ['splits.0.instrument_id']);
    }

    /**
     * Inventory: api.treasury.051 — MultiPaymentController::recordDeposit (partner_id, partners).
     */
    public function test_record_deposit_refuses_cross_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments/deposit', [
                'partner_id' => $this->partnerB->id,
                'payment_method_id' => $this->paymentMethodA->id,
                'amount' => '10.00',
                'currency' => 'EUR',
            ]);
        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    /**
     * Inventory: api.treasury.052 — MultiPaymentController::recordDeposit
     * (payment_method_id, payment_methods).
     */
    public function test_record_deposit_refuses_cross_tenant_payment_method_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments/deposit', [
                'partner_id' => $this->partnerA->id,
                'payment_method_id' => $this->paymentMethodB->id,
                'amount' => '10.00',
                'currency' => 'EUR',
            ]);
        $this->assertApiValidationErrors($response, ['payment_method_id']);
    }

    /**
     * Inventory: api.treasury.053 — MultiPaymentController::recordDeposit
     * (repository_id, payment_repositories).
     */
    public function test_record_deposit_refuses_cross_tenant_repository_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments/deposit', [
                'partner_id' => $this->partnerA->id,
                'payment_method_id' => $this->paymentMethodA->id,
                'amount' => '10.00',
                'currency' => 'EUR',
                'repository_id' => $this->repositoryB->id,
            ]);
        $this->assertApiValidationErrors($response, ['repository_id']);
    }

    /**
     * Inventory: api.treasury.059 — MultiPaymentController::recordDeposit
     * (instrument_id, payment_instruments).
     */
    public function test_record_deposit_refuses_cross_tenant_instrument_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments/deposit', [
                'partner_id' => $this->partnerA->id,
                'payment_method_id' => $this->paymentMethodA->id,
                'amount' => '10.00',
                'currency' => 'EUR',
                'instrument_id' => $this->instrumentB->id,
            ]);
        $this->assertApiValidationErrors($response, ['instrument_id']);
    }

    /**
     * Inventory: api.treasury.054 — MultiPaymentController::applyDeposit
     * (document_id, documents — pipe-form bare exists at line 122).
     *
     * The applyDeposit endpoint also has a service-tier Document::findOrFail
     * that's already scoped (api.treasury.035), so the body-validator fires
     * first and surfaces a structured validation error post-fix.
     */
    public function test_apply_deposit_refuses_cross_tenant_document_id_via_validator(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/payments/{$this->paymentA->id}/apply-deposit", [
                'document_id' => $this->invoiceB->id,
                'amount' => '5.00',
            ]);
        $this->assertApiValidationErrors($response, ['document_id']);
    }

    /**
     * Inventory: api.treasury.055 — MultiPaymentController::recordPaymentOnAccount
     * (partner_id, partners).
     */
    public function test_record_payment_on_account_refuses_cross_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments/on-account', [
                'partner_id' => $this->partnerB->id,
                'amount' => '10.00',
                'currency' => 'EUR',
            ]);
        $this->assertApiValidationErrors($response, ['partner_id']);
    }

    /**
     * Inventory: api.treasury.062 — PaymentController::store (instrument_id, payment_instruments).
     */
    public function test_payments_store_refuses_cross_tenant_instrument_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->paymentStorePayload([
                'instrument_id' => $this->instrumentB->id,
            ]));
        $this->assertApiValidationErrors($response, ['instrument_id']);
    }

    /**
     * Inventory: api.treasury.056 — PaymentRepositoryController::store
     * (responsible_user_id, users — tenant-scoped only, no company column).
     */
    public function test_payment_repository_store_refuses_cross_tenant_responsible_user_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-repositories', [
                'code' => 'NEW1',
                'name' => 'New Repo',
                'type' => 'cash_register',
                'responsible_user_id' => $this->userB->id,
            ]);
        $this->assertApiValidationErrors($response, ['responsible_user_id']);
    }

    /**
     * Inventory: api.treasury.057 — PaymentRepositoryController::update
     * (responsible_user_id, users).
     */
    public function test_payment_repository_update_refuses_cross_tenant_responsible_user_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/payment-repositories/{$this->repositoryA->id}", [
                'responsible_user_id' => $this->userB->id,
            ]);
        $this->assertApiValidationErrors($response, ['responsible_user_id']);
    }

    /**
     * Inventory: api.treasury.060 + 061 — PaymentMethodController::store + ::update
     * (default_journal_id, journals).
     *
     * Important context: there is NO `journals` table in the schema. The
     * `default_journal_id` column on `payment_methods` is a legacy storage-only
     * field — no Eloquent model, no migration, no read path. The bare
     * `exists:journals,id` validator was therefore broken (any value triggers a
     * 500 SQL error: relation "journals" does not exist).
     *
     * The fix REMOVES the broken validator. There's nothing to scope against.
     * Test asserts: the validator no longer 500s on a bare UUID, AND the column
     * still accepts any well-formed UUID payload (no validation error). Because
     * the rule is gone, cross-tenant data binding is moot for this column.
     */
    public function test_payment_method_store_default_journal_id_validator_no_longer_500s(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-methods', [
                'code' => 'JNL1',
                'name' => 'Test',
                'default_journal_id' => Str::uuid()->toString(),
            ]);
        // Pre-fix: 500 (SQLSTATE[42P01] relation "journals" does not exist).
        // Post-fix: 200/201 — validator no longer hits the missing table.
        $this->assertNotSame(
            500,
            $response->status(),
            'default_journal_id validator must not 500 on missing journals table. Body: '.$response->getContent(),
        );
        $this->assertNoValidationErrorFor($response, 'default_journal_id');
    }

    public function test_payment_method_update_default_journal_id_validator_no_longer_500s(): void
    {
        $method = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'code' => 'JNL2',
            'name' => 'Update target',
            'is_active' => true,
            'is_physical' => false,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => true,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);

        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/payment-methods/{$method->id}", [
                'default_journal_id' => Str::uuid()->toString(),
            ]);
        $this->assertNotSame(
            500,
            $response->status(),
            'default_journal_id update validator must not 500. Body: '.$response->getContent(),
        );
        $this->assertNoValidationErrorFor($response, 'default_journal_id');
    }

    // =========================================================================
    // Surface 4 same-tenant controls — proves the routes accept legit ids
    // (closes Codex Treasury Finding 4: same-tenant controls for newly-found
    // bare exists fields)
    // =========================================================================

    /**
     * Same-tenant control for split-payment validator path (callsites 049/050/058).
     */
    public function test_split_payment_accepts_same_tenant_ids(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->invoiceA->id}/split-payment", [
                'splits' => [
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                        'repository_id' => $this->repositoryA->id,
                        'instrument_id' => $this->instrumentA->id,
                    ],
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                ],
            ]);
        $this->assertNoValidationErrorFor($response, 'splits.0.payment_method_id');
        $this->assertNoValidationErrorFor($response, 'splits.0.repository_id');
        $this->assertNoValidationErrorFor($response, 'splits.0.instrument_id');
    }

    /**
     * Same-tenant control for recordDeposit validator path (callsites 051/052/053/059).
     */
    public function test_record_deposit_accepts_same_tenant_ids(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments/deposit', [
                'partner_id' => $this->partnerA->id,
                'payment_method_id' => $this->paymentMethodA->id,
                'amount' => '10.00',
                'currency' => 'EUR',
                'repository_id' => $this->repositoryA->id,
                'instrument_id' => $this->instrumentA->id,
            ]);
        $this->assertNoValidationErrorFor($response, 'partner_id');
        $this->assertNoValidationErrorFor($response, 'payment_method_id');
        $this->assertNoValidationErrorFor($response, 'repository_id');
        $this->assertNoValidationErrorFor($response, 'instrument_id');
    }

    /**
     * Same-tenant control for applyDeposit validator path (callsite 054).
     */
    public function test_apply_deposit_accepts_same_tenant_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/payments/{$this->paymentA->id}/apply-deposit", [
                'document_id' => $this->invoiceA->id,
                'amount' => '5.00',
            ]);
        $this->assertNoValidationErrorFor($response, 'document_id');
    }

    /**
     * Same-tenant control for recordPaymentOnAccount (callsite 055).
     */
    public function test_record_payment_on_account_accepts_same_tenant_partner_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments/on-account', [
                'partner_id' => $this->partnerA->id,
                'amount' => '10.00',
                'currency' => 'EUR',
            ]);
        $this->assertNoValidationErrorFor($response, 'partner_id');
    }

    /**
     * Same-tenant control for PaymentController::store instrument_id (callsite 062).
     */
    public function test_payments_store_accepts_same_tenant_instrument_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payments', $this->paymentStorePayload([
                'instrument_id' => $this->instrumentA->id,
            ]));
        $this->assertNoValidationErrorFor($response, 'instrument_id');
    }

    /**
     * Same-tenant control for PaymentRepositoryController::store responsible_user_id (callsite 056).
     */
    public function test_payment_repository_store_accepts_same_tenant_responsible_user_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/payment-repositories', [
                'code' => 'NEWO',
                'name' => 'New Repo OK',
                'type' => 'cash_register',
                'responsible_user_id' => $this->userA->id,
            ]);
        $this->assertNoValidationErrorFor($response, 'responsible_user_id');
    }

    // =========================================================================
    // Surface 3 same-tenant controls — closes Codex Treasury Finding 4
    // (the original Surface 3 service-layer tests asserted [403,404,422]
    // without paired same-tenant 200/201 controls; a route that's broken for
    // everyone would have passed. Same-tenant controls prove the route
    // reaches the production operation when properly scoped.)
    // =========================================================================

    /**
     * Same-tenant control for MultiPaymentController::createSplitPayment
     * (paired with test_multi_payment_create_split_refuses_cross_tenant_document_id).
     */
    public function test_multi_payment_create_split_accepts_same_tenant_document_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->invoiceA->id}/split-payment", [
                'splits' => [
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                    [
                        'payment_method_id' => $this->paymentMethodA->id,
                        'amount' => '5.00',
                    ],
                ],
            ]);
        // Same-tenant document_id must reach service tier — i.e. NOT 403/404
        // from a tenant-scoped findOrFail. The service may still reject for
        // unrelated business reasons (split amounts, partner mismatch, etc.).
        $this->assertNotContains(
            $response->status(),
            [403, 404],
            'Same-tenant document_id must reach service tier (no 403/404 from scoped findOrFail). '
            .'Got status '.$response->status().' body: '.$response->getContent(),
        );
    }

    /**
     * Same-tenant control for MultiPaymentController::applyDeposit
     * (paired with test_multi_payment_apply_deposit_refuses_cross_tenant_payment_id).
     */
    public function test_multi_payment_apply_deposit_accepts_same_tenant_payment_id(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/payments/{$this->paymentA->id}/apply-deposit", [
                'document_id' => $this->invoiceA->id,
                'amount' => '5.00',
            ]);
        $this->assertNotContains(
            $response->status(),
            [403, 404],
            'Same-tenant payment_id must reach service tier (no 403/404 from scoped findOrFail). '
            .'Got status '.$response->status().' body: '.$response->getContent(),
        );
    }

    /**
     * Same-tenant control for refund-prepayment service tier
     * (paired with test_refund_prepayment_refuses_cross_tenant_repository_at_service_tier).
     */
    public function test_refund_prepayment_accepts_same_tenant_repository_at_service_tier(): void
    {
        $response = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/documents/{$this->purchaseOrderA->id}/refund-prepayment", [
                'amount' => '10.00',
                'payment_method_id' => $this->paymentMethodA->id,
                'repository_id' => $this->repositoryA->id,
            ]);
        // Same-tenant must NOT 403/404 from scoped find — service may 422
        // for unrelated reasons (e.g. "no prepayment to refund"). The key is
        // that we reach the service, not that the operation succeeds.
        $this->assertNotContains(
            $response->status(),
            [403, 404],
            'Same-tenant repository_id must reach service tier (no 403/404 from scoped find). '
            .'Got status '.$response->status().' body: '.$response->getContent(),
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => "Tenant {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function makeUser(Tenant $tenant, string $email): User
    {
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test '.$email,
            'email' => $email,
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole('admin');

        return $user;
    }

    /**
     * @param  Partner|null  $existingPartner  ignored — kept for tuple typing only
     * @return array{0: Partner, 1: PaymentMethod, 2: PaymentRepository, 3: PaymentInstrument, 4: Account, 5: Document, 6: Document, 7: Payment}
     */
    private function seedTenantResources(Tenant $tenant, Company $company, ?Partner $existingPartner = null): array
    {
        UserCompanyMembership::firstOrCreate([
            'user_id' => $tenant->id === $this->tenantA->id ? $this->userA->id : $this->userB->id,
            'company_id' => $company->id,
        ], ['role' => 'admin']);

        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $method = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => strtoupper(Str::random(4)),
            'name' => 'Cash',
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
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => strtoupper(Str::random(4)),
            'name' => 'Main Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '0.00',
            'is_active' => true,
        ]);

        $instrument = PaymentInstrument::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'partner_id' => $partner->id,
            'reference' => 'INSTR-'.Str::random(6),
            'amount' => '50.00',
            'currency' => 'EUR',
            'received_date' => now(),
            'status' => InstrumentStatus::Received,
            'created_by' => $tenant->id === $this->tenantA->id ? $this->userA->id : $this->userB->id,
        ]);

        $account = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $invoice = Document::factory()
            ->posted()
            ->for($company, 'company')
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Posted,
                'document_number' => 'INV-'.strtoupper(Str::random(5)),
            ]);

        $purchaseOrder = Document::factory()
            ->purchaseOrder()
            ->confirmed()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'document_number' => 'PO-'.strtoupper(Str::random(5)),
                'balance_due' => '50.00',
                'total' => '50.00',
            ]);

        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $method->id,
            'amount' => '20.00',
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::Advance,
            'reference' => 'PMT-'.strtoupper(Str::random(5)),
        ]);

        return [$partner, $method, $repository, $instrument, $account, $invoice, $purchaseOrder, $payment];
    }

    /**
     * Assert that the response has NO validation error for `$key`. Works against
     * the project's `{ error: { code, errors: { field: [..] } } }` shape used by
     * AssertsApiValidation. A non-422 response trivially passes.
     *
     * Used by same-tenant control assertions where the FormRequest rule must
     * accept the value but the downstream service may still 422 for unrelated
     * domain reasons (e.g. "refund exceeds allocated").
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertNoValidationErrorFor(TestResponse $response, string $key): void
    {
        $json = $response->json();
        if (! is_array($json) || ! isset($json['error']['errors']) || ! is_array($json['error']['errors'])) {
            // No FormRequest validation envelope present — that itself is the
            // assertion: the validator did not flag $key (request reached the
            // service layer or returned successfully). Record an explicit
            // assertion (`assertNotSame(422, ...)` would be wrong because the
            // service tier may legitimately return 422 for unrelated business
            // reasons — see Codex round-2 trap) by asserting the response is
            // not a server error, since same-tenant ids must never 500 on a
            // path that 200/201/422-business-errored on the test setup.
            $this->assertLessThan(
                500,
                $response->status(),
                "Same-tenant control: response is 5xx for valid '{$key}' (status {$response->status()}). Body: ".$response->getContent(),
            );

            return;
        }
        $this->assertArrayNotHasKey(
            $key,
            $json['error']['errors'],
            "Same-tenant control failed: validator reported error for '{$key}'. Body: ".$response->getContent(),
        );
    }

    /**
     * Authenticate `$user` and pin the company context header to `$company`.
     *
     * Returns a TestResponse-builder ($this) so tests can chain `->postJson(...)`.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        // Re-pin permission team for tenant before request runs (Spatie team scoping)
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function paymentStorePayload(array $overrides = []): array
    {
        return array_merge([
            'partner_id' => $this->partnerA->id,
            'payment_method_id' => $this->paymentMethodA->id,
            'repository_id' => $this->repositoryA->id,
            'amount' => '10.00',
            'payment_date' => now()->toDateString(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function multiPaymentStorePayload(array $overrides = []): array
    {
        return array_merge([
            'partner_id' => $this->partnerA->id,
            'document_id' => $this->invoiceA->id,
            'payment_date' => now()->toDateString(),
            'payments' => [[
                'payment_method_id' => $this->paymentMethodA->id,
                'repository_id' => $this->repositoryA->id,
                'amount' => '10.00',
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function instrumentStorePayload(array $overrides = []): array
    {
        return array_merge([
            'payment_method_id' => $this->paymentMethodA->id,
            'reference' => 'INSTR-'.Str::random(6),
            'partner_id' => $this->partnerA->id,
            'amount' => '50.00',
            'received_date' => now()->toDateString(),
            'repository_id' => $this->repositoryA->id,
        ], $overrides);
    }
}
