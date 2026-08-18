<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class CapabilityRegistryBumpTransitionTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_global_registry_bump_stays_red_until_last_assignment_is_repointed(): void
    {
        $actor = $this->m4Actor();
        $old = [];
        foreach ([['tn', 'TN'], ['fr', 'FR'], ['generic', '*'], ['generic', 'DE']] as [$fixture, $country]) {
            $template = $this->m4Published($fixture, $country, $actor);
            $this->m4Assign($country, $template, $actor);
            $old[] = $template->id;
        }

        $this->app->bind(CountryAccountingCapabilities::class, static fn (): CountryAccountingCapabilities => new class implements CountryAccountingCapabilities
        {
            public function supportsStampDuty(string $countryCode): bool
            {
                return strtoupper(trim($countryCode)) === 'TN';
            }

            public function version(): string
            {
                return 'v2';
            }
        });

        $this->m4Artisan()->assertFailed()->expectsOutputToContain('stale capability');
        foreach ([['tn', 'TN'], ['fr', 'FR'], ['generic', '*'], ['generic', 'DE']] as $index => [$fixture, $country]) {
            $replacement = $this->m4Published($fixture, $country, $actor);
            $this->m4Assign($country, $replacement, $actor);
            if ($index < 3) {
                $this->m4Artisan()->assertFailed();
            }
        }

        $this->m4Artisan()->assertSuccessful();
        self::assertSame(4, AdminTemplate::query()->whereIn('id', $old)->whereNotNull('content_hash')->count());
        self::assertSame(0, DB::connection((new AdminTemplate)->getConnectionName())->table('country_template_assignments')->whereIn('template_id', $old)->count());
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
