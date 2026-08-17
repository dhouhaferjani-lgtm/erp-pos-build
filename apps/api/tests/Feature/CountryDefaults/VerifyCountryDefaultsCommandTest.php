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
        $repoRoot = dirname(base_path(), 2);
        $process = new Process(
            [$repoRoot.'/scripts/phase-a-authenticated-verifier-fixture.sh', '--preflight-only'],
            $repoRoot,
            [
                'PGHOST' => '127.0.0.1',
                'PGPORT' => '5432',
                'PGUSER' => 'fixture-user',
                'PGPASSWORD' => 'fixture-password',
                'PHASE_A_MIGRATE_DB' => 'iziposcentral',
                'PHASE_A_FIXTURE_CONFIRM' => 'I_UNDERSTAND_THIS_REBUILDS_A_DISPOSABLE_DATABASE',
                'PHASE_A_FIXTURE_PORT' => '8197',
            ],
        );
        $process->run();

        self::assertSame(64, $process->getExitCode());
        self::assertStringContainsString('Refusing non-disposable database name: iziposcentral', $process->getErrorOutput());
    }

    private function m4Artisan(): PendingCommand
    {
        $command = $this->artisan('country-defaults:verify');
        if (! $command instanceof PendingCommand) {
            self::fail('The test console did not return a pending Country Defaults command.');
        }

        return $command;
    }
}
