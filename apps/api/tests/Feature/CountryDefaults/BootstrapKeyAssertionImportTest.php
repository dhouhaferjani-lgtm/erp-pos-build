<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class BootstrapKeyAssertionImportTest extends TestCase
{
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

        try {
            Event::listen('eloquent.created: '.AdminTemplate::class, static function (AdminTemplate $created): void {
                if ($created->bootstrap_key === 'coa.fr.legacy-v1') {
                    throw new RuntimeException('injected bootstrap import failure');
                }
            });
            $this->runMigration('up');
            self::fail('Injected row failure must abort the import.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('injected bootstrap import failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.AdminTemplate::class);
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

    public function test_concurrent_import_attempts_converge_on_one_complete_keyed_set(): void
    {
        $this->runMigration('down');
        $results = [];
        if (DB::getDriverName() !== 'pgsql') {
            $this->runMigration('up');
            $this->runMigration('up');
            $results = ['ok', 'ok'];
        } else {
            if (! function_exists('pcntl_fork')) {
                self::markTestSkipped('PostgreSQL bootstrap concurrency proof requires ext-pcntl.');
            }
            $children = [];
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
                        DB::purge();
                        DB::purge('central');
                        $this->runMigration('up');
                        fwrite($child, 'ok');
                    } catch (Throwable $exception) {
                        fwrite($child, 'error:'.$exception->getMessage());
                    }
                    fclose($child);
                    exit(0);
                }
                fclose($child);
                $children[] = [$pid, $parent, $index];
            }
            foreach ($children as [$pid, $socket]) {
                $results[] = stream_get_contents($socket);
                fclose($socket);
                pcntl_waitpid($pid, $status);
            }
            DB::purge();
            DB::purge('central');
        }

        sort($results);
        self::assertSame(['ok', 'ok'], $results);
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
}
