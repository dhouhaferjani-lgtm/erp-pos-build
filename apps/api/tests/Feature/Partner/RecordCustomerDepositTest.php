<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipStatus;
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
use App\Modules\Treasury\Domain\Enums\DepositReferenceRefusal;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
     * R2-K-prev V1 — FROZEN REPOSITORY, the ops-realistic arm.
     *
     * `TreasuryDepositBridge::apply()` passes `allowWhileFrozen: false` for a
     * server-only DEPOSIT_RECEIPT, so `TreasuryMovementService::record()` throws
     * `RepositoryFrozenException` when `payment_repositories.frozen_at` is set —
     * and it throws POST-seal, in the projection run. A cash count freezes the
     * drawer routinely; a back-office user submitting a deposit inside the
     * freeze/reopen window used to mint a permanent hash-chained orphan.
     *
     * Ticket: 2026-08-05-deposit-residual-seal-before-resolve-vectors.md V1.
     */
    public function test_post_returns_422_for_a_frozen_repository_without_sealing_a_receipt(): void
    {
        DB::table('payment_repositories')
            ->where('id', $this->repository->id)
            ->update(['frozen_at' => now(), 'frozen_reason' => 'cash count in progress']);

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
     * R2-K-prev SCOPE GATE — the movement-port-derived refusals must NOT fire on
     * a MATURITY tender (cheque / effet).
     *
     * `TreasuryDepositBridge::apply()` takes the maturity branch at :169-174,
     * sets `shouldRecordMovement = false`, and RETURNS at :217-219 before
     * `TreasuryMovementService::record()` is ever called. Neither the freeze
     * policy nor the currency guard nor the checkpoint guard executes on that
     * path, so refusing a cheque deposit against a frozen drawer would 422 a
     * legitimate ops flow that projects perfectly well today: the customer hands
     * over a cheque while the drawer is frozen for a cash count. No cash moves,
     * so the freeze is irrelevant.
     *
     * Asserts the whole positive path: the receipt seals, the instrument is
     * created into the checks-to-collect portfolio, and NO repository movement
     * is written.
     */
    public function test_post_accepts_a_maturity_tender_deposit_against_a_frozen_repository(): void
    {
        $this->seedChequePortfolioAccount();

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'name' => 'Cheque',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        DB::table('payment_repositories')
            ->where('id', $this->repository->id)
            ->update(['frozen_at' => now(), 'frozen_reason' => 'cash count in progress']);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CHECK',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(201);

        $this->assertSame(
            1,
            FiscalEvent::query()->where('event_type', FiscalEventType::DEPOSIT_RECEIPT)->count(),
            'A maturity-tender deposit must still seal its receipt — the freeze does not apply to it.'
        );
        $this->assertSame(1, DB::table('payment_instruments')->count(), 'The cheque must be received into the portfolio.');
        $this->assertSame(
            0,
            DB::table('repository_movements')->count(),
            'A maturity leg never touches the cash drawer, which is exactly why the freeze must not refuse it.'
        );
    }

    /**
     * R2-K-prev round-2 gate — the maturity path has its OWN post-seal
     * invariants, and skipping the movement-port refusals must not skip THOSE.
     *
     * `InstrumentLifecycleService::receive()` refuses an instrument whose
     * currency is not the COMPANY currency (`InstrumentLifecycleService.php:74-80`)
     * — note the operand: company, NOT repository. It throws inside the
     * projection, POST-seal.
     *
     * Reachable from plain client input: `RecordDepositRequest.php:92` accepts
     * any `size:3` currency and `PartnerDepositController.php:57-59` forwards it
     * verbatim. Amount is `'11'` so it survives the controller's currency-scale
     * check against EUR (scale 2) and actually reaches the vector.
     */
    public function test_post_returns_422_for_a_maturity_tender_in_a_non_company_currency(): void
    {
        $this->seedChequePortfolioAccount();

        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'name' => 'Cheque',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11',
                'payment_method_code' => 'CHECK',
                'repository_id' => $this->repository->id,
                'currency' => 'EUR',
            ],
        );

        $response->assertStatus(422);
        $this->assertNoDepositWasSealed();
    }

    /**
     * R2-K-prev round-2 gate, second maturity invariant — the portfolio account
     * the cheque posts into must resolve.
     *
     * `HandlesMaturityTenderLeg::portfolioAccountId()` calls
     * `InstrumentAccountResolver::resolveOrFail()` (`InstrumentAccountResolver.php:35-39`),
     * which throws `MissingInstrumentAccountException` when the company has no
     * active checks-to-collect account — POST-seal, inside the projection.
     *
     * Ops-realistic: a company whose chart of accounts was never seeded with
     * `5312` (TN) / `5112` accepts cheques from the UI and orphans every one.
     * Deliberately does NOT call `seedChequePortfolioAccount()`.
     */
    public function test_post_returns_422_for_a_maturity_tender_with_no_portfolio_account(): void
    {
        PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CHECK',
            'name' => 'Cheque',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            [
                'amount' => '11.111',
                'payment_method_code' => 'CHECK',
                'repository_id' => $this->repository->id,
                'currency' => 'TND',
            ],
        );

        $response->assertStatus(422);
        $this->assertNoDepositWasSealed();
    }

    /**
     * R2-K-prev, authz gate I-2 — the movement port's THIRD rejecting guard.
     *
     * `TreasuryDepositBridge.php:273` passes `allowBehindCheckpoint =
     * ! isServerOnly()`, which is CONSTANT FALSE for a DEPOSIT_RECEIPT, so
     * `TreasuryMovementService::checkpointDisposition()` (:852-874) throws
     * `RepositoryCheckpointException` for any movement whose occurrence date is
     * not strictly after `payment_repositories.last_reconciled_at` — POST-seal.
     *
     * Reachable without any privilege: `StatementCompletionService` stamps
     * `last_reconciled_at` at end-of-day of the statement's `period_end`, and
     * `ConfirmBankStatementRequest` puts no upper bound on `period_end`. Confirm
     * a statement through today and EVERY subsequent same-day cash deposit
     * seals and then orphans.
     */
    public function test_post_returns_422_for_a_repository_reconciled_through_today_without_sealing_a_receipt(): void
    {
        DB::table('payment_repositories')
            ->where('id', $this->repository->id)
            ->update(['last_reconciled_at' => now()->endOfDay()]);

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
     * R2-K-prev, authz gate m-2 / fiscal gate m-4 — DENY-PATH PIN.
     *
     * The V2 reachability answer ("`can:payments.create` is tenant-team scoped
     * and therefore company-blind; what actually closes the non-member path is
     * `CompanyContextMiddleware`") lived only in the lane report. Pin it:
     * a user who HOLDS `payments.create` but whose company membership was
     * revoked BEFORE the request never reaches the controller, on both
     * company-resolution paths.
     *
     * Without `X-Company-Id` the user has no active membership to default to
     * (`CompanyContext::getDefaultCompanyForUser()` filters on Active), so the
     * middleware answers `NO_COMPANY_ACCESS`; with an explicit header,
     * `userHasAccessToCompany()` rejects it as `COMPANY_ACCESS_DENIED`.
     */
    public function test_a_revoked_member_holding_payments_create_is_denied_before_the_controller(): void
    {
        $this->assertTrue(
            $this->user->can('payments.create'),
            'The pin is only meaningful while the actor still holds the permission the route gates on.'
        );

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['status' => MembershipStatus::Revoked->value]);

        $payload = [
            'amount' => '11.111',
            'payment_method_code' => 'CASH',
            'repository_id' => $this->repository->id,
            'currency' => 'TND',
        ];

        $withoutHeader = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            $payload,
        );
        $withoutHeader->assertStatus(403);
        $withoutHeader->assertJsonPath('error.code', 'NO_COMPANY_ACCESS');

        $withHeader = $this->actingAs($this->user, 'sanctum')->postJson(
            "/api/v1/partners/{$this->customer->id}/deposits",
            $payload,
            ['X-Company-Id' => $this->company->id],
        );
        $withHeader->assertStatus(403);
        $withHeader->assertJsonPath('error.code', 'COMPANY_ACCESS_DENIED');

        $this->assertNoDepositWasSealed();
    }

    /**
     * R2-K-prev V1, NARROWING proof — a freeze that lands AFTER the pre-flight
     * read must still be refused, because the pre-flight is re-run INSIDE the
     * transaction that seals the event.
     *
     * The freeze is applied from a one-shot `TransactionBeginning` listener,
     * which fires straight after `beginTransaction()` — so the write lands
     * between the pre-transaction pre-flight and the append. If the only check
     * were the pre-transaction one, the receipt would be sealed here and the
     * assertion below would fail.
     *
     * **What this probe does and does not model (fiscal gate m-3).** The
     * listener runs on the SAME connection, INSIDE the sealing transaction: it
     * never independently commits, and it is rolled back with everything else.
     * So this models a same-connection write interleaved at the right instant —
     * enough to prove the in-transaction re-verification exists and aborts
     * cleanly. It is NOT a concurrent external committer, which no
     * single-connection test can simulate, and which is precisely the residual
     * race below.
     *
     * This NARROWS the race to the width of the sealing transaction; it does NOT
     * close it — the projection runs after that transaction commits, so a freeze
     * landing in the gap still mints an orphan. Recoverability is R2-K-rec.
     */
    public function test_a_freeze_landing_after_the_pre_flight_is_refused_inside_the_sealing_transaction(): void
    {
        $service = app(RecordCustomerDepositService::class);
        $fired = false;

        Event::listen(TransactionBeginning::class, function () use (&$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;

            DB::table('payment_repositories')
                ->where('id', $this->repository->id)
                ->update(['frozen_at' => now(), 'frozen_reason' => 'frozen mid-request']);
        });

        try {
            $service->record(
                partner: $this->customer,
                actorUserId: $this->user->id,
                actorName: $this->user->name,
                currencyCode: 'TND',
                amount: '11.111',
                methodCode: 'CASH',
                repositoryId: $this->repository->id,
                notes: null,
            );
            $this->fail('record() must re-verify the freeze inside the sealing transaction.');
        } catch (UnresolvableDepositReferenceException $e) {
            $this->assertSame(DepositReferenceRefusal::RepositoryFrozen, $e->refusal);
        }

        $this->assertTrue($fired, 'The freeze must be applied from inside the sealing transaction to be a valid probe.');
        $this->assertNoDepositWasSealed();
    }

    /**
     * R2-K-prev V2 — ACTOR WITHOUT AN ACTIVE COMPANY MEMBERSHIP.
     *
     * `TreasuryDepositBridge::resolveActorUserId()` requires the payload actor to
     * be an `Active` member of the event's company and throws otherwise — POST
     * seal. The refusal must move ahead of the seal.
     *
     * Driven at the service level on purpose: over HTTP the request-entry
     * `CompanyContextMiddleware::userHasAccessToCompany()` already 403s a
     * non-member, so this leg is the belt-and-braces guard for the mid-request
     * revocation and for any internal (non-HTTP) caller.
     */
    public function test_service_refuses_a_deposit_whose_actor_has_no_active_company_membership(): void
    {
        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['status' => MembershipStatus::Revoked->value]);

        $service = app(RecordCustomerDepositService::class);

        try {
            $service->record(
                partner: $this->customer,
                actorUserId: $this->user->id,
                actorName: $this->user->name,
                currencyCode: 'TND',
                amount: '11.111',
                methodCode: 'CASH',
                repositoryId: $this->repository->id,
                notes: null,
            );
            $this->fail('record() must refuse an actor without an active company membership before authoring the receipt.');
        } catch (UnresolvableDepositReferenceException $e) {
            $this->assertSame(DepositReferenceRefusal::ActorNotActiveCompanyMember, $e->refusal);
            $this->assertStringContainsString($this->user->id, $e->getMessage());
        }

        $this->assertNoDepositWasSealed();
    }

    /**
     * R2-K-prev V2, NARROWING proof over the real HTTP surface — the membership
     * is revoked AFTER `CompanyContextMiddleware` and after the pre-transaction
     * pre-flight, from a one-shot `TransactionBeginning` listener. Only the
     * in-transaction re-verification can catch it, and it must land as a 422
     * with nothing sealed rather than a 500 plus an orphan.
     *
     * Same probe semantics and same honest scope as the freeze leg above: a
     * same-connection interleaved write, not a concurrent committer; the window
     * is narrowed to the sealing transaction, not closed.
     */
    public function test_post_refuses_a_membership_revoked_after_the_pre_flight_without_sealing_a_receipt(): void
    {
        $fired = false;

        Event::listen(TransactionBeginning::class, function () use (&$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;

            DB::table('user_company_memberships')
                ->where('user_id', $this->user->id)
                ->where('company_id', $this->company->id)
                ->update(['status' => MembershipStatus::Revoked->value]);
        });

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
        $this->assertTrue($fired, 'The revocation must be applied from inside the sealing transaction to be a valid probe.');
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

    /**
     * The checks-to-collect portfolio account a cheque deposit posts into.
     *
     * `InstrumentAccountResolver` looks it up by (company, code, type, active),
     * and the code is country-derived: `5312` for TN (this fixture's company),
     * `5112` elsewhere.
     */
    private function seedChequePortfolioAccount(): void
    {
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5312',
            'name' => 'Cheques to collect',
            'type' => 'asset',
            'is_active' => true,
        ]);
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
