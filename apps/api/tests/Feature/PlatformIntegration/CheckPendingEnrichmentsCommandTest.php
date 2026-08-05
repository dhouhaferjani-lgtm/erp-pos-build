<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Console\Concerns\WarnsOnTenantScopeDrift;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\EnrichmentQueryInterface;
use App\Shared\DTOs\PendingEnrichmentDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `enrichment:check-pending` — every 15 minutes.
 *
 * `ProductEnrichmentQueryService::findPendingEnrichments()` is a bare
 * `Product::query()`, and `products` is a TENANT table, so on the scheduler's
 * CENTRAL connection every tick raised 42P01 since the 2026-05-28 flip. The
 * per-record `CompanyContext::setCompanyId()` inside the loop was never the DB
 * selector under database-per-tenant — it only stamps the outbound
 * `X-Company-Id` header — so it could not save the query.
 *
 * This poller matters beyond its own output: it is the SAFETY NET for the
 * enrichment webhook. A webhook whose payload carries no tenant anchor is
 * discarded, and this command re-resolves the same submission within 15
 * minutes.
 */
final class CheckPendingEnrichmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    public function test_the_pending_query_runs_once_per_tenant_in_the_directory(): void
    {
        $this->createTenant('enrichment-poll-a');
        $this->createTenant('enrichment-poll-b');

        $spy = $this->bindQuerySpy();

        $this->assertSame(0, Artisan::call('enrichment:check-pending'));

        $this->assertSame(
            2,
            $spy->calls,
            'The pending-enrichment query must be issued once per tenant, inside that tenant — not once fleet-wide.',
        );
    }

    /**
     * B2 (2026-08-05 review). The call-count guards above pin only that the
     * closure runs N times; they cannot go red when the query itself is
     * fleet-wide, because the spy returns an empty collection and no product
     * row is ever read. This is the missing half: the ITERATING tenant must be
     * handed to the query, which is the only thing that scopes it under compat
     * mode (`db_per_tenant=false` — the suite's mode, where `forEachTenant()`
     * deliberately does not switch databases). The scoping itself is pinned
     * against real rows in
     * tests/Unit/Shared/ProductEnrichmentQueryServiceTest.php.
     */
    public function test_the_iterating_tenant_is_passed_to_the_pending_query(): void
    {
        $tenantA = $this->createTenant('enrichment-poll-anchor-a');
        $tenantB = $this->createTenant('enrichment-poll-anchor-b');

        $spy = $this->bindQuerySpy();

        $this->assertSame(0, Artisan::call('enrichment:check-pending'));

        $seen = $spy->tenantIds;
        sort($seen);
        $expected = [$tenantA->id, $tenantB->id];
        sort($expected);

        $this->assertSame(
            $expected,
            $seen,
            'Each pass must poll ONLY the tenant it is iterating — otherwise every tenant re-polls the same '
            .'50 products under compat mode, N-fold multiplying the outbound platform calls.',
        );
    }

    /**
     * The iteration guard. `forEachTenant()` opens a slot per row in the CENTRAL
     * tenant directory; with no tenants there is nothing to poll. The
     * pre-conversion shape issued exactly one fleet-wide `Product::query()`
     * regardless.
     */
    public function test_the_pending_query_is_never_issued_when_the_tenant_directory_is_empty(): void
    {
        $this->assertSame(0, Tenant::query()->count());

        $spy = $this->bindQuerySpy();

        $this->assertSame(0, Artisan::call('enrichment:check-pending'));

        $this->assertSame(0, $spy->calls);
    }

    /**
     * The `limit: 50` in findPendingEnrichments() is now a PER-TENANT budget,
     * not a fleet-wide one — pinned so the change of meaning is deliberate
     * rather than incidental.
     */
    public function test_the_batch_limit_is_applied_per_tenant(): void
    {
        $this->createTenant('enrichment-poll-limit-a');
        $this->createTenant('enrichment-poll-limit-b');

        $spy = $this->bindQuerySpy();

        $this->assertSame(0, Artisan::call('enrichment:check-pending'));

        $this->assertSame([50, 50], $spy->limits);
        $this->assertSame([10, 10], $spy->staleMinutes);
    }

    /**
     * Finding beyond the audit: `enrichment:check-pending` was never a
     * REGISTERED Artisan command. It lives outside `app/Console/Commands`, the
     * only path Laravel auto-discovers, so `php artisan list` never showed it —
     * yet `Schedule::command('enrichment:check-pending')` takes an unvalidated
     * string, so `schedule:list` printed it and the scheduler shelled out to a
     * command that did not exist on every tick. A scheduler-registration
     * assertion alone would NOT have caught this; the resolvable-name check is
     * the one that does.
     */
    public function test_the_command_is_a_registered_artisan_command(): void
    {
        $this->assertArrayHasKey('enrichment:check-pending', Artisan::all());
    }

    /**
     * N-6. The drift probes are additional QUERIES, so they must be inert in
     * exactly the mode where their answer would be meaningless: under
     * `tenancy_resolver.db_per_tenant=false` one database holds the whole
     * fleet, and an unfiltered count there is the fleet's count by definition.
     * {@see WarnsOnTenantScopeDrift} takes CLOSURES for
     * that reason; this pins that the poller passes them as closures and does
     * not evaluate them at the call site.
     */
    public function test_the_drift_probes_are_never_issued_in_compat_mode(): void
    {
        $spy = $this->bindQuerySpy();

        $this->createTenant('enrichment-probe-inert-a');
        $this->createTenant('enrichment-probe-inert-b');

        $this->assertSame(0, Artisan::call('enrichment:check-pending'));

        $this->assertSame(2, $spy->calls, 'The poll itself must still run once per tenant.');
        $this->assertSame(
            0,
            $spy->probeCalls,
            'A drift probe under compat mode would count every other tenant\'s rows and warn about all of them.',
        );
    }

    public function test_the_command_is_registered_with_the_scheduler(): void
    {
        $exitCode = Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('php artisan enrichment:check-pending', $output);
    }

    private function bindQuerySpy(): RecordingEnrichmentQuery
    {
        $spy = new RecordingEnrichmentQuery;
        $this->app->instance(EnrichmentQueryInterface::class, $spy);

        return $spy;
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', ucfirst($slug)),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }
}

