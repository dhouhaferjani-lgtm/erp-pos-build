<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Concerns\WarnsOnTenantScopeDrift;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use ArrayObject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * R1 (2026-08-05 cat-(b) wave-1 adversarial review, Important).
 *
 * The wave-1 conversions all narrow their per-tenant queries with an explicit
 * `companies.tenant_id` predicate, because under the pre-flip compat mode
 * (`tenancy_resolver.db_per_tenant=false`) every tenant shares one database and
 * without it each tenant's pass would sweep the whole fleet.
 *
 * Under database-per-tenant that predicate is redundant *if the column is
 * correct*. When it is NOT — a tenant database restored from another tenant's
 * dump, a seeder default, a mis-provisioned tenant — the row is now SILENTLY
 * excluded where the pre-conversion fleet-wide query would have picked it up.
 * For `channels:reconcile` the consequence compounds: a drifted channel is
 * never reconciled AND never gets a webhook-directory pointer, so its webhooks
 * 404 forever with no signal at all.
 *
 * This helper is that signal. It is deliberately inert under compat mode, where
 * the unfiltered set legitimately contains every other tenant's rows and a
 * delta would be pure noise.
 */
final class WarnsOnTenantScopeDriftTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_inert_under_compat_mode_and_never_runs_the_probes(): void
    {
        config(['tenancy_resolver.db_per_tenant' => false]);

        $probed = false;
        $records = $this->captureLog();

        $dropped = $this->subject()->probe(
            $this->tenant(),
            function () use (&$probed): int {
                $probed = true;

                return 99;
            },
            static fn (): int => 1,
            static fn (): array => ['x'],
        );

        $this->assertSame(0, $dropped);
        $this->assertFalse(
            $probed,
            'Under compat mode the unfiltered set legitimately holds the whole fleet — the counting query must not '
            .'even be issued, let alone warned about.',
        );
        $this->assertSame([], $records->getArrayCopy());
    }

    public function test_it_stays_silent_when_the_predicate_drops_nothing(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $idsProbed = false;
        $records = $this->captureLog();

        $dropped = $this->subject()->probe(
            $this->tenant(),
            static fn (): int => 7,
            static fn (): int => 7,
            function () use (&$idsProbed): array {
                $idsProbed = true;

                return [];
            },
        );

        $this->assertSame(0, $dropped);
        $this->assertFalse($idsProbed, 'The id probe is the expensive one — it must only run when there IS a delta.');
        $this->assertSame([], $records->getArrayCopy());
    }

    public function test_a_delta_is_warned_with_both_counts_and_the_offending_ids(): void
    {
        config(['tenancy_resolver.db_per_tenant' => true]);

        $tenant = $this->tenant();
        $records = $this->captureLog();

        $dropped = $this->subject()->probe(
            $tenant,
            static fn (): int => 5,
            static fn (): int => 3,
            static fn (): array => ['drifted-1', 'drifted-2'],
        );

        $this->assertSame(2, $dropped);

        $lines = array_values($records->getArrayCopy());
        $this->assertCount(1, $lines, 'Drift must produce exactly ONE line per tenant per resource per run.');
        $this->assertSame('warning', $lines[0]->level);
        $this->assertSame('channels', $lines[0]->context['resource']);
        $this->assertSame($tenant->id, $lines[0]->context['tenant_id']);
        $this->assertSame(5, $lines[0]->context['rows_in_tenant_database']);
        $this->assertSame(3, $lines[0]->context['rows_matching_predicate']);
        $this->assertSame(2, $lines[0]->context['rows_dropped']);
        $this->assertSame(['drifted-1', 'drifted-2'], $lines[0]->context['dropped_ids']);
    }

    /**
     * @return ArrayObject<int, MessageLogged>
     */
    private function captureLog(): ArrayObject
    {
        /** @var ArrayObject<int, MessageLogged> $records */
        $records = new ArrayObject;

        Log::listen(function (MessageLogged $message) use ($records): void {
            $records[] = $message;
        });

        return $records;
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Drift Tenant',
            'slug' => 'drift-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function subject(): object
    {
        return new class
        {
            use WarnsOnTenantScopeDrift;

            /**
             * @param  callable(): int  $countAll
             * @param  callable(): int  $countMatched
             * @param  callable(): list<string>  $droppedIds
             */
            public function probe(Tenant $tenant, callable $countAll, callable $countMatched, callable $droppedIds): int
            {
                return $this->warnOnTenantScopeDrift('channels', $tenant, $countAll, $countMatched, $droppedIds);
            }
        };
    }
}
