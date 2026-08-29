<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Modules\Accounting\Application\Services\AccountingOpeningService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class BackfillCompanyPaymentRepositoriesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_30_100800_backfill_company_payment_repositories.php';

    public function test_database_without_companies_is_untouched(): void
    {
        $output = $this->runMigration();

        self::assertSame('', $output);
        self::assertSame(0, PaymentRepository::query()->count());
    }

    public function test_company_with_zero_repositories_receives_two_rows_and_is_censused(): void
    {
        [$company, $location, $cashAccount] = $this->companyShape();
        $logSpy = Log::spy();

        $this->runMigration();

        $logSpy->shouldHaveReceived('info', [
            'payment_repositories.census',
            ['companies' => 1, 'empty' => 1],
        ]);
        $logSpy->shouldHaveReceived('info', ['payment-repositories-census companies=1 empty=1']);
        $logSpy->shouldHaveReceived('info', ["payment-repositories-seeded company_id={$company->id}"]);
        $repositories = PaymentRepository::query()->where('company_id', $company->id)->orderBy('code')->get();
        self::assertSame(['CASH-01', 'SAFE-01'], $repositories->pluck('code')->all());
        foreach ($repositories as $repository) {
            self::assertSame($location->id, $repository->location_id);
            self::assertSame($cashAccount->id, $repository->account_id);
            self::assertSame($cashAccount->id, $repository->gl_account_id);
            self::assertSame(0, bccomp($repository->balance, '0', 3));
        }
        $this->assertProvisioningDidNotBookMoney($company);
    }

    /**
     * Tenant migrations run synchronously during registration. Web execution
     * must log its census, never write into the POST /auth/register JSON body.
     */
    public function test_web_execution_emits_no_stdout(): void
    {
        $this->companyShape();
        App::shouldReceive('runningInConsole')->twice()->andReturnFalse();

        self::assertSame('', $this->runMigration());
    }

    public function test_already_correct_company_reports_empty_zero_and_writes_nothing(): void
    {
        [$company, $location, $cashAccount] = $this->companyShape();
        $this->repository($company, $location, $cashAccount, 'CASH-01', RepositoryType::CashRegister);
        $this->repository($company, $location, $cashAccount, 'SAFE-01', RepositoryType::Safe);
        $idsBefore = PaymentRepository::query()->orderBy('code')->pluck('id')->all();
        $logSpy = Log::spy();

        $this->runMigration();

        $logSpy->shouldHaveReceived('info', ['payment-repositories-census companies=1 empty=0']);
        $logSpy->shouldNotHaveReceived('info', ["payment-repositories-seeded company_id={$company->id}"]);
        self::assertSame($idsBefore, PaymentRepository::query()->orderBy('code')->pluck('id')->all());
    }

    public function test_second_up_is_a_clean_no_op(): void
    {
        $this->companyShape();
        $this->runMigration();
        $idsBefore = PaymentRepository::query()->orderBy('code')->pluck('id')->all();
        $logSpy = Log::spy();

        $this->runMigration();

        $logSpy->shouldHaveReceived('info', ['payment-repositories-census companies=1 empty=0']);
        self::assertSame($idsBefore, PaymentRepository::query()->orderBy('code')->pluck('id')->all());
    }

    public function test_partial_company_receives_only_the_missing_safe(): void
    {
        [$company, $location, $cashAccount] = $this->companyShape();
        $cash = $this->repository(
            $company,
            $location,
            $cashAccount,
            'CASH-01',
            RepositoryType::CashRegister,
        );
        $logSpy = Log::spy();

        $this->runMigration();

        $logSpy->shouldHaveReceived('info', ['payment-repositories-census companies=1 empty=0']);
        $logSpy->shouldHaveReceived('info', ["payment-repositories-seeded company_id={$company->id}"]);
        self::assertSame(2, PaymentRepository::query()->where('company_id', $company->id)->count());
        self::assertSame($cash->id, PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('code', 'CASH-01')
            ->value('id'));
        self::assertTrue(PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('code', 'SAFE-01')
            ->exists());
    }

    /**
     * @return array{0: Company, 1: Location, 2: Account}
     */
    private function companyShape(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create([
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
        ]);
        $location = Location::factory()->for($company)->create([
            'code' => 'MAIN',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => true,
        ]);
        $cashAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => '531',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
        ]);

        return [$company, $location, $cashAccount];
    }

    private function repository(
        Company $company,
        Location $location,
        Account $cashAccount,
        string $code,
        RepositoryType $type,
    ): PaymentRepository {
        return PaymentRepository::forceCreate([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'code' => $code,
            'name' => $code,
            'type' => $type->value,
            'location_id' => $location->id,
            'account_id' => $cashAccount->id,
            'gl_account_id' => $cashAccount->id,
            'is_active' => true,
        ]);
    }

    private function runMigration(): string
    {
        $path = database_path('migrations/tenant/'.self::MIGRATION);
        self::assertFileExists($path);

        $migration = require $path;
        if (! is_object($migration) || ! is_callable([$migration, 'up'])) {
            self::fail('The company payment repository migration must expose up().');
        }

        ob_start();
        Closure::fromCallable([$migration, 'up'])();
        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }

    /**
     * Provisioning is metadata-only. Opening money is written only by
     * {@see AccountingOpeningService::postBatch()},
     * which owns the journal entry, journal lines, and repository movement.
     */
    private function assertProvisioningDidNotBookMoney(Company $company): void
    {
        self::assertSame(0, DB::table('repository_movements')->where('company_id', $company->id)->count());
        self::assertSame(0, DB::table('journal_entries')->where('company_id', $company->id)->count());
        self::assertSame(0, DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)
            ->count());
    }
}
