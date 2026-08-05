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
use App\Modules\Partner\Application\Exceptions\UnresolvableDepositReferenceException;
use App\Modules\Partner\Application\Services\RecordCustomerDepositService;
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
use Tests\Traits\AssertsApiValidation;

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
    use AssertsApiValidation;
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

    /**
     * W-5c D1 — an unknown `payment_method_code` must 422 at the boundary, BEFORE
     * the DEPOSIT_RECEIPT is authored. Previously the fiscal event was sealed into
     * the hash chain first and the Treasury bridge only discovered the dangling
     * reference during the (post-commit) synchronous projection run, leaving a
     * permanent, undeletable orphan receipt that over-states the customer's
     * deposit history plus a 500.
     *
     * Ticket: docs/superpowers/tickets/2026-08-03-w5c-expense-income-deposit-findings.md (D1).
     */
    public function test_post_returns_422_for_an_unknown_payment_method_code_without_sealing_a_receipt(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'NOSUCHMETHOD-W5C-D1',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['payment_method_code']);
        $this->assertNoDepositWasSealed();
    }

    /**
     * W-5c D1 — the same ordering hole on `repository_id`: a syntactically valid
     * UUID that belongs to no repository (or to another company) must 422 before
     * anything is authored.
     */
    public function test_post_returns_422_for_an_unknown_repository_id_without_sealing_a_receipt(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CASH',
                'repository_id' => '00000000-0000-0000-0000-000000000000',
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['repository_id']);
        $this->assertNoDepositWasSealed();
    }

    /**
     * W-5c D1 (review escalation) — reachable by ROUTINE ops, not just malformed
     * clients: both Treasury bridge resolvers filter `is_active = true`, so
     * deactivating a payment method while a back-office user has the deposit
     * dialog open used to seal an orphan. The boundary check must carry the same
     * `is_active` predicate the bridge does.
     */
    public function test_post_returns_422_for_a_deactivated_payment_method_without_sealing_a_receipt(): void
    {
        PaymentMethod::query()
            ->where('company_id', $this->company->id)
            ->where('code', 'CASH')
            ->update(['is_active' => false]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['payment_method_code']);
        $this->assertNoDepositWasSealed();
    }

    /**
     * W-5c D1 (review escalation, repository leg) — same for a repository
     * deactivated between dialog-open and submit.
     */
    public function test_post_returns_422_for_a_deactivated_repository_without_sealing_a_receipt(): void
    {
        $this->repository->update(['is_active' => false]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['repository_id']);
        $this->assertNoDepositWasSealed();
    }

    /**
     * W-5c D1, belt-and-braces leg — a caller that BYPASSES the FormRequest (any
     * future internal caller of the application service) must still be unable to
     * seal a receipt it cannot project. The references are resolved BEFORE
     * `appendDepositReceipt(...)`, so the throw leaves no fiscal event behind.
     */
    public function test_service_refuses_to_seal_a_receipt_for_an_unresolvable_reference(): void
    {
        $service = app(RecordCustomerDepositService::class);

        try {
            $service->record(
                partner: $this->customer,
                actorUserId: $this->user->id,
                actorName: $this->user->name,
                currencyCode: 'TND',
                amount: '11.111',
                methodCode: 'NOSUCHMETHOD-W5C-D1',
                repositoryId: $this->repository->id,
                notes: null,
            );
            $this->fail('record() must refuse an unresolvable payment method before authoring the receipt.');
        } catch (UnresolvableDepositReferenceException $e) {
            $this->assertStringContainsString('NOSUCHMETHOD-W5C-D1', $e->getMessage());
        }

        $this->assertNoDepositWasSealed();
    }

    /**
     * Gate finding I-4 (PG 22P02 on a malformed `repository_id`) — REGRESSION
     * GUARD, not a reproduction: the vector is already closed by the framework.
     *
     * `Validator::shouldStopValidating()` indeed does not stop on a failed
     * non-implicit rule, but `Validator::isValidatable()` calls
     * `hasNotFailedPreviousRuleIfPresenceRule()`
     * (`vendor/laravel/framework/src/Illuminate/Validation/Validator.php:902-905`),
     * which SKIPS `Exists`/`Unique` outright whenever the attribute already
     * carries a message — its own docblock says "This is to avoid possible
     * database type comparison errors." So a malformed uuid fails `uuid` and the
     * `exists` query is never issued, on any driver. `'bail'` is nevertheless
     * prepended as an explicit, order-independent belt.
     *
     * The assertion below is the durable invariant: exactly ONE message on the
     * attribute proves the `exists` lookup did not run against a value the
     * native PostgreSQL `uuid` column cannot parse.
     */
    public function test_a_malformed_repository_id_bails_before_the_exists_lookup(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CASH',
                'repository_id' => 'not-a-uuid',
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $this->assertApiValidationErrors($response, ['repository_id']);

        $messages = $response->json('error.errors.repository_id');
        $this->assertIsArray($messages);
        $this->assertCount(
            1,
            $messages,
            'Only the format rule may report: a second message proves the `exists` lookup ran on a '
            .'malformed uuid, which is a 500 on PostgreSQL. Got: '.json_encode($messages)
        );

        $this->assertNoDepositWasSealed();
    }

    /**
     * Gate finding I-3 (currency leg) — the most reachable surviving
     * seal-before-resolve vector: plain client input, no privilege needed.
     *
     * A deposit denominated in a currency the receiving repository does not hold
     * used to seal the DEPOSIT_RECEIPT and only then blow up in
     * `TreasuryMovementService`'s currency guard, minting exactly the orphan D1
     * is about.
     *
     * The amount is scale-agnostic (`'11'`) on purpose: a 3-decimal amount would
     * be refused earlier as `INVALID_AMOUNT` against USD's scale of 2 — also
     * pre-seal, but it would not exercise the currency guard.
     */
    public function test_post_returns_422_for_a_currency_the_repository_does_not_hold(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'USD',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BUSINESS_ERROR');
        $this->assertNoDepositWasSealed();
    }

    /**
     * Gate finding I-4 (repository parity) — `TreasuryDepositBridge::resolveRepository()`
     * additionally requires `gl_account_id ?? account_id` to be non-null AND to
     * name an ACTIVE Account. The pre-flight must mirror that, or a repository
     * whose GL account was deactivated seals a receipt and then fails to project.
     */
    public function test_post_returns_422_when_the_repository_gl_account_is_inactive(): void
    {
        Account::query()
            ->whereKey($this->repository->gl_account_id)
            ->update(['is_active' => false]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BUSINESS_ERROR');
        $this->assertNoDepositWasSealed();
    }

    /**
     * Gate finding I-4 (repository parity, missing-account leg) — a repository
     * carrying neither `gl_account_id` nor the legacy `account_id` can never
     * project; refuse before sealing.
     */
    public function test_post_returns_422_when_the_repository_has_no_gl_account(): void
    {
        $this->repository->forceFill(['gl_account_id' => null, 'account_id' => null])->save();

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CASH',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'BUSINESS_ERROR');
        $this->assertNoDepositWasSealed();
    }

    /**
     * Gate finding I-6 / answer Q2 — the belt-and-braces refusal must reach the
     * client as a 422 `BUSINESS_ERROR`, not a 500. Nothing is sealed and the
     * caller's input is at fault, so a 500 would reproduce the very symptom the
     * D1 ticket calls out ("indistinguishable from an outage in monitoring").
     */
    public function test_the_service_refusal_is_a_domain_exception_so_it_maps_to_422(): void
    {
        $this->assertTrue(
            is_subclass_of(UnresolvableDepositReferenceException::class, \DomainException::class),
            'UnresolvableDepositReferenceException must extend \DomainException so bootstrap/app.php '
            .'renders it as 422 BUSINESS_ERROR.'
        );
    }

    private function assertNoDepositWasSealed(): void
    {
        $this->assertSame(
            0,
            FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->count(),
            'No DEPOSIT_RECEIPT may be sealed into the hash chain for an unresolvable reference.'
        );
        $this->assertSame(0, DB::table('pos_deposit_receipts')->count());
        $this->assertSame(0, DB::table('payments')->count());
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
            // Canonical cash GL account is gl_account_id (account_id is the dead
            // legacy column, always NULL in production — 53ec7e1c8). A ledgered
            // repository lets the deposit's customer-advance JE post, so the cash
            // movement carries it and treasury:reconcile stays green (Task 24 Fix A).
            'gl_account_id' => $cashAccount->id,
            'is_active' => true,
        ]);
    }
}
