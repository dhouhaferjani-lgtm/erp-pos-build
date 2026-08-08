<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentAmountBelowCurrencyPrecisionException;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentToleranceAccountMissingException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Shared\Contracts\Treasury\RepositoryAdjustmentServiceInterface;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DPA lane G3, Task 1 — the V3-gate-mandated extraction of
 * `RepositoryAdjustmentService` (+ its Shared contract) out of
 * `RepositoryAdjustmentController`.
 *
 * The controller keeps its own HTTP-level coverage in
 * {@see RepositoryAdjustmentTest}; this suite drives the service DIRECTLY, with
 * NO CompanyContext bound, because the G3 shift-variance listener consumes it
 * from a listener/queue context where `CompanyContext::requireCompany()` and a
 * bare `CurrencyScaleResolver::getScale()` both throw (CLAUDE.md rules 19+20).
 */
final class RepositoryAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_posts_document_journal_entry_and_movement_with_no_company_context(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        [$repo, $cashAccount] = $this->makeRepository($user, $company, '500.000');

        // The listener/queue reality: no company bound (CLAUDE.md rule 20).
        app(CompanyContext::class)->clear();

        $adjustmentId = (string) Str::uuid();

        $result = $this->service()->post(new RepositoryAdjustmentIntent(
            repositoryId: $repo->id,
            tenantId: (string) $user->tenant_id,
            companyId: $company->id,
            direction: MovementDirection::Out,
            amount: '25.000',
            reasonCode: MovementReasonCode::CountVariance,
            reasonText: 'Till was short at close.',
            userId: $user->id,
            adjustmentId: $adjustmentId,
        ));

        $this->assertSame($adjustmentId, $result->adjustmentId);
        $this->assertFalse($result->wasIdempotentHit);

        $freshRepo = $repo->fresh();
        $this->assertNotNull($freshRepo);
        $this->assertSame('475.000', $freshRepo->balance);

        // Document exists and is cross-linked to BOTH the JE and the movement.
        $document = RepositoryAdjustment::query()->findOrFail($adjustmentId);
        $this->assertSame($result->journalEntryId, $document->journal_entry_id);
        $this->assertSame($result->movementId, $document->movement_id);
        $this->assertSame($repo->id, $document->payment_repository_id);
        $this->assertSame('TND', $document->currency);
        $this->assertSame(MovementReasonCode::CountVariance, $document->reason_code);

        // Movement points back at the document AND carries the journal entry.
        $movement = DB::table('repository_movements')->where('id', $result->movementId)->first();
        $this->assertNotNull($movement);
        $this->assertSame('adjustment', $movement->source_type);
        $this->assertSame($adjustmentId, $movement->source_id);
        $this->assertSame($result->journalEntryId, $movement->journal_entry_id);

        // The journal entry is POSTED (not left as a draft) and balanced 658/cash.
        $entry = JournalEntry::query()->whereKey($result->journalEntryId)->with('lines')->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame('repository_adjustment', $entry->source_type);
        $this->assertSame($adjustmentId, $entry->source_id);

        $toleranceExpense = $this->accountFor($user, $company, SystemAccountPurpose::PaymentToleranceExpense);
        $this->assertSame('25.000', $this->postedDebitFor($company->id, $toleranceExpense->id));
        $this->assertSame('25.000', $this->postedCreditFor($company->id, $cashAccount->id));
    }

    public function test_service_refuses_a_missing_tolerance_purpose_and_writes_nothing(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        [$repo] = $this->makeRepository($user, $company, '500.000');

        Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', SystemAccountPurpose::PaymentToleranceExpense->value)
            ->update(['system_purpose' => null]);

        app(CompanyContext::class)->clear();

        try {
            $this->service()->post($this->intentFor($repo, $user, $company, MovementDirection::Out, '25.000'));
            $this->fail('Expected AdjustmentToleranceAccountMissingException.');
        } catch (AdjustmentToleranceAccountMissingException $e) {
            $this->assertSame(SystemAccountPurpose::PaymentToleranceExpense, $e->purpose);
        }

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame('500.000', $repo->fresh()?->balance);
    }

    public function test_service_refuses_an_amount_below_currency_precision_before_any_write(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        [$repo] = $this->makeRepository($user, $company, '500.000');

        app(CompanyContext::class)->clear();

        try {
            $this->service()->post($this->intentFor($repo, $user, $company, MovementDirection::Out, '0.0004'));
            $this->fail('Expected AdjustmentAmountBelowCurrencyPrecisionException.');
        } catch (AdjustmentAmountBelowCurrencyPrecisionException $e) {
            $this->assertSame('0.0004', $e->requestedAmount);
            $this->assertSame('TND', $e->currency);
            $this->assertSame(3, $e->scale);
        }

        $this->assertSame(0, RepositoryAdjustment::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->count());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
    }

    public function test_service_replay_of_the_same_adjustment_id_writes_exactly_one_of_each_artifact(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        [$repo] = $this->makeRepository($user, $company, '500.000');

        app(CompanyContext::class)->clear();

        $adjustmentId = (string) Str::uuid();
        $intent = $this->intentFor($repo, $user, $company, MovementDirection::In, '10.000', $adjustmentId);

        $first = $this->service()->post($intent);
        $second = $this->service()->post($intent);

        $this->assertFalse($first->wasIdempotentHit);
        $this->assertTrue($second->wasIdempotentHit);
        $this->assertSame($first->movementId, $second->movementId);
        $this->assertSame($first->journalEntryId, $second->journalEntryId);

        $this->assertSame(1, RepositoryAdjustment::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'repository_adjustment')->count());
        $this->assertSame('510.000', $repo->fresh()?->balance);
    }

    public function test_service_stamps_the_originating_pos_shift_on_the_document(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        [$repo] = $this->makeRepository($user, $company, '500.000');

        app(CompanyContext::class)->clear();

        $shiftId = (string) Str::uuid();
        $adjustmentId = (string) Str::uuid();

        $this->service()->post(new RepositoryAdjustmentIntent(
            repositoryId: $repo->id,
            tenantId: (string) $user->tenant_id,
            companyId: $company->id,
            direction: MovementDirection::In,
            amount: '3.000',
            reasonCode: MovementReasonCode::CountVariance,
            reasonText: 'Shift close cash over.',
            userId: $user->id,
            adjustmentId: $adjustmentId,
            posShiftId: $shiftId,
            idempotencyLeg: 'shift:'.$shiftId,
        ));

        $document = RepositoryAdjustment::query()->findOrFail($adjustmentId);
        $this->assertSame($shiftId, $document->pos_shift_id);

        $movement = DB::table('repository_movements')->where('source_id', $adjustmentId)->first();
        $this->assertNotNull($movement);
        $this->assertSame("adjustment:{$adjustmentId}:shift:{$shiftId}", $movement->idempotency_key);
    }

    /**
     * Gate finding M5 — the extraction newly re-queries the acting user
     * tenant-scoped, where the controller previously trusted the already
     * authenticated `$request->user()`. Pin the behaviour so the narrowing is a
     * decision rather than an accident: a user id outside the intent's tenant is
     * a miss, not a cross-tenant write.
     */
    public function test_an_acting_user_outside_the_intent_tenant_is_refused_and_writes_nothing(): void
    {
        [$user, $company] = $this->makeUserAndCompany();
        [$repo] = $this->makeRepository($user, $company, '500.000');
        [$foreignUser] = $this->makeUserAndCompany();

        app(CompanyContext::class)->clear();

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->service()->post(new RepositoryAdjustmentIntent(
                repositoryId: $repo->id,
                tenantId: (string) $user->tenant_id,
                companyId: $company->id,
                direction: MovementDirection::Out,
                amount: '25.000',
                reasonCode: MovementReasonCode::CountVariance,
                reasonText: 'Cross-tenant acting user.',
                userId: $foreignUser->id,
                adjustmentId: (string) Str::uuid(),
            ));
        } finally {
            $this->assertSame(0, RepositoryAdjustment::query()->count());
            $this->assertSame(0, DB::table('repository_movements')->count());
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function service(): RepositoryAdjustmentServiceInterface
    {
        return app(RepositoryAdjustmentServiceInterface::class);
    }

    private function intentFor(
        PaymentRepository $repo,
        User $user,
        Company $company,
        MovementDirection $direction,
        string $amount,
        ?string $adjustmentId = null,
    ): RepositoryAdjustmentIntent {
        return new RepositoryAdjustmentIntent(
            repositoryId: $repo->id,
            tenantId: (string) $user->tenant_id,
            companyId: $company->id,
            direction: $direction,
            amount: $amount,
            reasonCode: MovementReasonCode::CountVariance,
            reasonText: 'Count variance.',
            userId: $user->id,
            adjustmentId: $adjustmentId ?? (string) Str::uuid(),
        );
    }

    /**
     * @return array{0: PaymentRepository, 1: Account}
     */
    private function makeRepository(User $user, Company $company, string $balance): array
    {
        app(CompanyContext::class)->setCompanyId($company->id);
        app(ChartOfAccountsService::class)->seedForCompany($company);

        $cashAccount = $this->accountFor($user, $company, SystemAccountPurpose::Cash);

        $repo = PaymentRepository::factory()->create([
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'balance' => $balance,
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
        ]);

        return [$repo, $cashAccount];
    }

    private function postedDebitFor(string $companyId, string $accountId): string
    {
        return $this->postedSideFor($companyId, $accountId, 'debit');
    }

    private function postedCreditFor(string $companyId, string $accountId): string
    {
        return $this->postedSideFor($companyId, $accountId, 'credit');
    }

    private function postedSideFor(string $companyId, string $accountId, string $side): string
    {
        $total = '0.000';

        $lines = JournalLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('company_id', $companyId)
                ->where('status', JournalEntryStatus::Posted->value))
            ->get();

        foreach ($lines as $line) {
            $total = bcadd($total, (string) $line->{$side}, 3);
        }

        return $total;
    }

    private function accountFor(User $user, Company $company, SystemAccountPurpose $purpose): Account
    {
        return Account::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $company->id)
            ->where('system_purpose', $purpose->value)
            ->firstOrFail();
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function makeUserAndCompany(): array
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user_'.uniqid().'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'accountant',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$user, $company];
    }
}
