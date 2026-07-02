<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\BankReconciliationItem;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private User $unauthorizedUser;

    private PaymentMethod $cashMethod;

    private PaymentRepository $bankAccount;

    private Partner $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reconciliation Tenant',
            'slug' => 'reconciliation-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Reconciliation Company',
            'legal_name' => 'Reconciliation Company LLC',
            'tax_id' => 'TAX-RECON-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Treasury Manager',
            'email' => 'treasury@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'repositories.view',
            'repositories.manage',
            'payments.view',
            'payments.create',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->unauthorizedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permissions User',
            'email' => 'noperm@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->unauthorizedUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BANK_TRANSFER',
            'name' => 'Bank Transfer',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->bankAccount = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BNK-001',
            'name' => 'Main Bank Account',
            'type' => RepositoryType::BankAccount,
            'bank_name' => 'Test Bank',
            'account_number' => '1234567890',
            'balance' => '5000.00',
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Customer Corp',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);
    }

    /**
     * Helper: create a payment assigned to the bank account repository.
     */
    private function createPayment(string $amount, string $reference, bool $isReconciled = false): Payment
    {
        return Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'repository_id' => $this->bankAccount->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => $reference,
            'created_by' => $this->user->id,
        ]);
    }

    // ---------------------------------------------------------------
    // 1. Start Reconciliation Session
    // ---------------------------------------------------------------

    public function test_can_start_reconciliation_session(): void
    {
        $this->createPayment('1000.00', 'PMT-001');
        $this->createPayment('500.00', 'PMT-002');

        $response = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
            'notes' => 'March reconciliation',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.statement_balance', '5000.000');
        $response->assertJsonPath('data.notes', 'March reconciliation');

        // Should include both unreconciled payments as items
        $response->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('bank_reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'status' => 'draft',
        ]);
    }

    public function test_start_reconciliation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', []);

        // Empty payload should be rejected — repository_id, statement_date, statement_balance are required
        $response->assertStatus(422);
    }

    public function test_start_reconciliation_excludes_already_reconciled_payments(): void
    {
        // Create a reconciled payment (manually set the DB column)
        $reconciledPayment = $this->createPayment('300.00', 'PMT-RECONCILED');
        $reconciledPayment->forceFill(['is_reconciled' => true, 'reconciled_at' => now()])->save();

        // Create an unreconciled payment
        $this->createPayment('700.00', 'PMT-UNRECONCILED');

        $response = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);

        $response->assertStatus(201);
        // Only the unreconciled payment should appear
        $response->assertJsonCount(1, 'data.items');
    }

    // ---------------------------------------------------------------
    // 2. Match Payment Items
    // ---------------------------------------------------------------

    public function test_can_match_payment_item(): void
    {
        $payment = $this->createPayment('1000.00', 'PMT-001');

        // Start reconciliation
        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Match the payment
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment->id}",
            [
                'bank_reference' => 'BANK-REF-001',
                'notes' => 'Matched via statement',
            ]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_matched', true);
        $response->assertJsonPath('data.bank_reference', 'BANK-REF-001');

        $this->assertDatabaseHas('bank_reconciliation_items', [
            'reconciliation_id' => $reconciliationId,
            'payment_id' => $payment->id,
            'is_matched' => true,
            'bank_reference' => 'BANK-REF-001',
        ]);
    }

    // ---------------------------------------------------------------
    // 3. Unmatch Payment Items
    // ---------------------------------------------------------------

    public function test_can_unmatch_payment_item(): void
    {
        $payment = $this->createPayment('1000.00', 'PMT-001');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Match then unmatch
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment->id}",
            ['bank_reference' => 'REF-001']
        );

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/unmatch/{$payment->id}"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.is_matched', false);
        $response->assertJsonPath('data.bank_reference', null);

        $this->assertDatabaseHas('bank_reconciliation_items', [
            'reconciliation_id' => $reconciliationId,
            'payment_id' => $payment->id,
            'is_matched' => false,
            'bank_reference' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // 4. Get Reconciliation Summary
    // ---------------------------------------------------------------

    public function test_can_get_reconciliation_summary(): void
    {
        $payment1 = $this->createPayment('1000.00', 'PMT-001');
        $payment2 = $this->createPayment('2000.00', 'PMT-002');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '3000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Match only the first payment
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment1->id}"
        );

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/summary"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.matched_count', 1);
        $response->assertJsonPath('data.unmatched_count', 1);
        $response->assertJsonPath('data.status', 'draft');
    }

    // ---------------------------------------------------------------
    // 5. Complete Reconciliation
    // ---------------------------------------------------------------

    public function test_can_complete_reconciliation(): void
    {
        // The opening_balance is last_reconciled_balance ?? '0.00' = '0.00'
        // We create a payment of 5000.00 and set statement_balance to '5000.00'
        // After matching: expectedBalance = opening(0) + matched(5000) = 5000
        // difference = statement(5000) - expected(5000) = 0 => can complete
        $payment = $this->createPayment('5000.00', 'PMT-FULL');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Match the payment so difference becomes 0
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment->id}"
        );

        // Complete
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/complete"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('bank_reconciliations', [
            'id' => $reconciliationId,
            'status' => 'completed',
        ]);

        // Verify repository last_reconciled_balance updated
        $this->bankAccount->refresh();
        $this->assertEquals('5000.000', $this->bankAccount->last_reconciled_balance);
        $this->assertNotNull($this->bankAccount->last_reconciled_at);
    }

    public function test_explicit_opening_balance_makes_zero_difference_complete_reachable(): void
    {
        $payment = $this->createPayment('500.000', 'PMT-OPENING');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'opening_balance' => '25000.000',
            'statement_balance' => '25500.000',
        ]);

        $startResponse->assertStatus(201);
        $startResponse->assertJsonPath('data.opening_balance', '25000.000');
        $startResponse->assertJsonPath('data.difference', '500.000');

        $reconciliationId = $startResponse->json('data.id');

        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment->id}"
        )->assertStatus(200);

        $summaryResponse = $this->actingAs($this->user)->getJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/summary"
        );

        $summaryResponse->assertStatus(200);
        $summaryResponse->assertJsonPath('data.opening_balance', '25000.000');
        $summaryResponse->assertJsonPath('data.closing_balance', '25500.000');
        $summaryResponse->assertJsonPath('data.difference', '0.000');
        $summaryResponse->assertJsonPath('data.can_complete', true);

        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/complete"
        )->assertStatus(200);
    }

    public function test_start_reconciliation_rejects_opening_balance_over_three_decimals(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'opening_balance' => '25000.1234',
            'statement_balance' => '25500.000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.opening_balance.0', 'Opening balance must have at most 3 decimal places.');
    }

    public function test_complete_reconciliation_marks_matched_payments_as_reconciled(): void
    {
        $payment1 = $this->createPayment('3000.00', 'PMT-REC-1');
        $payment2 = $this->createPayment('2000.00', 'PMT-REC-2');

        // Start reconciliation with statement_balance matching total payments
        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Match both payments
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment1->id}"
        );
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment2->id}"
        );

        // Complete
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/complete"
        );
        $response->assertStatus(200);

        // Both payments should now be marked as reconciled
        $payment1->refresh();
        $payment2->refresh();

        $this->assertTrue($payment1->is_reconciled);
        $this->assertNotNull($payment1->reconciled_at);
        $this->assertTrue($payment2->is_reconciled);
        $this->assertNotNull($payment2->reconciled_at);
    }

    // ---------------------------------------------------------------
    // 6. Cancel Reconciliation
    // ---------------------------------------------------------------

    public function test_can_cancel_reconciliation(): void
    {
        $this->createPayment('1000.00', 'PMT-001');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/cancel"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('bank_reconciliations', [
            'id' => $reconciliationId,
            'status' => 'cancelled',
        ]);
    }

    // ---------------------------------------------------------------
    // 7. Prevent Editing Completed Reconciliation
    // ---------------------------------------------------------------

    public function test_cannot_match_items_on_completed_reconciliation(): void
    {
        $payment = $this->createPayment('5000.00', 'PMT-FULL');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Match and complete
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment->id}"
        );
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/complete"
        );

        // Try to match again — should fail
        $newPayment = $this->createPayment('200.00', 'PMT-NEW');

        // Create an item manually for the new payment since it wasn't in the reconciliation
        BankReconciliationItem::create([
            'reconciliation_id' => $reconciliationId,
            'payment_id' => $newPayment->id,
            'is_matched' => false,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$newPayment->id}"
        );

        // The service throws a RuntimeException — the controller should return 500
        $response->assertStatus(500);
    }

    public function test_cannot_cancel_completed_reconciliation(): void
    {
        $payment = $this->createPayment('5000.00', 'PMT-FULL');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment->id}"
        );
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/complete"
        );

        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/cancel"
        );

        $response->assertStatus(500);
    }

    public function test_cannot_unmatch_items_on_cancelled_reconciliation(): void
    {
        $payment = $this->createPayment('1000.00', 'PMT-001');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Match, then cancel the reconciliation
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/match/{$payment->id}"
        );
        $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/cancel"
        );

        // Try to unmatch — should fail
        $response = $this->actingAs($this->user)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/unmatch/{$payment->id}"
        );

        $response->assertStatus(500);
    }

    // ---------------------------------------------------------------
    // 8. Tenant Isolation
    // ---------------------------------------------------------------

    public function test_tenant_isolation_blocks_cross_tenant_access(): void
    {
        // Create reconciliation in tenant A
        $this->createPayment('1000.00', 'PMT-001');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        // Create tenant B with its own user
        $tenantB = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $companyB = Company::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX-OTHER-001',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $otherUser = User::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $otherUser->givePermissionTo(['repositories.view', 'repositories.manage']);

        UserCompanyMembership::create([
            'user_id' => $otherUser->id,
            'company_id' => $companyB->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($companyB->id);

        // Tenant B tries to view tenant A's reconciliation — should get 404
        $response = $this->actingAs($otherUser)->getJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}"
        );

        $response->assertStatus(404);
    }

    // ---------------------------------------------------------------
    // 9. Authorization
    // ---------------------------------------------------------------

    public function test_unauthorized_user_cannot_start_reconciliation(): void
    {
        $response = $this->actingAs($this->unauthorizedUser)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_cannot_complete_reconciliation(): void
    {
        $this->createPayment('5000.00', 'PMT-FULL');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        $response = $this->actingAs($this->unauthorizedUser)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/complete"
        );

        $response->assertStatus(403);
    }

    public function test_user_with_view_only_can_list_reconciliations(): void
    {
        // Give the unauthorized user just the view permission
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->unauthorizedUser->givePermissionTo('repositories.view');

        $response = $this->actingAs($this->unauthorizedUser)->getJson('/api/v1/bank-reconciliations');

        $response->assertStatus(200);
    }

    public function test_user_with_view_only_cannot_cancel_reconciliation(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->unauthorizedUser->givePermissionTo('repositories.view');

        $this->createPayment('1000.00', 'PMT-001');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        $response = $this->actingAs($this->unauthorizedUser)->postJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}/cancel"
        );

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // List and Show
    // ---------------------------------------------------------------

    public function test_can_list_reconciliations(): void
    {
        $this->createPayment('1000.00', 'PMT-001');

        $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/bank-reconciliations');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.repository_name', 'Main Bank Account');
    }

    public function test_can_filter_reconciliations_by_repository(): void
    {
        $this->createPayment('1000.00', 'PMT-001');

        $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);

        // Filter by a different repository — should return empty
        $otherRepo = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BNK-002',
            'name' => 'Other Bank',
            'type' => RepositoryType::BankAccount,
            'balance' => '0.00',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/bank-reconciliations?repository_id='.$otherRepo->id
        );

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_can_show_single_reconciliation_with_items(): void
    {
        $this->createPayment('1000.00', 'PMT-001');

        $startResponse = $this->actingAs($this->user)->postJson('/api/v1/bank-reconciliations', [
            'repository_id' => $this->bankAccount->id,
            'statement_date' => '2026-03-20',
            'statement_balance' => '5000.00',
        ]);
        $reconciliationId = $startResponse->json('data.id');

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/bank-reconciliations/{$reconciliationId}"
        );

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $reconciliationId);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'repository_id',
                'repository_name',
                'statement_date',
                'opening_balance',
                'closing_balance',
                'statement_balance',
                'difference',
                'status',
                'items',
            ],
        ]);
    }
}
