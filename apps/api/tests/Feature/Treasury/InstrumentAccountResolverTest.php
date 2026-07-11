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
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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
