<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Exceptions\MissingInstrumentAccountException;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class PayableInstrumentAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payable_purposes_resolve_to_liability_accounts_in_every_chart(): void
    {
        $tenant = Tenant::factory()->create();
        $charts = [
            [new TunisiaChartOfAccountsSeeder, Company::factory()->tunisia()->create(['tenant_id' => $tenant->id])],
            [new FranceChartOfAccountsSeeder, Company::factory()->create(['tenant_id' => $tenant->id, 'country_code' => 'FR'])],
            [new GenericChartOfAccountsSeeder, Company::factory()->create(['tenant_id' => $tenant->id, 'country_code' => 'US'])],
        ];
        $resolver = $this->app->make(InstrumentAccountResolver::class);

        foreach ($charts as [$seeder, $company]) {
            $seeder->run($company->id, $tenant->id);

            $checks = Account::query()->findOrFail($resolver->resolveOrFail(
                InstrumentAccountPurpose::ChecksToPay,
                $company->id,
            ));
            $effects = Account::query()->findOrFail($resolver->resolveOrFail(
                InstrumentAccountPurpose::EffetsPayable,
                $company->id,
            ));

            self::assertSame('4035', $checks->code);
            self::assertSame(AccountType::Liability, $checks->type);
            self::assertTrue($checks->is_system);
            self::assertSame('403', $effects->code);
            self::assertSame(AccountType::Liability, $effects->type);
            self::assertTrue($effects->is_system);
        }
    }

    public function test_missing_payable_purpose_fails_loudly(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $resolver = $this->app->make(InstrumentAccountResolver::class);

        self::assertNull($resolver->resolve(InstrumentAccountPurpose::ChecksToPay, $company->id));

        $this->expectException(MissingInstrumentAccountException::class);
        $resolver->resolveOrFail(InstrumentAccountPurpose::ChecksToPay, $company->id);
    }

    public function test_backfill_is_dry_run_safe_and_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        (new TunisiaChartOfAccountsSeeder)->run($company->id, $tenant->id);
        Account::query()->where('company_id', $company->id)->where('code', '4035')->delete();

        $this->artisanCommand('treasury:backfill-payable-instrument-accounts', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('[DRY-RUN]');
        self::assertFalse(Account::query()->where('company_id', $company->id)->where('code', '4035')->exists());

        $this->artisanCommand('treasury:backfill-payable-instrument-accounts')->assertSuccessful();
        $this->artisanCommand('treasury:backfill-payable-instrument-accounts')->assertSuccessful();

        $checks = Account::query()->where('company_id', $company->id)->where('code', '4035')->sole();
        self::assertSame(AccountType::Liability, $checks->type);
        self::assertSame('40', $checks->parent?->code);
        self::assertSame(1, Account::query()->where('company_id', $company->id)->where('code', '4035')->count());
    }

    public function test_backfill_reports_and_skips_an_existing_wrong_type_account(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        (new TunisiaChartOfAccountsSeeder)->run($company->id, $tenant->id);
        Account::query()
            ->where('company_id', $company->id)
            ->where('code', '403')
            ->update(['type' => AccountType::Asset->value]);

        $this->artisanCommand('treasury:backfill-payable-instrument-accounts')
            ->expectsOutputToContain('wrong type')
            ->assertFailed();

        self::assertSame(
            AccountType::Asset,
            Account::query()->where('company_id', $company->id)->where('code', '403')->sole()->type,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisanCommand(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
