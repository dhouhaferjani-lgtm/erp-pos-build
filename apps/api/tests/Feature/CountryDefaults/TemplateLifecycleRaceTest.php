<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Application\Services\TemplateAssignmentService;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

final class TemplateLifecycleRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string|null> */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    protected function tearDown(): void
    {
        DB::connection((new AdminTemplate)->getConnectionName())
            ->table('country_template_assignments')
            ->whereIn('country_code', ['FR', 'DE'])
            ->delete();

        parent::tearDown();
    }

    public function test_real_publish_vs_assign_services_serialize_at_the_template_lock(): void
    {
        $this->requirePostgreSqlRace('publish versus assign');
        $actor = $this->actor();
        $template = $this->validDraft('FR');

        [$parent, $child] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parent);
            $this->runPausedPublishChild($child, $template->id, $actor->id, 'PCG 2026', ['FR']);
        }

        fclose($child);
        self::assertSame("publish-locked\n", fgets($parent));
        $assignment = app(TemplateAssignmentService::class)->assign(
            'FR',
            TemplateDomain::ChartOfAccounts,
            $template->id,
            $actor,
        );
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));

        self::assertSame($template->id, $assignment->template_id);
        self::assertSame(TemplateStatus::Published, $template->refresh()->status);
        self::assertSame($this->canonicalHash($template), $template->content_hash);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.template.published', 'entity_id' => $template->id]);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.assignment.created', 'entity_id' => $assignment->id]);
    }

    #[DataProvider('draftEditOperations')]
    public function test_real_publish_vs_stale_draft_edit_rechecks_under_the_service_lock(string $operation): void
    {
        $this->requirePostgreSqlRace("publish versus stale {$operation}");
        $actor = $this->actor();
        $template = $this->validDraft('FR');
        $staleTemplate = AdminTemplate::query()->findOrFail($template->id);
        $staleAccount = $template->accounts()->orderBy('sort_order')->firstOrFail();
        $originalTemplateName = $staleTemplate->name;
        $originalAccountName = $staleAccount->name;
        $createdCode = 'RACE-CREATE';
        [$parent, $child] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parent);
            $this->runPausedPublishChild($child, $template->id, $actor->id, 'PCG 2026', ['FR']);
        }
        fclose($child);
        self::assertSame("publish-locked\n", fgets($parent));

        $exception = null;
        try {
            DB::connection($template->getConnectionName())->transaction(function () use (
                $operation,
                $staleTemplate,
                $staleAccount,
                $createdCode,
                $template,
            ): void {
                if ($operation === 'header-save') {
                    $staleTemplate->name = 'Raced header edit';
                    $staleTemplate->save();

                    return;
                }
                if ($operation === 'account-save') {
                    $staleAccount->name = 'Raced account edit';
                    $staleAccount->save();

                    return;
                }
                if ($operation === 'account-delete') {
                    $staleAccount->delete();

                    return;
                }

                AdminTemplateAccount::query()->create([
                    'template_id' => $template->id,
                    'code' => $createdCode,
                    'name' => 'Raced account create',
                    'type' => AccountType::Asset,
                    'parent_code' => null,
                    'system_purpose' => null,
                    'is_system' => false,
                    'sort_order' => 9999,
                ]);
            });
        } catch (LogicException $caught) {
            $exception = $caught;
        }

        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));

        self::assertInstanceOf(LogicException::class, $exception);
        self::assertStringContainsString('immutable', $exception->getMessage());
        self::assertSame(TemplateStatus::Published, $template->refresh()->status);
        self::assertSame($this->canonicalHash($template), $template->content_hash);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.template.published', 'entity_id' => $template->id]);

        if ($operation === 'header-save') {
            self::assertSame($originalTemplateName, $template->name);
        } elseif ($operation === 'account-save') {
            self::assertSame($originalAccountName, $staleAccount->refresh()->name);
        } elseif ($operation === 'account-delete') {
            self::assertDatabaseHas('admin_template_accounts', ['id' => $staleAccount->id]);
        } else {
            self::assertDatabaseMissing('admin_template_accounts', ['template_id' => $template->id, 'code' => $createdCode]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function draftEditOperations(): iterable
    {
        yield 'header save' => ['header-save'];
        yield 'account save' => ['account-save'];
        yield 'account delete' => ['account-delete'];
        yield 'account create' => ['account-create'];
    }

    public function test_real_repoint_vs_archive_services_share_assignment_first_global_order(): void
    {
        $this->requirePostgreSqlRace('repoint versus archive');
        $actor = $this->actor();
        $old = $this->published('FR', $actor);
        $new = $this->published('FR', $actor);
        $assignment = app(TemplateAssignmentService::class)->assign(
            'FR',
            TemplateDomain::ChartOfAccounts,
            $old->id,
            $actor,
        );

        [$parent, $child] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parent);
            $this->runLockedRepointChild($child, $assignment->id, $new->id, $actor->id);
        }
        fclose($child);
        self::assertSame("assignment-locked\n", fgets($parent));
        $archived = app(TemplatePublishingService::class)->archive($old->id, $actor);
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));

        self::assertSame(TemplateStatus::Archived, $archived->status);
        self::assertSame($new->id, $assignment->refresh()->template_id);
        self::assertSame(TemplateStatus::Published, $new->refresh()->status);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.assignment.repointed', 'entity_id' => $assignment->id]);
        self::assertDatabaseHas('admin_audit_logs', ['action' => 'country_defaults.template.archived', 'entity_id' => $old->id]);
    }

    public function test_real_new_assign_vs_archive_rechecks_after_the_template_lock(): void
    {
        $this->requirePostgreSqlRace('new assignment versus archive');
        $actor = $this->actor();
        $template = $this->published('DE', $actor);

        [$parent, $child] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parent);
            $this->runPausedAssignChild($child, $template->id, $actor->id, 'DE');
        }
        fclose($child);
        self::assertSame("assignment-template-locked\n", fgets($parent));

        $exception = null;
        try {
            app(TemplatePublishingService::class)->archive($template->id, $actor);
        } catch (DomainException $caught) {
            $exception = $caught;
        }

        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));

        self::assertInstanceOf(DomainException::class, $exception);
        self::assertStringContainsString('assignment', $exception->getMessage());
        self::assertSame(TemplateStatus::Published, $template->refresh()->status);
        self::assertDatabaseHas('country_template_assignments', ['country_code' => 'DE', 'template_id' => $template->id]);
        self::assertDatabaseMissing('admin_audit_logs', ['action' => 'country_defaults.template.archived', 'entity_id' => $template->id]);
    }

    public function test_lifecycle_services_declare_assignment_rows_before_sorted_template_locks(): void
    {
        $assignmentSource = (string) file_get_contents(app_path('Modules/CountryDefaults/Application/Services/TemplateAssignmentService.php'));
        self::assertLessThan(
            strpos($assignmentSource, '$templates = AdminTemplate::query()'),
            strpos($assignmentSource, '$assignment = CountryTemplateAssignment::query()'),
        );
        self::assertStringContainsString('sort($templateIds, SORT_STRING);', $assignmentSource);
        self::assertStringContainsString("->orderBy('id')", $assignmentSource);

        $publishingSource = (string) file_get_contents(app_path('Modules/CountryDefaults/Application/Services/TemplatePublishingService.php'));
        foreach (['archive', 'delete'] as $method) {
            $start = strpos($publishingSource, "public function {$method}");
            self::assertNotFalse($start);
            $body = substr($publishingSource, $start, 2400);
            $assignmentLock = strpos($body, 'CountryTemplateAssignment::query()');
            $templateLock = strpos($body, '$template = AdminTemplate::query()');
            self::assertNotFalse($assignmentLock, "{$method} must lock assignment rows.");
            self::assertNotFalse($templateLock, "{$method} must lock the template row.");
            self::assertLessThan(
                $templateLock,
                $assignmentLock,
                "{$method} must lock assignment rows before the template.",
            );
        }
    }

    public function test_publish_and_archive_require_exactly_one_locked_status_transition(): void
    {
        $publishingSource = (string) file_get_contents(app_path('Modules/CountryDefaults/Application/Services/TemplatePublishingService.php'));

        self::assertSame(2, substr_count($publishingSource, 'if ($updated !== 1)'));
    }

    /** @param list<string> $scope */
    private function runPausedPublishChild(mixed $socket, string $templateId, string $actorId, string $standardRef, array $scope): never
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
                        fwrite($this->socket, "publish-locked\n");
                        usleep(400_000);
                    }

                    return $this->delegate->version();
                }
            });
            $actor = SuperAdmin::query()->findOrFail($actorId);
            app(TemplatePublishingService::class)->publish($templateId, $standardRef, $scope, $actor);
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            if (is_resource($socket)) {
                fwrite($socket, 'error:'.$exception->getMessage()."\n");
                fclose($socket);
            }
            exit(1);
        }
    }

    private function runLockedRepointChild(mixed $socket, string $assignmentId, string $newTemplateId, string $actorId): never
    {
        try {
            $this->resetChildConnection();
            $connection = DB::connection((new AdminTemplate)->getConnectionName());
            $connection->beginTransaction();
            $connection->table('country_template_assignments')->where('id', $assignmentId)->lockForUpdate()->first();
            if (! is_resource($socket)) {
                throw new LogicException('Race socket is unavailable.');
            }
            fwrite($socket, "assignment-locked\n");
            usleep(250_000);
            $actor = SuperAdmin::query()->findOrFail($actorId);
            app(TemplateAssignmentService::class)->assign(
                'FR',
                TemplateDomain::ChartOfAccounts,
                $newTemplateId,
                $actor,
            );
            $connection->commit();
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            if (is_resource($socket)) {
                fwrite($socket, 'error:'.$exception->getMessage()."\n");
                fclose($socket);
            }
            exit(1);
        }
    }

    private function runPausedAssignChild(mixed $socket, string $templateId, string $actorId, string $countryCode): never
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
                        usleep(400_000);
                    }

                    return $this->delegate->version();
                }
            });
            $actor = SuperAdmin::query()->findOrFail($actorId);
            app(TemplateAssignmentService::class)->assign(
                $countryCode,
                TemplateDomain::ChartOfAccounts,
                $templateId,
                $actor,
            );
            fclose($socket);
            exit(0);
        } catch (Throwable $exception) {
            if (is_resource($socket)) {
                fwrite($socket, 'error:'.$exception->getMessage()."\n");
                fclose($socket);
            }
            exit(1);
        }
    }

    /** @return array{resource, resource} */
    private function socketPair(): array
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for the PostgreSQL lifecycle race proof.');
        }
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        return [$sockets[0], $sockets[1]];
    }

    private function requirePostgreSqlRace(string $label): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped("PostgreSQL-only lifecycle race ({$label}); SQLite cannot prove row-lock concurrency.");
        }
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped("PostgreSQL-only lifecycle race ({$label}) requires ext-pcntl.");
        }
    }

    private function resetChildConnection(): void
    {
        DB::purge((new AdminTemplate)->getConnectionName());
    }

    private function published(string $country, SuperAdmin $actor): AdminTemplate
    {
        $draft = $this->validDraft($country);

        return app(TemplatePublishingService::class)->publish($draft->id, 'Standard 2026', [$country], $actor);
    }

    private function validDraft(string $country): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Race fixture '.Str::random(8),
            'status' => TemplateStatus::Draft,
        ]);
        DB::connection($template->getConnectionName())->transaction(function () use ($template, $country): void {
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
            foreach (ProtectedAccountCodeRegistry::forCountry($country) as $protected) {
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

        return $template;
    }

    private function canonicalHash(AdminTemplate $template): string
    {
        $rows = $template->accounts()->orderBy('sort_order')->get()->map(static fn (AdminTemplateAccount $account): array => [
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'parent_code' => $account->parent_code,
            'system_purpose' => $account->system_purpose,
            'is_system' => $account->is_system,
            'sort_order' => $account->sort_order,
        ])->all();

        return app(CanonicalCoaSerializer::class)->hash(array_values($rows));
    }

    private function actor(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Race operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}
