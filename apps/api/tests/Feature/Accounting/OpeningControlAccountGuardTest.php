<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * W4-4, the other half — the GL opening batch must not restate 411/401.
 *
 * With the AR/AP open-item batches now posting their own partner-tagged entries to
 * the control accounts, an operator who ALSO states 411 and 401 in the GL opening
 * (which is what the shipped template taught, and what the first-tenant campaign
 * did) doubles both control balances and leaves the sub-ledger disagreeing with the
 * GL by exactly the opening amount. Control accounts are sub-ledger territory:
 * their cutover balance is the SUM of the open items, one line per partner, and it
 * can only come from the open-item batch.
 *
 * The refusal is at VALIDATION, before anything posts, and names the batch the
 * operator should use instead.
 */
final class OpeningControlAccountGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'W43 Control Tenant',
            'slug' => 'w43-control-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Parabio Tunisie',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Opening Admin',
            'email' => 'w43-control@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_a_gl_opening_row_on_the_receivable_control_account_is_refused(): void
    {
        $receivable = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
        $counterpart = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::OpeningBalanceEquity);

        $batch = $this->makeAccountingBatch([
            ['account_code' => $receivable->code, 'debit' => '150.000', 'credit' => '0.000', 'description' => 'Clients'],
            ['account_code' => $counterpart->code, 'debit' => '0.000', 'credit' => '150.000', 'description' => 'Solde ouverture'],
        ]);

        $result = app(AccountingOpeningService::class)->validateBatch($batch->refresh());

        self::assertFalse($result['valid']);

        $refusedRow = $batch->rows()->where('row_number', 1)->firstOrFail();
        self::assertSame(OpeningImportRowStatus::Invalid, $refusedRow->status);

        $message = $refusedRow->validation_errors['account_code'][0] ?? '';
        self::assertStringContainsString($receivable->code, $message);
        self::assertStringContainsString('AR open items', $message);
    }

    public function test_a_gl_opening_row_on_the_payable_control_account_is_refused(): void
    {
        $payable = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $counterpart = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::OpeningBalanceEquity);

        $batch = $this->makeAccountingBatch([
            ['account_code' => $payable->code, 'debit' => '0.000', 'credit' => '500.000', 'description' => 'Fournisseurs'],
            ['account_code' => $counterpart->code, 'debit' => '500.000', 'credit' => '0.000', 'description' => 'Solde ouverture'],
        ]);

        $result = app(AccountingOpeningService::class)->validateBatch($batch->refresh());

        self::assertFalse($result['valid']);

        $refusedRow = $batch->rows()->where('row_number', 1)->firstOrFail();
        self::assertSame(OpeningImportRowStatus::Invalid, $refusedRow->status);
        self::assertStringContainsString(
            'AP open items',
            $refusedRow->validation_errors['account_code'][0] ?? ''
        );
    }

    public function test_an_ordinary_balance_sheet_account_is_still_accepted(): void
    {
        $cash = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        $counterpart = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::OpeningBalanceEquity);

        $batch = $this->makeAccountingBatch([
            ['account_code' => $cash->code, 'debit' => '1200.000', 'credit' => '0.000', 'description' => 'Caisse'],
            ['account_code' => $counterpart->code, 'debit' => '0.000', 'credit' => '1200.000', 'description' => 'Solde ouverture'],
        ]);

        $result = app(AccountingOpeningService::class)->validateBatch($batch->refresh());

        self::assertTrue($result['valid'], 'The guard must only refuse partner control accounts.');
        self::assertSame(2, $result['valid_rows']);
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private function makeAccountingBatch(array $rows): OpeningBalanceBatch
    {
        $batchService = app(OpeningBalanceBatchService::class);

        $batch = $batchService->createBatch(
            $this->company,
            OpeningBatchType::Accounting,
            Carbon::parse('2026-08-25'),
            'GL-'.uniqid(),
            $this->user->id,
            'phpunit',
        );

        $batchService->addImportRows($batch, $rows);

        return $batch;
    }
}