/**
 * Records every `findPendingEnrichments()` call. Returns nothing, so the
 * command's outbound HTTP leg is never reached.
 *
 * NOTE: the tenancy the call runs under is NOT asserted here. The suite runs in
 * legacy row-level mode (`tenancy_resolver.db_per_tenant=false`, forced by
 * phpunit.xml), where `forEachTenant()` deliberately does not call
 * `tenancy()->initialize()` — so `tenant('id')` would be null on every call.
 * That the closure body runs under initialized tenancy in db-per-tenant mode is
 * pinned against the base class itself in
 * tests/Unit/Console/TenantScopedCommandForEachTenantTest.php.
 */
final class RecordingEnrichmentQuery implements EnrichmentQueryInterface
{
    public int $calls = 0;

    /** @var list<string> */
    public array $tenantIds = [];

    /** @var list<int> */
    public array $limits = [];

    /** @var list<int> */
    public array $staleMinutes = [];

    /**
     * N-6: the drift probes must stay UNCALLED under the compat mode this
     * suite runs in — the connection legitimately holds the whole fleet there,
     * so a delta would be pure noise and the counting queries are not worth
     * issuing.
     */
    public int $probeCalls = 0;

    /**
     * @return Collection<int, PendingEnrichmentDTO>
     */
    public function findPendingEnrichments(string $tenantId, int $limit, int $staleMinutes): Collection
    {
        $this->calls++;
        $this->tenantIds[] = $tenantId;
        $this->limits[] = $limit;
        $this->staleMinutes[] = $staleMinutes;

        /** @var Collection<int, PendingEnrichmentDTO> */
        return new Collection;
    }

    public function countPendingEnrichmentsOnConnection(int $staleMinutes): int
    {
        $this->probeCalls++;

        return 0;
    }

    public function countPendingEnrichmentsForTenant(string $tenantId, int $staleMinutes): int
    {
        $this->probeCalls++;

        return 0;
    }

    /**
     * @return list<string>
     */
    public function findPendingEnrichmentIdsOutsideTenant(string $tenantId, int $staleMinutes, int $limit): array
    {
        $this->probeCalls++;

        return [];
    }
}
