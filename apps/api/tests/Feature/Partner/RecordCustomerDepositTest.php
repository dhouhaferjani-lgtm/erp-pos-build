<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerAccountStatus;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 5 full-flow — POST/GET /api/v1/partners/{partner}/deposits.
 *
 * Drives the entire back-office deposit pipeline end-to-end over HTTP: author the
 * DEPOSIT_RECEIPT, dispatch + synchronously run the projection pipeline (printable
 * receipt + Treasury FIFO allocation), and return the receipt + allocation summary
 * + allocation summary. Uses the pure-advance path (no open invoices); the FIFO
 * settle-vs-overflow math is proven by the shared PaymentAllocationService suite +
 * the TreasuryDepositBridge command-shape tests (anti-divergence, spec §2.2).
 *
 * The settled/credited split is read from the persisted payment allocations (exact
 * at write time). Cached partner balances reflect POSTED GL only — the customer-
 * advance entry is created as a draft and updates the balance once the accounting
 * cycle posts it — so this test asserts the allocation split + the drafted advance
 * journal line, not a synchronous balance bump.
 */
final class RecordCustomerDepositTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private PaymentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Deposit Tenant',
            'slug' => 'deposit-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Deposit Co',
            'legal_name' => 'Deposit Co SARL',
            'tax_id' => 'TAX-DEP-1',
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        Location::factory()->create(['company_id' => $this->company->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Back Office Owner',
            'email' => 'owner@deposit.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Mariam Ben Ali',
            'customer_category' => CustomerCategory::Business,
            'account_status' => CustomerAccountStatus::Active,
            'account_status_version' => 1,
        ]);

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $this->seedChartOfAccounts();
    }

    public function test_post_records_a_pure_advance_deposit_end_to_end_and_lists_it(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '120',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
                'note' => 'Paid in cash at the depot',
            ],
        );

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '120.000');
        $response->assertJsonPath('data.currency_code', 'TND');
        // No open invoices → nothing settled, the whole amount overflows to credit.
        $response->assertJsonPath('data.settled_amount', '0.000');
        $response->assertJsonPath('data.credited_amount', '120.000');

        $depositReceiptUuid = $response->json('data.deposit_receipt_uuid');
        $this->assertIsString($depositReceiptUuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $depositReceiptUuid,
        );

        // Fiscal event sealed, printable receipt projected, back-office payment created.
        $event = FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->sole();
        $this->assertSame($this->customer->id, $event->partner_id);
        $this->assertDatabaseHas('pos_deposit_receipts', [
            'fiscal_event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'amount' => '120.000',
        ]);
        $this->assertDatabaseHas('payments', [
            'fiscal_event_id' => $event->id,
            'partner_id' => $this->customer->id,
            'origin' => PaymentOrigin::BackOffice->value,
            'amount' => '120.000',
            'created_by' => $this->user->id,
        ]);

        // The overflow posted a customer-advance GL line crediting the customer
        // (the cached credit balance updates once the accounting cycle posts it).
        $advanceCredit = DB::table('journal_lines as l')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.system_purpose', SystemAccountPurpose::CustomerAdvance->value)
            ->where('l.partner_id', $this->customer->id)
            ->value('l.credit');
        $this->assertNotNull($advanceCredit);
        $this->assertSame(0, bccomp((string) $advanceCredit, '120', 3));

        // History lists the recorded deposit.
        $history = $this->actingAs($this->user, 'sanctum')->getJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
        );
        $history->assertOk();
        $history->assertJsonPath('meta.total', 1);
        $history->assertJsonPath('data.0.deposit_receipt_uuid', $depositReceiptUuid);
        $history->assertJsonPath('data.0.amount', '120.000');
        $history->assertJsonPath('data.0.payment_method_code', 'CASH');
        $history->assertJsonPath('data.0.actor_name', 'Back Office Owner');
        $history->assertJsonPath('data.0.note', 'Paid in cash at the depot');
    }

    public function test_post_returns_422_for_a_zero_amount(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '0',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_AMOUNT');
        $this->assertSame(0, FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->count());
    }

    public function test_post_returns_422_for_an_amount_more_precise_than_the_currency(): void
    {
        // TND has scale 3; a 4th non-zero decimal is over-precise.
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '10.0001',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'INVALID_AMOUNT');
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_post_rejects_a_deposit_against_a_non_customer_partner(): void
    {
        $supplier = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => PartnerType::Supplier,
            'name' => 'Parts Wholesaler',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$supplier->id}/deposits",
            [
                'amount' => '50',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PARTNER_NOT_CUSTOMER');
        $this->assertSame(0, FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->count());
    }

    public function test_post_requires_the_payments_create_permission(): void
    {
        $stranger = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permission',
            'email' => 'noperm@deposit.test',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $stranger->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($stranger, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '50',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
            ],
        );

        $response->assertForbidden();
        $this->assertSame(0, DB::table('payments')->count());
    }

    private function seedChartOfAccounts(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customers',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '531',
            'name' => 'Cash',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '419',
            'name' => 'Customer Advances',
            'type' => 'liability',
            'system_purpose' => SystemAccountPurpose::CustomerAdvance,
            'is_active' => true,
        ]);

        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'DRAWER-1',
            'name' => 'Drawer 1',
            'account_id' => $cashAccount->id,
            'is_active' => true,
        ]);
    }
}
