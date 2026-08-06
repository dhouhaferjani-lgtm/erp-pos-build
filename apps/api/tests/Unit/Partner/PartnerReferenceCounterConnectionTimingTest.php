<?php

declare(strict_types=1);

namespace Tests\Unit\Partner;

use App\Modules\Partner\Application\Services\PartnerReferenceCounter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression test for the 2026-08-06 live-verification incident:
 * `DELETE /api/v1/partners/{id}` 500'd unconditionally with
 * `SQLSTATE[42P01] Undefined table: relation "documents" does not exist
 * (Connection: central)`.
 *
 * ROOT CAUSE: `PartnerReferenceCounter` constructor-injected
 * `Illuminate\Database\ConnectionInterface`. Laravel resolves that to a
 * CONCRETE connection object at construction time, and `PartnerController`
 * (which builds this class as a dependency) is `make()`'d during
 * `Route::gatherMiddleware()` -> `controllerMiddleware()` — which runs
 * BEFORE the route's own middleware pipeline (`ResolveTenancy`) has swapped
 * `database.default` from `central` to the tenant DB. The captured
 * connection was therefore permanently pinned to `central`, which has no
 * tenant tables — every partner delete 500'd, with or without documents.
 *
 * WHY `actingAs()` CANNOT CATCH THIS: `DeletePartnerTest` (and most feature
 * tests in this suite) authenticate via `$this->actingAs($user, 'sanctum')`,
 * which sets `Auth::guard('sanctum')->setUser($user)` directly and never
 * sends a request through `ResolveTenancy`'s bearer-token branch (the ONLY
 * branch that flips `database.default` in single-schema test mode — see
 * `ResolveTenancy::tenantFromBearer()`). With no real bearer token, the
 * connection is never swapped mid-request in the first place, so "was the
 * connection captured before or after the swap" is not an observable
 * distinction under `actingAs()` — the bug is structurally invisible to
 * that test methodology. Any future feature test using `actingAs()` would
 * pass against BOTH the buggy and fixed code, because both read the same
 * never-swapped default connection.
 *
 * This test instead directly swaps `database.default` between construction
 * and the call (mirroring what `ResolveTenancy` does mid-request via
 * Stancl's `tenancy()->initialize()` -> `DatabaseTenancyBootstrapper`),
 * which is the only way to observe the defect in isolation.
 */
class PartnerReferenceCounterConnectionTimingTest extends TestCase
{
    private const string CONNECTION_AT_CONSTRUCTION = 'partner_reference_counter_probe_before';

    private const string CONNECTION_AT_CALL_TIME = 'partner_reference_counter_probe_after';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::CONNECTION_AT_CONSTRUCTION, self::CONNECTION_AT_CALL_TIME] as $connectionName) {
            Config::set('database.connections.'.$connectionName, [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ]);

            foreach (['documents', 'payments', 'pos_receipts'] as $table) {
                Schema::connection($connectionName)->create($table, function ($blueprint) use ($table): void {
                    $blueprint->id();
                    $blueprint->string('partner_id');
                    if ($table === 'documents') {
                        $blueprint->timestamp('deleted_at')->nullable();
                    }
                });
            }
        }

        // A reference row that exists ONLY on the "before" connection — a
        // counter that captured its connection at construction time would
        // wrongly report this row.
        DB::connection(self::CONNECTION_AT_CONSTRUCTION)->table('documents')->insert([
            'partner_id' => 'decoy-should-never-be-read',
        ]);
    }

    protected function tearDown(): void
    {
        Config::set('database.default', 'sqlite');

        parent::tearDown();
    }

    public function test_counter_resolves_connection_at_call_time_not_construction_time(): void
    {
        $partnerId = (string) Str::uuid();

        // Construct while the CONSTRUCTION-time connection is the default —
        // mirrors PartnerController being make()'d during
        // Route::gatherMiddleware(), before ResolveTenancy has run.
        Config::set('database.default', self::CONNECTION_AT_CONSTRUCTION);

        /** @var PartnerReferenceCounter $counter */
        $counter = $this->app->make(PartnerReferenceCounter::class);

        // Simulate ResolveTenancy's mid-request swap, which happens AFTER
        // the controller (and this counter, one of its constructor
        // dependencies) has already been built.
        Config::set('database.default', self::CONNECTION_AT_CALL_TIME);

        DB::connection(self::CONNECTION_AT_CALL_TIME)->table('documents')->insert([
            'partner_id' => $partnerId,
            'deleted_at' => null,
        ]);

        $counts = $counter->countFor($partnerId);

        // A construction-time-captured connection would read the "before"
        // handle: it would never see this partner's row (planted only on
        // "after"), reporting `[]` — falsely declaring the partner safe to
        // delete despite the swap. It also would have silently ignored the
        // decoy row (different partner_id), so a naive "did it return
        // something" check wouldn't catch the defect — the assertion must
        // pin the exact count for THIS partner id.
        $this->assertSame(['documents' => 1], $counts);
    }

    public function test_counter_never_leaks_a_reference_row_planted_on_the_construction_time_connection(): void
    {
        // Defends against a regression in the OPPOSITE direction: a fix
        // that accidentally caches `connection()`'s return value at
        // construction (e.g. resolving it once in the constructor and
        // reusing it) would read the decoy row on
        // CONNECTION_AT_CONSTRUCTION forever, even after the swap, for a
        // partner id that never existed on that connection.
        $partnerId = (string) Str::uuid();

        Config::set('database.default', self::CONNECTION_AT_CONSTRUCTION);

        /** @var PartnerReferenceCounter $counter */
        $counter = $this->app->make(PartnerReferenceCounter::class);

        Config::set('database.default', self::CONNECTION_AT_CALL_TIME);

        $counts = $counter->countFor($partnerId);

        $this->assertSame([], $counts);
    }
}
