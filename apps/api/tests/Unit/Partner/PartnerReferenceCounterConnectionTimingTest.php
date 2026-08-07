<?php

declare(strict_types=1);

namespace Tests\Unit\Partner;

use App\Modules\Partner\Application\Services\PartnerReferenceCounter;
use App\Shared\Application\Partner\PartnerReferenceTable;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Database\Schema\Blueprint;
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
 * CONCRETE connection object at the moment the container builds it, and
 * `PartnerController` (which builds this dependency graph) is `make()`'d
 * during `Route::gatherMiddleware()` -> `controllerMiddleware()` — which
 * runs BEFORE the route's own middleware pipeline (`ResolveTenancy`) has
 * swapped `database.default` from `central` to the tenant DB. The captured
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
 *
 * LANE R2-S: the counter no longer reads tables itself — every owning module
 * tags an `App\Shared\Contracts\Partner\PartnerReferenceSource`. The timing
 * rule therefore has to hold for EVERY tagged source, so the probe schema
 * below is built from what the sources themselves declare rather than from
 * a hardcoded table list.
 *
 * WHY THE SOURCES ARE MATERIALISED WITH `iterator_to_array` — do not
 * "simplify" this to `$this->app->make(PartnerReferenceCounter::class)`.
 * `Container::tagged()` returns a `RewindableGenerator`, which `make()`s
 * each tagged source lazily, AT ITERATION TIME — i.e. inside `countFor()`,
 * which runs AFTER the swap. Resolving the counter through the container
 * therefore constructs every source on the post-swap connection and the
 * test cannot discriminate: the R2-S gate implanted a construction-time
 * connection capture in `TableBackedPartnerReferenceSource` and all three
 * tests here stayed GREEN. Materialising the tagged set BEFORE the swap is
 * what puts source construction on the pre-swap connection, which is what
 * production actually does — `PartnerController` is `make()`d during
 * `Route::gatherMiddleware()`, and any future eager consumer (a singleton,
 * a constructor-injected `iterable`, an `app->tagged()` hoisted out of the
 * closure) would construct the sources there too. This test pins the rule
 * for that shape, which is the only shape in which it can be violated.
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

            foreach ($this->declaredTables() as $table) {
                Schema::connection($connectionName)->create(
                    $table->table,
                    function (Blueprint $blueprint) use ($table): void {
                        $blueprint->id();
                        foreach ($table->referenceColumns() as $column) {
                            $blueprint->string($column->column)->nullable();
                            // Discriminator columns (polymorphic anchors)
                            // must exist too or the count query errors.
                            foreach (array_keys($column->where) as $guardColumn) {
                                $blueprint->string($guardColumn)->nullable();
                            }
                        }
                        if ($table->hasSoftDeletes) {
                            $blueprint->timestamp('deleted_at')->nullable();
                        }
                    },
                );
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

        $counter = $this->counterBuiltOnTheCurrentConnection();

        // Simulate ResolveTenancy's mid-request swap, which happens AFTER
        // the controller (and this counter, plus every tagged source it
        // consumes) has already been built.
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
        // that caches `connection()`'s return value at construction (e.g.
        // resolving it once in the constructor and reusing it) keeps reading
        // CONNECTION_AT_CONSTRUCTION forever, even after the swap.
        //
        // The row below is planted for THIS partner id on the pre-swap
        // connection ONLY. Correct code queries the post-swap connection and
        // finds nothing; a construction-time capture reports it and wrongly
        // blocks a partner that is in fact clean on the tenant DB.
        $partnerId = (string) Str::uuid();

        DB::connection(self::CONNECTION_AT_CONSTRUCTION)->table('documents')->insert([
            'partner_id' => $partnerId,
            'deleted_at' => null,
        ]);

        Config::set('database.default', self::CONNECTION_AT_CONSTRUCTION);

        $counter = $this->counterBuiltOnTheCurrentConnection();

        Config::set('database.default', self::CONNECTION_AT_CALL_TIME);

        $counts = $counter->countFor($partnerId);

        $this->assertSame([], $counts);
    }

    public function test_every_tagged_source_is_queried_on_the_call_time_connection(): void
    {
        // Per-source coverage: plant one row for the partner on the
        // "after" connection in EVERY declared table and assert the counter
        // reports every one of them. A source that resolved its connection
        // at construction time contributes nothing here and the missing key
        // names it.
        $partnerId = (string) Str::uuid();

        Config::set('database.default', self::CONNECTION_AT_CONSTRUCTION);

        $counter = $this->counterBuiltOnTheCurrentConnection();

        Config::set('database.default', self::CONNECTION_AT_CALL_TIME);

        $expected = [];
        foreach ($this->declaredTables() as $table) {
            $column = $table->referenceColumns()[0];

            DB::connection(self::CONNECTION_AT_CALL_TIME)->table($table->table)->insert(
                [$column->column => $partnerId] + $column->where,
            );
            $expected[$table->table] = 1;
        }

        $counts = $counter->countFor($partnerId);
        ksort($expected);
        ksort($counts);

        $this->assertSame($expected, $counts);
    }

    /**
     * A counter whose sources are ALL constructed right now, on whatever
     * `database.default` currently points at.
     *
     * `$this->app->make(PartnerReferenceCounter::class)` would NOT do this:
     * the binding passes `app->tagged(...)`, a `RewindableGenerator` that
     * defers each source's `make()` to iteration time — which happens inside
     * `countFor()`, after the swap. Under that shape a construction-time
     * connection capture is unobservable (proven: the gate's implanted
     * mutation kept all three tests green). `iterator_to_array` forces the
     * `make()`s here, before the swap.
     */
    private function counterBuiltOnTheCurrentConnection(): PartnerReferenceCounter
    {
        $sources = iterator_to_array($this->app->tagged(PartnerReferenceSource::class));

        return new PartnerReferenceCounter($sources);
    }

    /**
     * Every table declared by every tagged source.
     *
     * @return list<PartnerReferenceTable>
     */
    private function declaredTables(): array
    {
        $tables = [];

        /** @var PartnerReferenceSource $source */
        foreach ($this->app->tagged(PartnerReferenceSource::class) as $source) {
            $this->assertInstanceOf(
                TableBackedPartnerReferenceSource::class,
                $source,
                $source::class.' is tagged as a PartnerReferenceSource but does not extend '
                .'TableBackedPartnerReferenceSource, so neither the connection-timing rule nor '
                .'the schema-contract test can be enforced for it. Extend the base class, or '
                .'extend these two tests to cover the new shape.',
            );

            foreach ($source->tables() as $table) {
                $tables[] = $table;
            }
        }

        return $tables;
    }
}
