<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Import\LegacyCoaBootstrapImporter;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;
use Throwable;

final class BootstrapKeyAssertionImportTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    /** @return list<string|null> */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    public function test_import_creates_exactly_three_draft_keyed_templates_and_is_idempotent(): void
    {
        self::assertSame(3, AdminTemplate::query()->whereNotNull('bootstrap_key')->count());
        self::assertSame(3, AdminTemplate::query()->where('status', TemplateStatus::Draft->value)->whereNotNull('bootstrap_key')->count());

        $this->runMigration('up');

        self::assertSame(3, AdminTemplate::query()->whereNotNull('bootstrap_key')->count());
    }

    public function test_keyed_wrong_domain_missing_rows_altered_content_and_published_state_abort_diagnostically(): void
    {
        $cases = [
            'wrong domain' => static fn ($template): int => DB::connection($template->getConnectionName())->table('admin_templates')->where('id', $template->id)->update(['domain' => 'wrong']),
            'missing rows' => static fn ($template): int => DB::connection($template->getConnectionName())->table('admin_template_accounts')
                ->where('template_id', $template->id)
                ->whereNotIn('code', DB::connection($template->getConnectionName())->table('admin_template_accounts')->where('template_id', $template->id)->whereNotNull('parent_code')->pluck('parent_code'))
                ->limit(1)
                ->delete(),
            'altered content' => static fn ($template): int => DB::connection($template->getConnectionName())->table('admin_template_accounts')->where('template_id', $template->id)->limit(1)->update(['name' => 'tampered']),
            'published state' => static fn ($template): int => DB::connection($template->getConnectionName())->table('admin_templates')->where('id', $template->id)->update(['status' => TemplateStatus::Published->value]),
        ];

        foreach ($cases as $label => $mutate) {
            $template = AdminTemplate::query()->where('bootstrap_key', 'coa.tn.legacy-v1')->firstOrFail();
            $mutate($template);

            try {
                $this->runMigration('up');
                self::fail("{$label} collision must abort.");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('coa.tn.legacy-v1', $exception->getMessage());
                self::assertStringContainsString('bootstrap assertion failed', $exception->getMessage());
            }

            DB::connection($template->getConnectionName())->table('admin_templates')->where('id', $template->id)->delete();
            $this->runMigration('up');
        }
    }

    public function test_failed_attempt_rolls_back_and_is_retryable(): void
    {
        $template = AdminTemplate::query()->where('bootstrap_key', 'coa.fr.legacy-v1')->firstOrFail();
        DB::connection($template->getConnectionName())->table('admin_template_accounts')->where('template_id', $template->id)->delete();
        DB::connection($template->getConnectionName())->table('admin_templates')->where('id', $template->id)->delete();

        $this->installImportFailureTrigger();
        try {
            $this->runMigration('up');
            self::fail('Injected row failure must abort the import.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('injected bootstrap import failure', $exception->getMessage());
        } finally {
            $this->removeImportFailureTrigger();
        }
        self::assertDatabaseMissing('admin_templates', ['bootstrap_key' => 'coa.fr.legacy-v1']);

        $this->runMigration('up');
        self::assertDatabaseHas('admin_templates', [
            'bootstrap_key' => 'coa.fr.legacy-v1',
            'status' => TemplateStatus::Draft->value,
        ]);
    }

    public function test_bootstrap_key_is_guarded_bootstrap_only_and_immutable_at_the_model_boundary(): void
    {
        try {
            AdminTemplate::query()->forceCreate([
                'domain' => TemplateDomain::ChartOfAccounts,
                'name' => 'forced arbitrary key',
                'status' => TemplateStatus::Draft,
                'bootstrap_key' => 'forbidden.forced',
            ]);
            self::fail('Forced model creation may not mint bootstrap provenance.');
        } catch (LogicException $exception) {
            self::assertStringContainsString('model boundary', $exception->getMessage());
        }

        $ordinary = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'ordinary',
            'status' => TemplateStatus::Draft,
            'bootstrap_key' => 'forbidden',
        ]);
        self::assertNull($ordinary->bootstrap_key);

        $bootstrap = AdminTemplate::query()->where('bootstrap_key', 'coa.generic.legacy-v1')->firstOrFail();
        $bootstrap->bootstrap_key = 'changed';
        $this->expectException(LogicException::class);
        $bootstrap->save();
    }

    public function test_bootstrap_import_provenance_has_one_production_caller_and_one_key_writer(): void
    {
        $productionRoots = [app_path(), database_path('migrations')];
        $importCallers = [];
        $keyWriters = [];
        $genericEscapeHatches = [];
        foreach ($productionRoots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                $source = (string) file_get_contents($path);
                $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                if (str_contains($source, '->importAll()')) {
                    $importCallers[] = $relative;
                }
                if (preg_match('/[\'\"]bootstrap_key[\'\"]\s*=>/', $source) === 1) {
                    $keyWriters[] = $relative;
                }
                if (str_contains($source, 'withinBootstrapImport')) {
                    $genericEscapeHatches[] = $relative;
                }
            }
        }

        sort($importCallers);
        sort($keyWriters);
        self::assertSame(
            ['database/migrations/2026_08_11_100300_import_legacy_coa_templates_as_drafts.php'],
            $importCallers,
        );
        self::assertSame(
            ['app/Modules/CountryDefaults/Infrastructure/Import/LegacyCoaBootstrapImporter.php'],
            $keyWriters,
        );
        self::assertSame([], $genericEscapeHatches);
    }

    public function test_migration_down_refuses_published_certified_bootstrap_history(): void
    {
        $actor = $this->m4Actor();
        $template = AdminTemplate::query()->where('bootstrap_key', 'coa.tn.legacy-v1')->firstOrFail();
        $published = app(TemplatePublishingService::class)->publish(
            $template->id,
            'Authenticated legacy certification',
            ['TN'],
            $actor,
        );
        $rowCount = $published->accounts()->count();

        try {
            $this->runMigration('down');
            self::fail('Rollback must never delete published certified bootstrap history.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not an untouched draft', $exception->getMessage());
        }

        self::assertSame(TemplateStatus::Published, $published->refresh()->status);
        self::assertSame($rowCount, $published->accounts()->count());
        self::assertSame($actor->id, $published->certified_by);

        DB::connection($published->getConnectionName())->table('admin_templates')->where('id', $published->id)->delete();
        DB::connection($published->getConnectionName())->table('admin_audit_logs')->where('super_admin_id', $actor->id)->delete();
        DB::connection($published->getConnectionName())->table('super_admins')->where('id', $actor->id)->delete();
        $this->runMigration('up');
    }

    public function test_migration_down_refuses_an_assigned_bootstrap_and_preserves_the_assignment(): void
    {
        $template = AdminTemplate::query()->where('bootstrap_key', 'coa.fr.legacy-v1')->firstOrFail();
        $assignmentId = Str::uuid()->toString();
        DB::connection($template->getConnectionName())->table('country_template_assignments')->insert([
            'id' => $assignmentId,
            'country_code' => 'FR',
            'domain' => TemplateDomain::ChartOfAccounts->value,
            'template_id' => $template->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->runMigration('down');
            self::fail('Rollback must refuse an assigned bootstrap draft.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('assigned', $exception->getMessage());
        }

        self::assertDatabaseHas('admin_templates', ['id' => $template->id]);
        self::assertDatabaseHas('country_template_assignments', ['id' => $assignmentId, 'template_id' => $template->id]);

        DB::connection($template->getConnectionName())->table('country_template_assignments')->where('id', $assignmentId)->delete();
    }

    public function test_migration_down_refuses_to_erase_clone_provenance(): void
    {
        $actor = $this->m4Actor();
        $template = AdminTemplate::query()->where('bootstrap_key', 'coa.generic.legacy-v1')->firstOrFail();
        $clone = app(TemplatePublishingService::class)->cloneToDraft($template->id, 'Preserved provenance', $actor);

        try {
            $this->runMigration('down');
            self::fail('Rollback must refuse a bootstrap template referenced by clone provenance.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('clone provenance', $exception->getMessage());
        }

        self::assertSame($template->id, $clone->refresh()->cloned_from_id);
        self::assertDatabaseHas('admin_templates', ['id' => $template->id]);

        DB::connection($template->getConnectionName())->table('admin_templates')->where('id', $clone->id)->delete();
        DB::connection($template->getConnectionName())->table('admin_audit_logs')->where('super_admin_id', $actor->id)->delete();
        DB::connection($template->getConnectionName())->table('super_admins')->where('id', $actor->id)->delete();
    }

    public function test_concurrent_import_uses_a_controlled_overlap_and_recovers_the_unique_conflict(): void
    {
        $this->runMigration('down');
        $reports = [];
        if (DB::getDriverName() !== 'pgsql') {
            $reports[] = app(LegacyCoaBootstrapImporter::class)->importAll();
            $reports[] = app(LegacyCoaBootstrapImporter::class)->importAll();
        } else {
            if (! function_exists('pcntl_fork')) {
                self::markTestSkipped('PostgreSQL bootstrap concurrency proof requires ext-pcntl.');
            }
            $lockKey = 880041;
            $this->installConcurrentImportBarrier($lockKey);
            $children = [];
            $overlapObserved = false;
            $observedWaitSnapshots = [];
            $lockHeld = false;
            $connection = null;
            try {
                foreach ([0, 1] as $index) {
                    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                    if ($sockets === false) {
                        self::fail('Unable to create bootstrap importer synchronization sockets.');
                    }
                    [$parent, $child] = $sockets;
                    $pid = pcntl_fork();
                    if ($pid < 0) {
                        self::fail('Unable to fork bootstrap importer.');
                    }
                    if ($pid === 0) {
                        fclose($parent);
                        try {
                            fwrite($child, "ready\n");
                            if (fgets($child) !== "purge\n") {
                                throw new RuntimeException('Concurrent importer did not receive the inherited-connection purge signal.');
                            }
                            DB::purge();
                            DB::purge('central');
                            $childConnection = DB::connection((new AdminTemplate)->getConnectionName());
                            $childConnection->statement("SET application_name = 'm4_bootstrap_race_{$index}'");
                            fwrite($child, "purged\n");
                            if (fgets($child) !== "go\n") {
                                throw new RuntimeException('Concurrent importer did not receive the start barrier release.');
                            }
                            $report = app(LegacyCoaBootstrapImporter::class)->importAll();
                            fwrite($child, 'result:'.json_encode($report, JSON_THROW_ON_ERROR)."\n");
                        } catch (Throwable $exception) {
                            fwrite($child, 'error:'.$exception->getMessage()."\n");
                        }
                        fclose($child);
                        exit(0);
                    }
                    fclose($child);
                    $children[] = [$pid, $parent];
                }

                foreach ($children as [, $socket]) {
                    self::assertSame("ready\n", fgets($socket));
                }
                foreach ($children as [, $socket]) {
                    fwrite($socket, "purge\n");
                }
                foreach ($children as [, $socket]) {
                    self::assertSame("purged\n", fgets($socket));
                }

                DB::purge();
                DB::purge('central');
                $connection = DB::connection((new AdminTemplate)->getConnectionName());
                $connection->select('SELECT pg_advisory_lock(?)', [$lockKey]);
                $lockHeld = true;
                foreach ($children as [, $socket]) {
                    fwrite($socket, "go\n");
                }

                $deadline = microtime(true) + 20;
                do {
                    $waiting = $connection->select(<<<'SQL'
                        SELECT application_name, wait_event_type, wait_event
                        FROM pg_stat_activity
                        WHERE application_name LIKE 'm4_bootstrap_race_%'
                    SQL);
                    $waitEvents = array_map(static function (object $row): string {
                        $values = get_object_vars($row);

                        return strtolower(is_string($values['wait_event'] ?? null) ? $values['wait_event'] : '');
                    }, $waiting);
                    if ($waitEvents !== [] && $waitEvents !== ['clientread', 'clientread']) {
                        $observedWaitSnapshots[json_encode($waitEvents, JSON_THROW_ON_ERROR)] = $waitEvents;
                    }
                    $overlapObserved = count($waiting) === 2
                        && in_array('advisory', $waitEvents, true)
                        && in_array('transactionid', $waitEvents, true);
                    if (! $overlapObserved) {
                        usleep(20_000);
                    }
                } while (! $overlapObserved && microtime(true) < $deadline);
            } finally {
                if ($lockHeld && $connection !== null) {
                    $connection->select('SELECT pg_advisory_unlock(?)', [$lockKey]);
                }
            }

            foreach ($children as [$pid, $socket]) {
                $payload = trim((string) stream_get_contents($socket));
                fclose($socket);
                pcntl_waitpid($pid, $status);
                self::assertStringStartsWith('result:', $payload, $payload);
                $decoded = json_decode(substr($payload, 7), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $reports[] = $decoded;
            }
            DB::purge();
            DB::purge('central');
            $this->removeConcurrentImportBarrier();
            self::assertTrue(
                $overlapObserved,
                'Both importers must overlap at the unique-key boundary. Observed: '.json_encode(array_values($observedWaitSnapshots), JSON_THROW_ON_ERROR),
            );
        }

        $outcomes = array_merge(...array_map('array_values', $reports));
        self::assertContains('created', $outcomes);
        if (DB::getDriverName() === 'pgsql') {
            self::assertContains('recovered_unique_conflict', $outcomes);
        } else {
            self::assertContains('verified_existing', $outcomes);
        }
        self::assertSame(3, AdminTemplate::query()->whereNotNull('bootstrap_key')->count());
        foreach (['coa.tn.legacy-v1' => 139, 'coa.fr.legacy-v1' => 144, 'coa.generic.legacy-v1' => 61] as $key => $count) {
            self::assertSame($count, AdminTemplate::query()->where('bootstrap_key', $key)->firstOrFail()->accounts()->count());
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_11_100300_import_legacy_coa_templates_as_drafts.php');
    }

    private function runMigration(string $method): void
    {
        $callable = [$this->migration(), $method];
        if (! is_callable($callable)) {
            self::fail("Bootstrap migration method {$method} is not callable.");
        }
        $callable();
    }

    private function installImportFailureTrigger(): void
    {
        $connection = DB::connection((new AdminTemplate)->getConnectionName());
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION country_defaults_fail_fr_import() RETURNS trigger AS $$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM admin_templates
                        WHERE id = NEW.template_id AND bootstrap_key = 'coa.fr.legacy-v1'
                    ) THEN
                        RAISE EXCEPTION 'injected bootstrap import failure';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER country_defaults_fail_fr_import
                BEFORE INSERT ON admin_template_accounts
                FOR EACH ROW EXECUTE FUNCTION country_defaults_fail_fr_import();
            SQL);

            return;
        }

        $connection->unprepared(<<<'SQL'
            CREATE TRIGGER country_defaults_fail_fr_import
            BEFORE INSERT ON admin_template_accounts
            WHEN EXISTS (
                SELECT 1 FROM admin_templates
                WHERE id = NEW.template_id AND bootstrap_key = 'coa.fr.legacy-v1'
            )
            BEGIN
                SELECT RAISE(ABORT, 'injected bootstrap import failure');
            END
        SQL);
    }

    private function removeImportFailureTrigger(): void
    {
        $connection = DB::connection((new AdminTemplate)->getConnectionName());
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared('DROP TRIGGER IF EXISTS country_defaults_fail_fr_import ON admin_template_accounts');
            $connection->unprepared('DROP FUNCTION IF EXISTS country_defaults_fail_fr_import()');

            return;
        }

        $connection->unprepared('DROP TRIGGER IF EXISTS country_defaults_fail_fr_import');
    }

    private function installConcurrentImportBarrier(int $lockKey): void
    {
        DB::connection((new AdminTemplate)->getConnectionName())->unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION country_defaults_import_barrier() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.bootstrap_key = 'coa.tn.legacy-v1' THEN
                    PERFORM pg_advisory_xact_lock({$lockKey});
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
            CREATE TRIGGER country_defaults_import_barrier
            AFTER INSERT ON admin_templates
            FOR EACH ROW EXECUTE FUNCTION country_defaults_import_barrier();
        SQL);
    }

    private function removeConcurrentImportBarrier(): void
    {
        $connection = DB::connection((new AdminTemplate)->getConnectionName());
        $connection->unprepared('DROP TRIGGER IF EXISTS country_defaults_import_barrier ON admin_templates');
        $connection->unprepared('DROP FUNCTION IF EXISTS country_defaults_import_barrier()');
    }
}
