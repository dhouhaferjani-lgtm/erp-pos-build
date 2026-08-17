<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Process\Process;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class VerifyCountryDefaultsCommandTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_drafts_only_state_and_missing_certification_metadata_fail_nonzero(): void
    {
        $this->m4Artisan()->assertFailed()->expectsOutputToContain('TN assignment');

        $actor = $this->m4Actor();
        foreach ([['tn', 'TN'], ['fr', 'FR'], ['generic', '*']] as [$fixture, $country]) {
            $template = $this->m4Published($fixture, $country, $actor);
            $this->m4Assign($country, $template, $actor);
        }
        $this->m4Artisan()->assertSuccessful();

        $assigned = CountryTemplateAssignment::query()
            ->where('country_code', 'FR')
            ->firstOrFail()
            ->template()
            ->firstOrFail();
        DB::connection($assigned->getConnectionName())->table('admin_templates')->where('id', $assigned->id)->update(['certified_by' => null]);
        $this->m4Artisan()->assertFailed()->expectsOutputToContain('certified_by');
    }

    public function test_every_assignment_and_unassigned_published_content_hash_is_scanned(): void
    {
        $actor = $this->m4Actor();
        foreach ([['tn', 'TN'], ['fr', 'FR'], ['generic', '*'], ['generic', 'DE']] as [$fixture, $country]) {
            $template = $this->m4Published($fixture, $country, $actor);
            $this->m4Assign($country, $template, $actor);
        }
        $history = $this->m4Published('generic', 'IT', $actor);
        DB::connection($history->getConnectionName())->table('admin_template_accounts')->where('template_id', $history->id)->limit(1)->update(['name' => 'tampered history']);

        $this->m4Artisan()->assertFailed()->expectsOutputToContain('content_hash');
    }

    public function test_authenticated_fixture_runner_rejects_a_production_like_database_before_execution(): void
    {
        $process = $this->fixturePreflight(['PHASE_A_MIGRATE_DB' => 'iziposcentral']);
        $process->run();

        self::assertSame(64, $process->getExitCode());
        self::assertStringContainsString('Refusing non-disposable database name: iziposcentral', $process->getErrorOutput());
    }

    public function test_authenticated_fixture_runner_rejects_inherited_database_url_precedence(): void
    {
        foreach (['DB_URL', 'DB_CENTRAL_URL'] as $variable) {
            $process = $this->fixturePreflight([
                $variable => 'postgresql://fixture:fixture@127.0.0.1:1/iziposcentral',
            ]);
            $process->run();

            self::assertSame(64, $process->getExitCode());
            self::assertStringContainsString("Refusing inherited database URL override: {$variable}", $process->getErrorOutput());
        }
    }

    public function test_authenticated_fixture_runner_rejects_cached_laravel_configuration(): void
    {
        $cachePath = tempnam(sys_get_temp_dir(), 'phase-a-config-cache-');
        self::assertIsString($cachePath);

        try {
            $process = $this->fixturePreflight(['APP_CONFIG_CACHE' => $cachePath]);
            $process->run();

            self::assertSame(64, $process->getExitCode());
            self::assertStringContainsString('Refusing cached Laravel configuration', $process->getErrorOutput());
        } finally {
            if (is_file($cachePath)) {
                unlink($cachePath);
            }
        }
    }

    public function test_authenticated_fixture_runner_pins_the_effective_laravel_connection(): void
    {
        $process = $this->fixturePreflight([
            'DB_CENTRAL_HOST' => 'production-db.example.test',
            'DB_CENTRAL_PORT' => '6543',
            'DB_CENTRAL_DATABASE' => 'iziposcentral',
            'DB_CENTRAL_USERNAME' => 'production-user',
            'DB_CENTRAL_PASSWORD' => 'production-password',
        ]);
        $process->run();

        self::assertSame(0, $process->getExitCode());
        self::assertStringContainsString(
            'Laravel effective central connection matches autoerp_country_defaults_scratch.',
            $process->getOutput(),
        );
    }

    private function m4Artisan(): PendingCommand
    {
        $command = $this->artisan('country-defaults:verify');
        if (! $command instanceof PendingCommand) {
            self::fail('The test console did not return a pending Country Defaults command.');
        }

        return $command;
    }

    /** @param array<string, string> $overrides */
    private function fixturePreflight(array $overrides = []): Process
    {
        $repoRoot = dirname(base_path(), 2);

        return new Process(
            [$repoRoot.'/scripts/phase-a-authenticated-verifier-fixture.sh', '--preflight-only'],
            $repoRoot,
            array_merge([
                'PGHOST' => '127.0.0.1',
                'PGPORT' => '5432',
                'PGUSER' => 'fixture-user',
                'PGPASSWORD' => 'fixture-password',
                'PHASE_A_MIGRATE_DB' => 'autoerp_country_defaults_scratch',
                'PHASE_A_FIXTURE_CONFIRM' => 'I_UNDERSTAND_THIS_REBUILDS_A_DISPOSABLE_DATABASE',
                'PHASE_A_FIXTURE_PORT' => '8197',
            ], $overrides),
        );
    }
}
