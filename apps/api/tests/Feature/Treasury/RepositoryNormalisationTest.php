<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\RepositoryNormalisationActionData;
use App\Modules\Treasury\Application\Services\RepositoryCensusService;
use App\Modules\Treasury\Domain\Enums\RepositoryCensusCode;
use App\Modules\Treasury\Domain\Enums\RepositoryNormalisationAction;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Presentation\Console\NormaliseRepositoriesCommand;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

final class RepositoryNormalisationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function repositoryReferenceSurfaces(): iterable
    {
        yield 'repository movement' => ['repository_movements', 'payment_repository_id'];
        yield 'payment' => ['payments', 'repository_id'];
        yield 'repository adjustment' => ['repository_adjustments', 'payment_repository_id'];
        yield 'bank statement' => ['bank_statements', 'payment_repository_id'];
        yield 'bank statement line' => ['bank_statement_lines', 'payment_repository_id'];
        yield 'expense metadata' => ['expense_metadata', 'payment_repository_id'];
        yield 'income metadata' => ['income_metadata', 'payment_repository_id'];
        yield 'payment-method default' => ['payment_methods', 'default_repository_id'];
        yield 'payment instrument' => ['payment_instruments', 'repository_id'];
        yield 'payment instrument deposit repository' => ['payment_instruments', 'deposited_to_id'];
        yield 'instrument event source' => ['instrument_events', 'from_repository_id'];
        yield 'instrument event destination' => ['instrument_events', 'to_repository_id'];
        yield 'instrument remittance' => ['instrument_remittances', 'bank_repository_id'];
        yield 'statement import profile' => ['statement_import_profiles', 'payment_repository_id'];
        yield 'bank reconciliation' => ['bank_reconciliations', 'repository_id'];
        yield 'expense recurrence template' => ['expense_recurrence_templates', 'payment_repository_id'];
    }

    public function test_declared_reference_surfaces_equal_the_live_tenant_schema_boundary(): void
    {
        $migrationSource = collect(File::allFiles(database_path('migrations/tenant')))
            ->map(static fn (SplFileInfo $migration): string => $migration->getContents())
            ->implode("\n");
        preg_match_all(
            "/['\"]([a-z0-9_]*(?:repository_id)|deposited_to_id)['\"]/",
            $migrationSource,
            $migrationColumnMatches,
        );
        $migrationColumnNames = array_values(array_unique($migrationColumnMatches[1]));

        $liveSurfaces = [];
        foreach (Schema::getTables() as $tableMetadata) {
            $table = $tableMetadata['name'];
            /** @var list<array{columns: list<string>, foreign_table: string}> $foreignKeys */
            $foreignKeys = Schema::getForeignKeys($table);
            $repositoryForeignColumns = [];
            foreach ($foreignKeys as $foreignKey) {
                if ($foreignKey['foreign_table'] === 'payment_repositories') {
                    $repositoryForeignColumns = array_merge($repositoryForeignColumns, $foreignKey['columns']);
                }
            }

            foreach (Schema::getColumnListing($table) as $column) {
                $matchesRepositoryName = preg_match(
                    '/(^|_)(repository_id|deposited_to_id|from_repository_id|to_repository_id|bank_repository_id|payment_repository_id)$/',
                    $column,
                ) === 1;
                if (! $matchesRepositoryName && ! in_array($column, $repositoryForeignColumns, true)) {
                    continue;
                }

                if ($matchesRepositoryName) {
                    self::assertContains($column, $migrationColumnNames);
                }
                $liveSurfaces[] = $table.'.'.$column;
            }
        }

        /** @var list<array{table: string, column: string}> $declaredSurfaces */
        $declaredSurfaces = (new ReflectionClass(RepositoryCensusService::class))
            ->getConstant('REFERENCE_SURFACES');
        $declaredSurfaceNames = array_map(
            static fn (array $surface): string => $surface['table'].'.'.$surface['column'],
            $declaredSurfaces,
        );
        sort($declaredSurfaceNames);
        sort($liveSurfaces);

        self::assertSame($declaredSurfaceNames, $liveSurfaces);
    }

    public function test_census_issues_only_select_statements(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $location = Location::factory()->create(['company_id' => $company->id]);
        $account = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-01',
            'type' => RepositoryType::Safe,
            'location_id' => $location->id,
            'gl_account_id' => $account->id,
        ]);

        /** @var list<string> $observed */
        $observed = [];
        /** @var list<string> $offending */
        $offending = [];
        DB::listen(function ($query) use (&$observed, &$offending): void {
            $sql = (string) $query->sql;
            $observed[] = $sql;

            if (preg_match('/^\s*(select|savepoint|release|rollback)\b/i', $sql) !== 1) {
                $offending[] = $sql;
            }
        });

        $exit = Artisan::call('treasury:census-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $company->id,
            '--json' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertNotSame([], $observed, 'the listener must observe the census queries');
        self::assertSame([], $offending, implode("\n", $offending));
        self::assertTrue(collect($observed)->contains(
            static fn (string $sql): bool => preg_match('/\bfrom\s+["`]?payment_repositories["`]?\b/i', $sql) === 1,
        ));
    }

    public function test_census_emits_the_expected_ordered_codes_for_legacy_and_second_companies(): void
    {
        $tenant = Tenant::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Repository Normalisation Owner',
            'email' => 'repository-normalisation@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        $legacyCompany = Company::factory()->for($tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $legacyCompany->id,
            'role' => MembershipRole::Owner,
        ]);
        Location::factory()->count(2)->create(['company_id' => $legacyCompany->id]);
        $legacyCashAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $legacyCompany->id,
        ]);
        foreach ([
            ['code' => 'CASH-01', 'type' => RepositoryType::CashRegister, 'balance' => '0.000'],
            ['code' => 'CASH-02', 'type' => RepositoryType::CashRegister, 'balance' => '41.000'],
            ['code' => 'SAFE-01', 'type' => RepositoryType::Safe, 'balance' => '0.000'],
        ] as $repository) {
            PaymentRepository::factory()->for($legacyCompany)->create([
                'tenant_id' => $tenant->id,
                'code' => $repository['code'],
                'type' => $repository['type'],
                'balance' => $repository['balance'],
                'location_id' => null,
                'gl_account_id' => $legacyCashAccount->id,
            ]);
        }

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
            'name' => 'Second Repository Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ])->assertCreated();
        $secondCompany = Company::query()->findOrFail((string) $response->json('data.id'));
        $mainLocation = Location::query()->where('company_id', $secondCompany->id)->firstOrFail();
        PaymentRepository::factory()->for($secondCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-02',
            'type' => RepositoryType::Safe,
            'balance' => '0.000',
            'location_id' => $mainLocation->id,
            'gl_account_id' => null,
        ]);
        PaymentRepository::factory()->for($secondCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-03',
            'type' => RepositoryType::Safe,
            'balance' => '12.000',
            'location_id' => $mainLocation->id,
            'gl_account_id' => null,
        ]);

        $exit = Artisan::call('treasury:census-repositories', [
            '--tenant' => $tenant->id,
            '--all-companies' => true,
            '--json' => true,
        ]);
        /** @var array{complete: bool, companies: list<array{company_id: string, findings: list<array{code: string, attribution_verdict: string|null}>}>} $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $codesByCompany = collect($payload['companies'])->mapWithKeys(
            static fn (array $company): array => [
                $company['company_id'] => array_column($company['findings'], 'code'),
            ],
        );

        self::assertSame(0, $exit);
        self::assertTrue($payload['complete']);
        self::assertSame([
            RepositoryCensusCode::CashLocationNull->value,
            RepositoryCensusCode::CashLocationNull->value,
            RepositoryCensusCode::CashLocationNull->value,
        ], $codesByCompany->get($legacyCompany->id));
        self::assertSame([
            RepositoryCensusCode::SafeCountNotOne->value,
            RepositoryCensusCode::SafeGlUnlinked->value,
            RepositoryCensusCode::SafeGlUnlinked->value,
            RepositoryCensusCode::SafeDuplicateClean->value,
            RepositoryCensusCode::DuplicateMoneyBearing->value,
        ], $codesByCompany->get($secondCompany->id));
        self::assertSame(
            ['ambiguous', 'ambiguous', 'attributable'],
            array_column(
                collect($payload['companies'])->firstWhere('company_id', $legacyCompany->id)['findings'],
                'attribution_verdict',
            ),
        );
    }

    #[DataProvider('repositoryReferenceSurfaces')]
    public function test_every_direct_repository_reference_surface_refuses_metadata_normalisation(
        string $table,
        string $column,
    ): void {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $location = Location::factory()->create(['company_id' => $company->id]);
        $account = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $canonical = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-CANONICAL',
            'type' => RepositoryType::Safe,
            'location_id' => $location->id,
            'gl_account_id' => $account->id,
            'balance' => '0.000',
        ]);
        $surplus = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-SURPLUS',
            'type' => RepositoryType::Safe,
            'location_id' => $location->id,
            'gl_account_id' => null,
            'balance' => '0.000',
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->insertRepositoryReference($table, $column, $tenant, $company, $surplus, $method, $user);

        $result = app(RepositoryCensusService::class)->census($tenant->id, $company->id);
        self::assertSame(
            [$surplus->id],
            array_map(
                static fn ($finding): ?string => $finding->repositoryId,
                $result->findingsFor(RepositoryCensusCode::DuplicateMoneyBearing),
            ),
        );
        self::assertSame([], $result->findingsFor(RepositoryCensusCode::SafeDuplicateClean));

        $before = $this->repositorySnapshot($tenant->id);
        $exit = Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $company->id,
            '--apply' => true,
        ]);
        $output = Artisan::output();

        self::assertSame(1, $exit);
        self::assertStringContainsString(RepositoryCensusCode::DuplicateMoneyBearing->value, $output);
        self::assertStringContainsString('RepositoryTransfer', $output);
        self::assertStringNotContainsString(RepositoryNormalisationAction::DeactivateSurplusSafe->value, $output);
        self::assertStringNotContainsString(RepositoryNormalisationAction::LinkCanonicalSafe->value, $output);
        self::assertSame($before, $this->repositorySnapshot($tenant->id));
        self::assertTrue($surplus->fresh()->is_active);
        self::assertSame($account->id, $canonical->fresh()->gl_account_id);
    }

    public function test_census_scale_decision_is_independent_of_company_context(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['currency' => 'JPY']);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $account = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-CANONICAL',
            'type' => RepositoryType::Safe,
            'currency' => 'TND',
            'location_id' => $location->id,
            'gl_account_id' => $account->id,
        ]);
        $surplus = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-TND-SURPLUS',
            'type' => RepositoryType::Safe,
            'currency' => 'TND',
            'balance' => '0.004',
            'location_id' => $location->id,
            'gl_account_id' => null,
        ]);

        app(CompanyContext::class)->clear();
        $result = app(RepositoryCensusService::class)->census($tenant->id, $company->id);

        self::assertSame(
            [$surplus->id],
            array_map(
                static fn ($finding): ?string => $finding->repositoryId,
                $result->findingsFor(RepositoryCensusCode::DuplicateMoneyBearing),
            ),
        );
    }

    public function test_dry_run_and_apply_only_change_clean_safe_metadata_and_refuse_money_bearing_duplicates(): void
    {
        $tenant = Tenant::factory()->create();
        $legacyCompany = Company::factory()->for($tenant)->create();
        Location::factory()->count(2)->create(['company_id' => $legacyCompany->id]);
        $legacyAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $legacyCompany->id,
        ]);
        foreach ([['CASH-01', '0.000'], ['CASH-02', '25.000']] as [$code, $balance]) {
            PaymentRepository::factory()->for($legacyCompany)->create([
                'tenant_id' => $tenant->id,
                'code' => $code,
                'type' => RepositoryType::CashRegister,
                'balance' => $balance,
                'location_id' => null,
                'gl_account_id' => $legacyAccount->id,
            ]);
        }

        $secondCompany = Company::factory()->for($tenant)->create();
        $secondLocation = Location::factory()->create(['company_id' => $secondCompany->id]);
        $cashPurposeAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $secondCompany->id,
            'code' => '531',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
        ]);
        $canonical = PaymentRepository::factory()->for($secondCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-01',
            'type' => RepositoryType::Safe,
            'location_id' => $secondLocation->id,
            'gl_account_id' => null,
        ]);
        $cleanDuplicate = PaymentRepository::factory()->for($secondCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-02',
            'type' => RepositoryType::Safe,
            'balance' => '0.000',
            'location_id' => $secondLocation->id,
            'gl_account_id' => null,
        ]);
        $moneyBearingDuplicate = PaymentRepository::factory()->for($secondCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-03',
            'type' => RepositoryType::Safe,
            'balance' => '9.000',
            'location_id' => $secondLocation->id,
            'gl_account_id' => null,
        ]);
        $before = $this->repositorySnapshot($tenant->id);
        $movementCount = DB::table('repository_movements')->count();
        $journalCount = DB::table('journal_entries')->count();

        $dryRunExit = Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--all-companies' => true,
        ]);
        $dryRunOutput = Artisan::output();

        self::assertSame(1, $dryRunExit);
        self::assertStringContainsString('[DRY-RUN]', $dryRunOutput);
        self::assertStringContainsString(RepositoryCensusCode::DuplicateMoneyBearing->value, $dryRunOutput);
        self::assertSame($before, $this->repositorySnapshot($tenant->id));

        /** @var list<string> $actingQueries */
        $actingQueries = [];
        /** @var list<MessageLogged> $logEvents */
        $logEvents = [];
        Log::listen(static function (MessageLogged $event) use (&$logEvents): void {
            $logEvents[] = $event;
        });
        DB::listen(static function ($query) use (&$actingQueries): void {
            $actingQueries[] = (string) $query->sql;
        });
        $applyExit = Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--all-companies' => true,
            '--apply' => true,
        ]);
        $applyOutput = Artisan::output();

        self::assertSame(1, $applyExit);
        self::assertStringContainsString(RepositoryCensusCode::DuplicateMoneyBearing->value, $applyOutput);
        self::assertStringContainsString('RepositoryTransferService', $applyOutput);
        self::assertSame($cashPurposeAccount->id, $canonical->fresh()->gl_account_id);
        self::assertFalse($cleanDuplicate->fresh()->is_active);
        self::assertSame(0, bccomp($cleanDuplicate->fresh()->balance, '0', 3));
        self::assertTrue($moneyBearingDuplicate->fresh()->is_active);
        self::assertNull($moneyBearingDuplicate->fresh()->gl_account_id);
        self::assertSame(0, bccomp($moneyBearingDuplicate->fresh()->balance, '9.000', 3));
        self::assertSame(
            array_intersect_key($before, array_flip(
                PaymentRepository::query()->where('company_id', $legacyCompany->id)->pluck('id')->all(),
            )),
            array_intersect_key($this->repositorySnapshot($tenant->id), array_flip(
                PaymentRepository::query()->where('company_id', $legacyCompany->id)->pluck('id')->all(),
            )),
        );
        self::assertSame($movementCount, DB::table('repository_movements')->count());
        self::assertSame($journalCount, DB::table('journal_entries')->count());
        self::assertFalse(collect($actingQueries)->contains(
            static fn (string $sql): bool => preg_match('/\b(delete\s+from\s+["`]?payment_repositories|insert\s+into\s+["`]?(repository_movements|journal_entries)|update\s+["`]?payment_repositories["`]?.*\b(balance|location_id)\b)/i', $sql) === 1,
        ), implode("\n", $actingQueries));
        self::assertTrue(collect($logEvents)->contains(static fn (MessageLogged $event): bool => $event->level === 'info'
                && $event->message === 'repositories.normalised'
                && $event->context['company_id'] === $secondCompany->id
                && count($event->context['actions']) === 2
                && $event->context['refused_count'] === 1
                && $event->context['refused_codes'] === [RepositoryCensusCode::DuplicateMoneyBearing->value]
                && $event->context['skipped_count'] === 0
                && $event->context['skipped_codes'] === [],
        ));
        self::assertCount(2, $logEvents);
    }

    public function test_apply_logs_no_op_and_refused_only_company_runs(): void
    {
        $tenant = Tenant::factory()->create();

        $noOpCompany = Company::factory()->for($tenant)->create();
        $noOpAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $noOpCompany->id,
        ]);
        $noOpLocation = Location::factory()->create(['company_id' => $noOpCompany->id]);
        PaymentRepository::factory()->for($noOpCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-NOOP',
            'type' => RepositoryType::Safe,
            'gl_account_id' => $noOpAccount->id,
            'location_id' => $noOpLocation->id,
        ]);

        $refusedCompany = Company::factory()->for($tenant)->create();
        $refusedAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $refusedCompany->id,
        ]);
        $refusedLocation = Location::factory()->create(['company_id' => $refusedCompany->id]);
        PaymentRepository::factory()->for($refusedCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-CANONICAL',
            'type' => RepositoryType::Safe,
            'gl_account_id' => $refusedAccount->id,
            'location_id' => $refusedLocation->id,
        ]);
        PaymentRepository::factory()->for($refusedCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-REFUSED',
            'type' => RepositoryType::Safe,
            'gl_account_id' => null,
            'balance' => '1.000',
            'location_id' => $refusedLocation->id,
        ]);

        /** @var list<MessageLogged> $logEvents */
        $logEvents = [];
        Log::listen(static function (MessageLogged $event) use (&$logEvents): void {
            $logEvents[] = $event;
        });
        self::assertSame(1, Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--all-companies' => true,
            '--apply' => true,
        ]));

        self::assertTrue(collect($logEvents)->contains(static fn (MessageLogged $event): bool => $event->level === 'info'
            && $event->message === 'repositories.normalised'
            && $event->context === [
                'company_id' => $noOpCompany->id,
                'actions' => [],
                'refused_codes' => [],
                'refused_count' => 0,
                'skipped_codes' => [],
                'skipped_count' => 0,
            ],
        ));
        self::assertTrue(collect($logEvents)->contains(static fn (MessageLogged $event): bool => $event->level === 'info'
            && $event->message === 'repositories.normalised'
            && $event->context === [
                'company_id' => $refusedCompany->id,
                'actions' => [],
                'refused_codes' => [RepositoryCensusCode::DuplicateMoneyBearing->value],
                'refused_count' => 1,
                'skipped_codes' => [],
                'skipped_count' => 0,
            ],
        ));
        self::assertCount(2, $logEvents);
    }

    public function test_apply_rechecks_a_clean_surplus_immediately_before_deactivation(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $account = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-CANONICAL',
            'type' => RepositoryType::Safe,
            'gl_account_id' => $account->id,
        ]);
        $surplus = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-RACE',
            'type' => RepositoryType::Safe,
            'gl_account_id' => null,
            'balance' => '0.000',
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $referenceInserted = false;
        DB::listen(function ($query) use (&$referenceInserted, $method, $surplus): void {
            if ($referenceInserted
                || ! str_contains((string) $query->sql, 'expense_recurrence_templates')
                || ! in_array($surplus->id, $query->bindings, true)) {
                return;
            }

            $referenceInserted = true;
            DB::table('payment_methods')
                ->where('id', $method->id)
                ->update(['default_repository_id' => $surplus->id]);
        });

        $exit = Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $company->id,
            '--apply' => true,
        ]);
        $output = Artisan::output();

        self::assertTrue($referenceInserted);
        self::assertSame(1, $exit);
        self::assertStringContainsString(RepositoryCensusCode::DuplicateMoneyBearing->value, $output);
        self::assertStringContainsString('RepositoryTransfer', $output);
        self::assertStringNotContainsString(RepositoryNormalisationAction::DeactivateSurplusSafe->value, $output);
        self::assertTrue($surplus->fresh()->is_active);
        self::assertNull($surplus->fresh()->gl_account_id);
        self::assertSame(0, bccomp($surplus->fresh()->balance, '0', 3));
    }

    public function test_apply_action_reasserts_tenant_at_the_write_boundary(): void
    {
        $selectedTenant = Tenant::factory()->create();
        $mismatchedTenant = Tenant::factory()->create();
        $company = Company::factory()->for($selectedTenant)->create();
        $account = Account::factory()->create([
            'tenant_id' => $selectedTenant->id,
            'company_id' => $company->id,
        ]);
        $mismatchedRepository = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $mismatchedTenant->id,
            'code' => 'SAFE-MISMATCHED-TENANT',
            'type' => RepositoryType::Safe,
            'gl_account_id' => null,
        ]);
        $action = new RepositoryNormalisationActionData(
            RepositoryNormalisationAction::LinkCanonicalSafe,
            $mismatchedRepository->id,
            $account->id,
        );
        $method = new ReflectionMethod(NormaliseRepositoriesCommand::class, 'applyAction');

        $updated = $method->invoke(
            app(NormaliseRepositoriesCommand::class),
            $selectedTenant->id,
            $company->id,
            $action,
        );

        self::assertFalse($updated);
        self::assertNull($mismatchedRepository->fresh()->gl_account_id);
    }

    public function test_apply_is_idempotent_for_a_real_second_company_with_a_second_location(): void
    {
        $tenant = Tenant::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Second Company Owner',
            'email' => 'second-company-normalisation@example.test',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');
        $existingCompany = Company::factory()->for($tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $existingCompany->id,
            'role' => MembershipRole::Owner,
        ]);

        $companyResponse = $this->actingAs($user, 'sanctum')->postJson('/api/v1/companies', [
            'name' => 'Current Path Company',
            'country_code' => 'FR',
            'currency' => 'EUR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
        ])->assertCreated();
        $company = Company::query()->findOrFail((string) $companyResponse->json('data.id'));
        $branchResponse = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id)
            ->postJson('/api/v1/locations', [
                'name' => 'Second POS Location',
                'code' => 'BRANCH',
                'type' => 'warehouse',
                'pos_enabled' => true,
            ])
            ->assertCreated();
        $branchId = (string) $branchResponse->json('data.id');
        $branchDrawer = PaymentRepository::query()
            ->where('company_id', $company->id)
            ->where('location_id', $branchId)
            ->where('type', RepositoryType::CashRegister)
            ->firstOrFail();
        $surplus = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-SURPLUS',
            'type' => RepositoryType::Safe,
            'location_id' => $branchId,
            'gl_account_id' => null,
            'balance' => '0.000',
        ]);

        $firstExit = Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $company->id,
            '--apply' => true,
        ]);
        self::assertSame(0, $firstExit);
        self::assertFalse($surplus->fresh()->is_active);
        self::assertTrue($branchDrawer->fresh()->is_active);
        $afterFirstRun = $this->repositorySnapshot($tenant->id);

        $secondExit = Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $company->id,
            '--apply' => true,
        ]);
        self::assertSame(0, $secondExit);
        self::assertSame($afterFirstRun, $this->repositorySnapshot($tenant->id));

        Artisan::call('treasury:census-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $company->id,
            '--json' => true,
        ]);
        /** @var array{companies: list<array{findings: list<array{code: string}>}>} $census */
        $census = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $census['companies'][0]['findings']);
    }

    public function test_postgres_partial_unique_transitions_and_pre_existing_duplicate_refusal(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The repository drawer partial unique is PostgreSQL-only.');
        }

        $tenant = Tenant::factory()->create();

        $deactivationCompany = Company::factory()->for($tenant)->create();
        $deactivationAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $deactivationCompany->id,
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
        ]);
        $firstLocation = Location::factory()->create(['company_id' => $deactivationCompany->id]);
        $secondLocation = Location::factory()->create(['company_id' => $deactivationCompany->id]);
        PaymentRepository::factory()->for($deactivationCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-CANONICAL',
            'type' => RepositoryType::Safe,
            'location_id' => $firstLocation->id,
            'gl_account_id' => $deactivationAccount->id,
        ]);
        $indexedSurplus = PaymentRepository::factory()->for($deactivationCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-SURPLUS',
            'type' => RepositoryType::Safe,
            'location_id' => $secondLocation->id,
            'gl_account_id' => $deactivationAccount->id,
        ]);
        self::assertSame(0, Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $deactivationCompany->id,
            '--apply' => true,
        ]));
        self::assertFalse($indexedSurplus->fresh()->is_active);

        $linkCompany = Company::factory()->for($tenant)->create();
        $linkLocation = Location::factory()->create(['company_id' => $linkCompany->id]);
        $linkAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $linkCompany->id,
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
        ]);
        $unlinkedCanonical = PaymentRepository::factory()->for($linkCompany)->create([
            'tenant_id' => $tenant->id,
            'code' => 'SAFE-UNLINKED',
            'type' => RepositoryType::Safe,
            'location_id' => $linkLocation->id,
            'gl_account_id' => null,
        ]);
        self::assertSame(0, Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $linkCompany->id,
            '--apply' => true,
        ]));
        self::assertSame($linkAccount->id, $unlinkedCanonical->fresh()->gl_account_id);
        self::assertSame(1, DB::table('payment_repositories')
            ->where('company_id', $linkCompany->id)
            ->where('location_id', $linkLocation->id)
            ->where('type', RepositoryType::Safe->value)
            ->where('is_active', true)
            ->whereNotNull('gl_account_id')
            ->count());

        $legacyDuplicateCompany = Company::factory()->for($tenant)->create();
        $legacyLocation = Location::factory()->create(['company_id' => $legacyDuplicateCompany->id]);
        $legacyAccount = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $legacyDuplicateCompany->id,
        ]);
        DB::statement('DROP INDEX payment_repositories_one_drawer_per_location_type');
        $legacyPair = collect([
            ['SAFE-LEGACY-1', '0.000', now()->subMinute()],
            ['SAFE-LEGACY-2', '7.000', now()],
        ])->map(
            fn (array $row): PaymentRepository => PaymentRepository::factory()->for($legacyDuplicateCompany)->create([
                'tenant_id' => $tenant->id,
                'code' => $row[0],
                'type' => RepositoryType::Safe,
                'location_id' => $legacyLocation->id,
                'gl_account_id' => $legacyAccount->id,
                'balance' => $row[1],
                'created_at' => $row[2],
            ]),
        );

        Artisan::call('treasury:census-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $legacyDuplicateCompany->id,
            '--json' => true,
        ]);
        /** @var array{companies: list<array{findings: list<array{code: string}>}>} $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(
            RepositoryCensusCode::DuplicatePerLocationType->value,
            array_column($payload['companies'][0]['findings'], 'code'),
        );
        self::assertContains(
            RepositoryCensusCode::DuplicateMoneyBearing->value,
            array_column($payload['companies'][0]['findings'], 'code'),
        );
        self::assertSame(1, Artisan::call('treasury:normalise-repositories', [
            '--tenant' => $tenant->id,
            '--company' => $legacyDuplicateCompany->id,
            '--apply' => true,
        ]));
        $legacyOutput = Artisan::output();
        self::assertStringContainsString(RepositoryCensusCode::DuplicateMoneyBearing->value, $legacyOutput);
        self::assertStringContainsString('RepositoryTransfer', $legacyOutput, $legacyOutput);
        foreach ($legacyPair as $repository) {
            self::assertTrue($repository->fresh()->is_active);
            self::assertSame($legacyLocation->id, $repository->fresh()->location_id);
            self::assertSame($legacyAccount->id, $repository->fresh()->gl_account_id);
        }
    }

    public function test_census_rejects_ambiguous_scope_and_reports_zero_company_coverage_as_incomplete(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();

        self::assertSame(1, Artisan::call('treasury:census-repositories'));
        self::assertSame(1, Artisan::call('treasury:census-repositories', [
            '--company' => $company->id,
            '--all-companies' => true,
        ]));
        self::assertSame(1, Artisan::call('treasury:normalise-repositories'));

        $exit = Artisan::call('treasury:census-repositories', [
            '--tenant' => $tenant->id,
            '--company' => (string) Str::uuid(),
            '--json' => true,
        ]);
        /** @var array{complete: bool, reason: string, totals: array{companies_visited: int}} $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(1, $exit);
        self::assertFalse($payload['complete']);
        self::assertSame('no_companies_visited', $payload['reason']);
        self::assertSame(0, $payload['totals']['companies_visited']);
    }

    private function insertRepositoryReference(
        string $table,
        string $column,
        Tenant $tenant,
        Company $company,
        PaymentRepository $repository,
        PaymentMethod $method,
        User $user,
    ): void {
        $surface = $table.'.'.$column;
        $id = (string) Str::uuid();

        if ($surface === 'repository_movements.payment_repository_id') {
            DB::table('repository_movements')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'payment_repository_id' => $repository->id,
                'direction' => 'in',
                'amount' => '1.000',
                'currency' => $repository->currency,
                'balance_after' => '1.000',
                'ordinal' => 1,
                'source_type' => 'manual_adjustment',
                'source_id' => (string) Str::uuid(),
                'idempotency_key' => 'census-'.$id,
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return;
        }

        if ($surface === 'payments.repository_id') {
            $partner = Partner::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);
            Payment::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'partner_id' => $partner->id,
                'payment_method_id' => $method->id,
                'repository_id' => $repository->id,
            ]);

            return;
        }

        if ($surface === 'repository_adjustments.payment_repository_id') {
            DB::table('repository_adjustments')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'payment_repository_id' => $repository->id,
                'direction' => 'in',
                'amount' => '1.000',
                'currency' => $repository->currency,
                'reason_code' => 'other',
                'reason_text' => 'Reference-census fixture',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($surface === 'bank_statements.payment_repository_id') {
            $this->insertBankStatement($id, $tenant, $company, $repository);

            return;
        }

        if ($surface === 'bank_statement_lines.payment_repository_id') {
            $statementId = (string) Str::uuid();
            $this->insertBankStatement($statementId, $tenant, $company, $repository);
            DB::table('bank_statement_lines')->insert([
                'id' => $id,
                'bank_statement_id' => $statementId,
                'payment_repository_id' => $repository->id,
                'line_number' => 1,
                'value_date' => now()->toDateString(),
                'direction' => 'in',
                'amount' => '1.000',
                'label' => 'Reference census fixture',
                'match_status' => 'unmatched',
                'fingerprint' => hash('sha256', $id),
            ]);

            return;
        }

        if (in_array($surface, [
            'expense_metadata.payment_repository_id',
            'income_metadata.payment_repository_id',
        ], true)) {
            $document = Document::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);
            DB::table($table)->insert([
                'id' => $id,
                'document_id' => $document->id,
                'payment_repository_id' => $repository->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($surface === 'payment_methods.default_repository_id') {
            DB::table('payment_methods')->where('id', $method->id)->update([
                'default_repository_id' => $repository->id,
            ]);

            return;
        }

        if ($surface === 'payment_instruments.repository_id') {
            $this->insertPaymentInstrument($id, $tenant, $company, $method, $repository->id);

            return;
        }

        if ($surface === 'payment_instruments.deposited_to_id') {
            $this->insertPaymentInstrument($id, $tenant, $company, $method, null);
            DB::table('payment_instruments')->where('id', $id)->update([
                'deposited_to_id' => $repository->id,
            ]);

            return;
        }

        if (in_array($surface, [
            'instrument_events.from_repository_id',
            'instrument_events.to_repository_id',
        ], true)) {
            $instrumentId = (string) Str::uuid();
            $this->insertPaymentInstrument($instrumentId, $tenant, $company, $method, null);
            DB::table('instrument_events')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'instrument_id' => $instrumentId,
                'event_type' => 'custody_transferred',
                $column => $repository->id,
                'payload' => '{}',
                'occurred_at' => now(),
                'created_at' => now(),
            ]);

            return;
        }

        if ($surface === 'instrument_remittances.bank_repository_id') {
            DB::table('instrument_remittances')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'number' => 'REM-'.substr($id, 0, 8),
                'remittance_type' => 'deposit',
                'instrument_kind' => 'cheque',
                'bank_repository_id' => $repository->id,
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($surface === 'statement_import_profiles.payment_repository_id') {
            DB::table('statement_import_profiles')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'payment_repository_id' => $repository->id,
                'name' => 'Reference census profile',
                'parser_key' => 'csv',
                'column_map' => '{}',
                'date_format' => 'Y-m-d',
                'decimal_format' => 'dot',
                'direction_convention' => 'signed_amount',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($surface === 'bank_reconciliations.repository_id') {
            DB::table('bank_reconciliations')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'repository_id' => $repository->id,
                'statement_date' => now()->toDateString(),
                'opening_balance' => '0.00',
                'closing_balance' => '0.00',
                'statement_balance' => '0.00',
                'difference' => '0.00',
                'status' => 'draft',
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ($surface === 'expense_recurrence_templates.payment_repository_id') {
            DB::table('expense_recurrence_templates')->insert([
                'id' => $id,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'name' => 'Reference census recurrence',
                'payment_repository_id' => $repository->id,
                'amount' => '1.000',
                'frequency' => 'monthly',
                'start_date' => now()->toDateString(),
                'status' => 'active',
                'next_due_date' => now()->addMonth()->toDateString(),
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        self::fail('Unhandled repository reference surface: '.$surface);
    }

    private function insertBankStatement(
        string $id,
        Tenant $tenant,
        Company $company,
        PaymentRepository $repository,
    ): void {
        DB::table('bank_statements')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_repository_id' => $repository->id,
            'currency' => $repository->currency,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'opening_balance' => '0.000',
            'closing_balance' => '0.000',
            'status' => 'imported',
            'source_file_sha256' => hash('sha256', $id),
            'source_file_path' => '/tmp/reference-census.csv',
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPaymentInstrument(
        string $id,
        Tenant $tenant,
        Company $company,
        PaymentMethod $method,
        ?string $repositoryId,
    ): void {
        DB::table('payment_instruments')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'payment_method_id' => $method->id,
            'reference' => 'INS-'.substr($id, 0, 8),
            'amount' => '1.000',
            'currency' => 'EUR',
            'received_date' => now()->toDateString(),
            'status' => 'received',
            'repository_id' => $repositoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, array{balance: string, gl_account_id: string|null, is_active: bool, location_id: string|null}>
     */
    private function repositorySnapshot(string $tenantId): array
    {
        $snapshot = [];
        foreach (PaymentRepository::query()->where('tenant_id', $tenantId)->orderBy('id')->get() as $repository) {
            $snapshot[$repository->id] = [
                'balance' => $repository->balance,
                'gl_account_id' => $repository->gl_account_id,
                'is_active' => $repository->is_active,
                'location_id' => $repository->location_id,
            ];
        }

        return $snapshot;
    }
}
