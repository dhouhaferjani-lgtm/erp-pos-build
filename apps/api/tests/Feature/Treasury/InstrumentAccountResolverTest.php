<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalCode;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\InstrumentAccountResolver;
use App\Modules\Treasury\Domain\Enums\InstrumentAccountPurpose;
use App\Modules\Treasury\Domain\Exceptions\MissingInstrumentAccountException;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('historical-compat')]
final class InstrumentAccountResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_country_specific_check_portfolio_accounts(): void
    {
        $tenant = Tenant::factory()->create();
        $tunisia = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $france = Company::factory()->create(['tenant_id' => $tenant->id]);

        $tunisiaSeeder = new TunisiaChartOfAccountsSeeder;
        $tunisiaSeeder->run($tunisia->id, $tenant->id);
        $tunisiaCount = Account::query()->where('company_id', $tunisia->id)->count();
        $tunisiaSeeder->run($tunisia->id, $tenant->id);

        $franceSeeder = new FranceChartOfAccountsSeeder;
        $franceSeeder->run($france->id, $tenant->id);
        $franceCount = Account::query()->where('company_id', $france->id)->count();
        $franceSeeder->run($france->id, $tenant->id);

        $resolver = app(InstrumentAccountResolver::class);

        $this->assertSame(
            '5312',
            Account::query()->findOrFail($resolver->resolveOrFail(InstrumentAccountPurpose::ChecksToCollect, $tunisia->id))->code,
        );
        $this->assertSame(
            '5112',
            Account::query()->findOrFail($resolver->resolveOrFail(InstrumentAccountPurpose::ChecksToCollect, $france->id))->code,
        );
        $this->assertSame($tunisiaCount, Account::query()->where('company_id', $tunisia->id)->count());
        $this->assertSame($franceCount, Account::query()->where('company_id', $france->id)->count());
    }

    /**
     * The Phase-2 portfolio accounts are system infrastructure (the
     * InstrumentAccountResolver resolves them BY CODE), so the chart seeders must
     * mark them is_system — and a seeder RE-RUN must promote the flag on charts
     * seeded before the flag existed (the seeders skip existing rows for
     * everything else, so the promotion is the only re-run write).
     */
    public function test_portfolio_accounts_are_system_flagged_and_rerun_promotes_the_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $tunisia = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $france = Company::factory()->create(['tenant_id' => $tenant->id]);
        $generic = Company::factory()->create(['tenant_id' => $tenant->id, 'country_code' => 'US']);

        $charts = [
            [new TunisiaChartOfAccountsSeeder, $tunisia, ['5312', '5313', '5314', '6275', '43666', '413', '416']],
            [new FranceChartOfAccountsSeeder, $france, ['5112', '5113', '5114', '627', '44566', '413', '416']],
            [new GenericChartOfAccountsSeeder, $generic, ['5112', '5113', '5114', '627', '44566', '413', '416']],
        ];

        foreach ($charts as [$seeder, $company, $codes]) {
            $seeder->run($company->id, $tenant->id);

            foreach ($codes as $code) {
                $account = Account::query()
                    ->where('company_id', $company->id)->where('code', $code)->sole();
                $this->assertTrue($account->is_system, "{$code} must be seeded is_system for {$company->country_code}");
            }

            // Simulate a chart seeded BEFORE the flag existed, then re-run: the
            // seeder must promote is_system=true on the existing rows without
            // duplicating or otherwise rewriting them.
            Account::query()
                ->where('company_id', $company->id)->whereIn('code', $codes)
                ->update(['is_system' => false]);
            $total = Account::query()->where('company_id', $company->id)->count();

            $seeder->run($company->id, $tenant->id);

            $this->assertSame($total, Account::query()->where('company_id', $company->id)->count());
            foreach ($codes as $code) {
                $account = Account::query()
                    ->where('company_id', $company->id)->where('code', $code)->sole();
                $this->assertTrue($account->is_system, "re-run must promote is_system on {$code} for {$company->country_code}");
            }
        }
    }

    public function test_missing_account_is_nullable_or_throws_explicit_domain_exception(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $resolver = app(InstrumentAccountResolver::class);

        $this->assertNull($resolver->resolve(InstrumentAccountPurpose::ChecksToCollect, $company->id));

        $this->expectException(MissingInstrumentAccountException::class);
        $resolver->resolveOrFail(InstrumentAccountPurpose::ChecksToCollect, $company->id);
    }

    public function test_instrument_sources_map_to_effets_journal_and_unknown_stays_misc(): void
    {
        $this->assertSame(JournalCode::Effets, JournalCode::fromSourceType('instrument'));
        $this->assertSame(JournalCode::Effets, JournalCode::fromSourceType('instrument_remittance'));
        $this->assertSame(JournalCode::Misc, JournalCode::fromSourceType('unmapped_source'));
    }
}
