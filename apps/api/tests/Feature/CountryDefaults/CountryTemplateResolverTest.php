<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CountryTemplateResolver;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Exceptions\TemplateRecertificationRequiredException;
use App\Modules\CountryDefaults\Domain\Exceptions\TimbreCountryRequiresExactAssignmentException;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CountryDefaults\M4Fixtures;
use Tests\TestCase;

final class CountryTemplateResolverTest extends TestCase
{
    use M4Fixtures;
    use RefreshDatabase;

    public function test_exact_assignment_wins_and_country_codes_are_normalized(): void
    {
        $actor = $this->m4Actor();
        $wildcard = $this->m4Published('generic', '*', $actor);
        $exact = $this->m4Published('tn', 'TN', $actor);
        $this->m4Assign('*', $wildcard, $actor);
        $this->m4Assign('TN', $exact, $actor);

        $resolved = app(CountryTemplateResolver::class)->resolve(
            TemplateDomain::ChartOfAccounts,
            ' tn ',
        );

        self::assertSame($exact->id, $resolved->id);
    }

    public function test_non_timbre_country_uses_pinned_wildcard_without_country_catalog_lookup(): void
    {
        $actor = $this->m4Actor();
        $wildcard = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $wildcard, $actor);

        $resolved = app(CountryTemplateResolver::class)->resolve(
            TemplateDomain::ChartOfAccounts,
            'zz',
        );

        self::assertSame($wildcard->id, $resolved->id);
    }

    public function test_timbre_country_without_exact_assignment_fails_with_typed_error(): void
    {
        $actor = $this->m4Actor();
        $wildcard = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $wildcard, $actor);

        $this->expectException(TimbreCountryRequiresExactAssignmentException::class);

        app(CountryTemplateResolver::class)->resolve(TemplateDomain::ChartOfAccounts, 'TN');
    }

    public function test_missing_wildcard_assignment_fails_loudly(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('wildcard');

        app(CountryTemplateResolver::class)->resolve(TemplateDomain::ChartOfAccounts, 'ZZ');
    }

    public function test_stale_exact_and_wildcard_assignments_require_recertification(): void
    {
        $actor = $this->m4Actor();
        $wildcard = $this->m4Published('generic', '*', $actor);
        $exact = $this->m4Published('fr', 'FR', $actor);
        $this->m4Assign('*', $wildcard, $actor);
        $this->m4Assign('FR', $exact, $actor);

        $this->app->bind(CountryAccountingCapabilities::class, static fn (): CountryAccountingCapabilities => new class implements CountryAccountingCapabilities
        {
            public function supportsStampDuty(string $countryCode): bool
            {
                return false;
            }

            public function version(): string
            {
                return 'next-version';
            }
        });

        foreach (['FR', 'ZZ'] as $country) {
            try {
                app(CountryTemplateResolver::class)->resolve(TemplateDomain::ChartOfAccounts, $country);
                self::fail("{$country} must reject a stale assigned template.");
            } catch (TemplateRecertificationRequiredException $exception) {
                self::assertStringContainsString('stale capability registry version', $exception->getMessage());
            }
        }
    }

    public function test_next_call_observes_an_assignment_repoint_without_cache(): void
    {
        $actor = $this->m4Actor();
        $first = $this->m4Published('generic', '*', $actor);
        $second = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $first, $actor);
        $resolver = app(CountryTemplateResolver::class);

        self::assertSame($first->id, $resolver->resolve(TemplateDomain::ChartOfAccounts, 'ZZ')->id);

        $this->m4Assign('*', $second, $actor);

        self::assertSame($second->id, $resolver->resolve(TemplateDomain::ChartOfAccounts, 'ZZ')->id);
    }

    public function test_resolver_reads_the_logical_central_connection_after_default_connection_changes(): void
    {
        $actor = $this->m4Actor();
        $wildcard = $this->m4Published('generic', '*', $actor);
        $this->m4Assign('*', $wildcard, $actor);
        $central = (new AdminTemplate)->getConnectionName();
        self::assertNotSame('', $central);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');
        try {
            self::assertSame(
                $wildcard->id,
                app(CountryTemplateResolver::class)->resolve(TemplateDomain::ChartOfAccounts, 'ZZ')->id,
            );
        } finally {
            DB::setDefaultConnection($original);
        }
    }
}
