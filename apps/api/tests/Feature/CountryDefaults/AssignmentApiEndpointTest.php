<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\CountryDefaults\Application\DTOs\AssignmentMatrixData;
use App\Modules\CountryDefaults\Application\DTOs\AssignmentMatrixMetaData;
use App\Modules\CountryDefaults\Application\Services\TemplateAssignmentService;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
use Throwable;

final class AssignmentApiEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_matrix_wire_contract_is_a_typed_envelope(): void
    {
        $matrix = new AssignmentMatrixData(
            data: [],
            meta: new AssignmentMatrixMetaData(catalog_version: 'catalog-v1'),
        );

        self::assertSame([
            'data' => [],
            'meta' => ['catalog_version' => 'catalog-v1'],
        ], $matrix->toArray());
    }

    /** @return list<string|null> */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::connection((new CountryTemplateAssignment)->getConnectionName())
                ->table('country_template_assignments')
                ->delete();
        }

        parent::tearDown();
    }

    public function test_assignment_api_returns_country_matrix_normalizes_and_repoints(): void
    {
        $actor = $this->admin();
        $first = $this->published('FR', $actor);
        $second = $this->published('FR', $actor);

        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/fr', [
            'domain' => 'chart_of_accounts',
            'template_id' => $first->id,
        ])->assertOk()->assertJsonPath('data.country_code', 'FR');
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
            'domain' => 'chart_of_accounts',
            'template_id' => $second->id,
        ])->assertOk()->assertJsonPath('data.template_id', $second->id);

        $this->actingAs($actor, 'sanctum-admin')->getJson('/api/v1/admin/country-defaults/assignments?domain=chart_of_accounts')
            ->assertOk()
            ->assertJsonPath('meta.catalog_version', 'iso-3166-1-alpha-2-2024')
            ->assertJsonFragment(['country_code' => '*', 'pinned' => true])
            ->assertJsonFragment(['country_code' => 'FR', 'template_id' => $second->id]);
    }

    public function test_assignment_api_enforces_status_scope_domain_wildcard_and_typed_conflicts(): void
    {
        $actor = $this->admin();
        $fr = $this->published('FR', $actor);
        $draft = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Draft assignment target',
            'status' => TemplateStatus::Draft,
        ]);

        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/DE', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_CONFLICT');
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
            'domain' => 'chart_of_accounts',
            'template_id' => $draft->id,
        ])->assertConflict();
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/not-a-country', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])->assertUnprocessable();

        $wildcard = $this->published('*', $actor);
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/*', [
            'domain' => 'chart_of_accounts',
            'template_id' => $wildcard->id,
        ])->assertOk();
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/*', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])
            ->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_CONFLICT');

        DB::connection($fr->getConnectionName())->table('admin_templates')->where('id', $fr->id)->update(['domain' => 'other']);
        $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
            'domain' => 'chart_of_accounts',
            'template_id' => $fr->id,
        ])->assertConflict();
    }

    public function test_postgresql_concurrent_first_assignment_unique_loser_is_a_typed_conflict(): void
    {
        $this->requirePostgreSqlConcurrency();
        $actor = $this->admin();
        $template = $this->published('FR', $actor);
        [$parent, $child] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parent);
            $this->runPausedFirstAssignmentChild($child, $template->id, $actor->id);
        }

        fclose($child);
        self::assertSame("assignment-template-locked\n", fgets($parent));
        $response = $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
            'domain' => 'chart_of_accounts',
            'template_id' => $template->id,
        ]);
        $childMessage = stream_get_contents($parent);
        fclose($parent);
        pcntl_waitpid($pid, $status);

        self::assertSame('', $childMessage);
        self::assertSame(0, pcntl_wexitstatus($status));
        $response->assertConflict()->assertJsonPath('error.code', 'ASSIGNMENT_CONFLICT');
        self::assertSame(1, CountryTemplateAssignment::query()
            ->where('country_code', 'FR')
            ->where('domain', TemplateDomain::ChartOfAccounts->value)
            ->count());
    }

    public function test_unrelated_assignment_query_exception_is_rethrown(): void
    {
        $actor = $this->admin();
        $template = $this->published('FR', $actor);
        $connection = DB::connection((new CountryTemplateAssignment)->getConnectionName());
        $this->installUnrelatedAssignmentFailure($connection->getDriverName());
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($actor, 'sanctum-admin')->putJson('/api/v1/admin/country-defaults/assignments/FR', [
                'domain' => 'chart_of_accounts',
                'template_id' => $template->id,
            ]);
            self::fail('An unrelated assignment storage failure must propagate as QueryException.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('country_defaults_unrelated_failure', $exception->getMessage());
        } finally {
            $this->removeUnrelatedAssignmentFailure($connection->getDriverName());
        }
    }

    private function published(string $scope, SuperAdmin $actor): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Published '.$scope.' '.Str::random(6),
            'status' => TemplateStatus::Draft,
        ]);
        DB::connection($template->getConnectionName())->transaction(function () use ($template, $scope): void {
            $sort = 1;
            foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
                if ($entry['classification'] !== 'REQUIRED') {
                    continue;
                }
                AdminTemplateAccount::query()->create([
                    'template_id' => $template->id,
                    'code' => sprintf('R%03d', $sort),
                    'name' => $entry['purpose']->name,
                    'type' => $entry['purpose']->expectedAccountType(),
                    'parent_code' => null,
                    'system_purpose' => $entry['purpose'],
                    'is_system' => true,
                    'sort_order' => $sort++,
                ]);
            }
            foreach (ProtectedAccountCodeRegistry::forCountry($scope) as $protected) {
                AdminTemplateAccount::query()->create([
                    'template_id' => $template->id,
                    'code' => $protected['code'],
                    'name' => 'Protected '.$protected['code'],
                    'type' => AccountType::from($protected['expected_type']),
                    'parent_code' => null,
                    'system_purpose' => null,
                    'is_system' => $protected['requires_system'],
                    'sort_order' => $sort++,
                ]);
            }
        });

        return app(TemplatePublishingService::class)->publish($template->id, 'Standard 2026', [$scope], $actor);
    }

    private function admin(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Assignment operator',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    /** @return array{resource, resource} */
    private function socketPair(): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        return [$sockets[0], $sockets[1]];
    }

    private function requirePostgreSqlConcurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('The first-assignment unique-loser proof requires PostgreSQL row locks.');
        }
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('The first-assignment unique-loser proof requires ext-pcntl.');
        }
    }

    private function resetChildConnection(): void
    {
        DB::purge((new CountryTemplateAssignment)->getConnectionName());
    }

    private function runPausedFirstAssignmentChild(mixed $socket, string $templateId, string $actorId): never
    {
        try {
            $this->resetChildConnection();
            $delegate = app(CountryAccountingCapabilities::class);
            $this->app->instance(CountryAccountingCapabilities::class, new class($delegate, $socket) implements CountryAccountingCapabilities
            {
                private bool $paused = false;

                public function __construct(
                    private readonly CountryAccountingCapabilities $delegate,
                    private readonly mixed $socket,
                ) {}

                public function supportsStampDuty(string $countryCode): bool
                {
                    return $this->delegate->supportsStampDuty($countryCode);
                }

                public function version(): string
                {
                    if (! $this->paused) {
                        if (! is_resource($this->socket)) {
                            throw new LogicException('Race socket is unavailable.');
                        }
                        $this->paused = true;
                        fwrite($this->socket, "assignment-template-locked\n");
                        usleep(500_000);
                    }

                    return $this->delegate->version();
                }
            });
            $actor = SuperAdmin::query()->findOrFail($actorId);
            app(TemplateAssignmentService::class)->assign(
                'FR',
                TemplateDomain::ChartOfAccounts,
                $templateId,
                $actor,
            );
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            if (is_resource($socket)) {
                fwrite($socket, 'error:'.$exception->getMessage());
                fclose($socket);
            }
            exit(1);
        }
    }

    private function installUnrelatedAssignmentFailure(string $driver): void
    {
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION country_defaults_unrelated_failure() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'country_defaults_unrelated_failure' USING ERRCODE = '23503';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER country_defaults_unrelated_failure
                BEFORE INSERT ON country_template_assignments
                FOR EACH ROW EXECUTE FUNCTION country_defaults_unrelated_failure();
                SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER country_defaults_unrelated_failure
            BEFORE INSERT ON country_template_assignments
            BEGIN
                SELECT RAISE(ABORT, 'country_defaults_unrelated_failure');
            END;
            SQL);
    }

    private function removeUnrelatedAssignmentFailure(string $driver): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS country_defaults_unrelated_failure'.($driver === 'pgsql' ? ' ON country_template_assignments' : ''));
        if ($driver === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS country_defaults_unrelated_failure()');
        }
    }
}
