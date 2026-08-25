<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `php artisan treasury:orphan-census {--tenant=} {--limit=} {--json}`
 *
 * READ-ONLY census of the nine bare-uuid treasury columns catalogued as
 * **DS-1** in `docs/handoff/TRIAGE-state-machine-audit-2026-08-23.md` §2 —
 * the columns that `2025_11_30_120000_create_treasury_tables.php` declares as
 * plain `uuid()` with no FK constraint:
 *
 *   `payment_repositories.location_id` (:34), `.responsible_user_id` (:35),
 *   `.account_id` (:38); `payment_methods.default_journal_id` (:75),
 *   `.default_account_id` (:76), `.fee_account_id` (:77);
 *   `payments.instrument_id` (:144), `.repository_id` (:145),
 *   `.journal_entry_id` (:160).
 *
 * **Why this command exists.** The DS-1 constraint lane wants to retrofit
 * real FKs. An `ADD CONSTRAINT ... FOREIGN KEY` is additive in *form* but
 * VALIDATING in effect: Postgres scans every existing row and aborts the
 * whole per-tenant migration on the first orphan, mid-fleet, leaving mixed
 * schema state under unattended `tenants:migrate`. Nobody has ever measured
 * the orphan population, because no detector covers any of the nine —
 * {@see ReconcileTreasuryCommand}'s invariant 2 walks
 * `repository_movements.journal_entry_id`, not `payments.*`. This command is
 * that detector, and NOTHING ELSE: it ships zero migrations, performs zero
 * writes, and never repairs anything it finds. Its `--json` output is the
 * go/no-go input to the constraint lane.
 *
 * **Strictly read-only, by construction and by test.** Every statement it
 * issues is a `SELECT` (a `count()` and a bounded sample `select`, both
 * driven by `whereNotExists`). It opens no transaction, takes no lock, and
 * touches no model with observers or booted hooks — it goes through the
 * query builder deliberately, so a `deleted_at` scope can never hide an
 * orphan that Postgres would still trip over. `tests/Feature/Treasury/
 * TreasuryOrphanCensusCommandTest::test_command_issues_only_select_statements`
 * pins this by listening to every query the command emits.
 *
 * **Soft deletes are deliberately NOT honoured.** An FK sees rows, not
 * scopes: a `payments.repository_id` pointing at a soft-deleted
 * `payment_repositories` row is NOT an orphan and must not be counted as
 * one, or the constraint lane would be blocked by a phantom population.
 *
 * **`payment_methods.default_journal_id` has no target table at all.** There
 * is no `journals` table in this schema — no migration, no model, no read
 * path (see `PaymentMethodController:90`, where a bare `exists:journals,id`
 * validator had to be removed because it 500'd). Reporting it as "0 orphans"
 * would be a false GO, so it is reported as `target_table_missing` with its
 * non-null population, and the FK lane must treat that column as
 * unconstrainable until an Accounting-module journals table exists.
 *
 * **Exit code.** Orphan FINDINGS never change the exit code — this is a
 * census, not a gate, and an operator must be able to run it fleet-wide
 * without a red pipeline. INCOMPLETE COVERAGE does: a tenant whose database
 * could not be opened, or whose iteration threw, means the census did not
 * see the whole fleet, and "zero orphans" from a partial run is precisely
 * the false GO that would abort a fleet migration halfway. Such a run exits
 * FAILURE, sets `"complete": false` and NAMES the defect in `"reason"`.
 *
 * **A run that visited ZERO tenants is the worst of those cases, not the
 * best.** An empty tenant directory produces no skips and no errors, so the
 * naive "nothing went wrong" test passes and the payload reads
 * `complete: true / orphans: 0 / exit 0` — a perfect GO from a run that
 * measured nothing. The realistic trigger is a wrong `DB_CENTRAL_DATABASE`
 * (or any `DB_*`) export in an ops shell, i.e. the most common deploy-time
 * mistake there is. Zero visited tenants therefore reports
 * `reason: "no_tenants_in_directory"` and exits FAILURE (gate r1 F-1).
 *
 * Rule 20 / master plan §14: runs in console context with NO CompanyContext,
 * and iterates tenants explicitly via
 * {@see TenantScopedCommand::forEachTenantFiltered()}; every per-tenant query
 * is additionally narrowed by `tenant_id` so the command body never issues a
 * cross-tenant query in single-schema compat mode.
 *
 * Not scheduled: this is an operator/deploy-time instrument.
 */
final class TreasuryOrphanCensusCommand extends TenantScopedCommand
{
    /**
     * Named `reason` values. The payload's consumer is the DS-1 constraint
     * lane's go/no-go, so an incomplete run must say WHICH way it was
     * incomplete — "not a GO" and "not a GO because you are pointed at the
     * wrong central database" call for very different operator responses.
     */
    private const REASON_TENANTS_SKIPPED = 'tenants_skipped';

    private const REASON_NO_TENANTS = 'no_tenants_in_directory';

    private const REASON_ITERATION_FAILED = 'tenant_iteration_failed';

    private const DEFAULT_SAMPLE_LIMIT = 10;

    private const MAX_SAMPLE_LIMIT = 1000;

    /**
     * DS-1's nine columns and the row each one is supposed to point at.
     *
     * Every target was verified against the migration tree, not against the
     * triage note: `locations` (`2025_11_30_105000:25`), `users`
     * (`2025_11_30_000003:17`), `accounts` (`2025_11_30_090000:14`),
     * `journal_entries` (`2025_11_30_100000:14`), `payment_instruments` and
     * `payment_repositories` (`2025_11_30_120000:90`, `:14`) — all uuid
     * primary keys in the SAME tenant database, which is why no
     * cross-database excuse exists for the missing FKs. `journals` is the one
     * target that does not exist; it is listed anyway so the census reports
     * the gap instead of omitting the column.
     *
     * @var list<array{table: string, column: string, target_table: string, target_column: string}>
     */
    private const COLUMNS = [
        ['table' => 'payment_repositories', 'column' => 'location_id', 'target_table' => 'locations', 'target_column' => 'id'],
        ['table' => 'payment_repositories', 'column' => 'responsible_user_id', 'target_table' => 'users', 'target_column' => 'id'],
        ['table' => 'payment_repositories', 'column' => 'account_id', 'target_table' => 'accounts', 'target_column' => 'id'],
        ['table' => 'payment_methods', 'column' => 'default_journal_id', 'target_table' => 'journals', 'target_column' => 'id'],
        ['table' => 'payment_methods', 'column' => 'default_account_id', 'target_table' => 'accounts', 'target_column' => 'id'],
        ['table' => 'payment_methods', 'column' => 'fee_account_id', 'target_table' => 'accounts', 'target_column' => 'id'],
        ['table' => 'payments', 'column' => 'instrument_id', 'target_table' => 'payment_instruments', 'target_column' => 'id'],
        ['table' => 'payments', 'column' => 'repository_id', 'target_table' => 'payment_repositories', 'target_column' => 'id'],
        ['table' => 'payments', 'column' => 'journal_entry_id', 'target_table' => 'journal_entries', 'target_column' => 'id'],
    ];

    /** @var string */
    protected $signature = 'treasury:orphan-census
        {--tenant= : restrict the census to one tenant id (fails loudly if that tenant is never reached)}
        {--limit=10 : how many orphan sample ids to report per column (0 = counts only)}
        {--json : emit a single machine-readable JSON document instead of the human tables}';

    /** @var string */
    protected $description = 'READ-ONLY census of the nine unconstrained treasury uuid columns (DS-1). Reports orphan counts + samples per tenant; writes nothing, repairs nothing.';

    protected function executeCommand(): int
    {
        $tenantFilter = $this->stringOption('tenant');
        $limit = $this->sampleLimit();
        $json = (bool) $this->option('json');

        /** @var list<array<string, mixed>> $tenantReports */
        $tenantReports = [];

        $exit = $this->forEachTenantFiltered($tenantFilter, function (Tenant $tenant) use ($limit, &$tenantReports): int {
            $tenantReports[] = [
                'tenant_id' => (string) $tenant->id,
                'tenant_name' => (string) $tenant->name,
                'columns' => $this->censusForTenant((string) $tenant->id, $limit),
            ];

            return self::SUCCESS;
        });

        $reason = $this->incompletenessReason($exit);

        $payload = $this->buildPayload($tenantReports, $reason);

        if ($json) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->renderHuman($payload);
        }

        // Findings never fail the run; incomplete coverage does.
        return $reason === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Why this run may NOT be read as a GO — null when coverage was complete.
     *
     * Three disqualifying shapes, checked most-specific first:
     *
     *   - a tenant was SKIPPED (its database could not be opened, or the
     *     existence probe itself could not be answered — see
     *     {@see TenantScopedCommand::forEachTenantNarrowed()}). The base treats
     *     the not-provisioned case as a WARNING; for a go/no-go census it is
     *     disqualifying, because the unmeasured tenant is exactly the one whose
     *     orphans would abort the fleet migration;
     *   - ZERO tenants were visited. Nothing was skipped and nothing threw, so
     *     every other signal says "clean" — this is the empty-directory false
     *     GO of gate r1 F-1, and it is the only failure mode of this command
     *     that looks *better* the more wrong it is;
     *   - the aggregate exit was non-SUCCESS: a tenant's iteration threw, or an
     *     operator's `--tenant=` filter never opened a slot
     *     ({@see TenantScopedCommand::failIfTenantFilterUnvisited()}).
     */
    private function incompletenessReason(int $exit): ?string
    {
        if ($this->skippedTenantIds() !== []) {
            return self::REASON_TENANTS_SKIPPED;
        }

        if ($this->visitedTenantIds() === []) {
            return self::REASON_NO_TENANTS;
        }

        if ($exit !== self::SUCCESS) {
            return self::REASON_ITERATION_FAILED;
        }

        return null;
    }

    /**
     * The nine per-column results for ONE tenant, in DS-1 declaration order.
     *
     * @return list<array<string, mixed>>
     */
    private function censusForTenant(string $tenantId, int $limit): array
    {
        $results = [];

        foreach (self::COLUMNS as $spec) {
            $results[] = $this->censusForColumn($spec, $tenantId, $limit);
        }

        return $results;
    }

    /**
     * @param  array{table: string, column: string, target_table: string, target_column: string}  $spec
     * @return array<string, mixed>
     */
    private function censusForColumn(array $spec, string $tenantId, int $limit): array
    {
        $base = [
            'table' => $spec['table'],
            'column' => $spec['column'],
            'target_table' => $spec['target_table'],
            'target_column' => $spec['target_column'],
        ];

        // A tenant whose migrations lag (or a source table retired in a later
        // schema) must be reported as UNMEASURED, never as clean.
        if (! Schema::hasTable($spec['table']) || ! Schema::hasColumn($spec['table'], $spec['column'])) {
            return $base + ['status' => 'source_missing', 'non_null' => null, 'orphans' => null, 'samples' => []];
        }

        $nonNull = $this->sourceQuery($spec, $tenantId)->count();

        if (! Schema::hasTable($spec['target_table'])) {
            // No target relation exists, so every non-null value is
            // unresolvable BY CONSTRUCTION and no FK can be declared at all.
            // Deliberately NOT folded into `orphans` (which would imply a
            // fixable data problem) — the FK lane needs the two apart.
            return $base + [
                'status' => 'target_table_missing',
                'non_null' => $nonNull,
                'orphans' => null,
                'samples' => $limit > 0
                    ? $this->sampleRows($this->sourceQuery($spec, $tenantId), $spec, $limit)
                    : [],
            ];
        }

        $orphanQuery = fn () => $this->sourceQuery($spec, $tenantId)
            ->whereNotExists(function ($sub) use ($spec): void {
                $sub->selectRaw('1')
                    ->from($spec['target_table'])
                    ->whereColumn(
                        $spec['target_table'].'.'.$spec['target_column'],
                        $spec['table'].'.'.$spec['column'],
                    );
            });

        $orphans = $orphanQuery()->count();

        return $base + [
            'status' => 'checked',
            'non_null' => $nonNull,
            'orphans' => $orphans,
            'samples' => ($orphans > 0 && $limit > 0) ? $this->sampleRows($orphanQuery(), $spec, $limit) : [],
        ];
    }

    /**
     * Non-null rows of one DS-1 column, narrowed to a single tenant.
     *
     * The `tenant_id` narrowing is what keeps the command body free of
     * cross-tenant queries under single-schema compat mode (master plan §14
     * invariant 2). Under db-per-tenant it is redundant but harmless. Tables
     * without a `tenant_id` column are not narrowed — none of the three
     * source tables is in that shape today, but the guard keeps the command
     * from throwing if one is ever restructured.
     *
     * @param  array{table: string, column: string, target_table: string, target_column: string}  $spec
     */
    private function sourceQuery(array $spec, string $tenantId): Builder
    {
        $query = DB::table($spec['table'])->whereNotNull($spec['table'].'.'.$spec['column']);

        if (Schema::hasColumn($spec['table'], 'tenant_id')) {
            $query->where($spec['table'].'.tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * Up to `$limit` offending rows: the source row id and the dangling value
     * it holds. Ordered by id so repeated runs of the same database produce
     * the same sample — a census that feeds a go/no-go must be diffable.
     *
     * @param  array{table: string, column: string, target_table: string, target_column: string}  $spec
     * @return list<array{id: string, value: string}>
     */
    private function sampleRows(Builder $query, array $spec, int $limit): array
    {
        $rows = $query
            ->select([
                $spec['table'].'.id as source_id',
                $spec['table'].'.'.$spec['column'].' as dangling_value',
            ])
            ->orderBy($spec['table'].'.id')
            ->limit($limit)
            ->get();

        $samples = [];

        foreach ($rows as $row) {
            $samples[] = [
                'id' => (string) $row->source_id,
                'value' => (string) $row->dangling_value,
            ];
        }

        return $samples;
    }

    /**
     * @param  list<array<string, mixed>>  $tenantReports
     * @param  string|null  $reason  null iff the census is complete
     * @return array<string, mixed>
     */
    private function buildPayload(array $tenantReports, ?string $reason): array
    {
        $orphans = 0;
        $checked = 0;
        $unresolvable = 0;

        foreach ($tenantReports as $report) {
            /** @var list<array<string, mixed>> $columns */
            $columns = $report['columns'];

            foreach ($columns as $column) {
                if ($column['status'] === 'checked') {
                    $checked++;
                    $orphans += (int) $column['orphans'];

                    continue;
                }

                $unresolvable++;
            }
        }

        return [
            'command' => 'treasury:orphan-census',
            'generated_at' => now()->toIso8601String(),
            'complete' => $reason === null,
            'reason' => $reason,
            'totals' => [
                'tenants_visited' => count($this->visitedTenantIds()),
                'tenants_skipped' => count($this->skippedTenantIds()),
                'columns_checked' => $checked,
                'columns_unresolvable' => $unresolvable,
                'orphans' => $orphans,
            ],
            'tenants' => $tenantReports,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        /** @var list<array<string, mixed>> $tenants */
        $tenants = $payload['tenants'];

        foreach ($tenants as $report) {
            $this->newLine();
            $this->line(sprintf('TENANT %s (%s)', $report['tenant_id'], $report['tenant_name']));

            /** @var list<array<string, mixed>> $columns */
            $columns = $report['columns'];

            $rows = [];

            foreach ($columns as $column) {
                $rows[] = [
                    $column['table'].'.'.$column['column'],
                    '-> '.$column['target_table'].'.'.$column['target_column'],
                    (string) $column['status'],
                    $column['non_null'] === null ? '-' : (string) $column['non_null'],
                    $column['orphans'] === null ? '-' : (string) $column['orphans'],
                    $this->formatSamples($column['samples']),
                ];
            }

            $this->table(['column', 'target', 'status', 'non-null', 'ORPHANS', 'sample ids'], $rows);
        }

        /** @var array<string, int> $totals */
        $totals = $payload['totals'];

        $this->newLine();
        $this->line(sprintf(
            'TOTAL: %d orphan(s) across %d checked column-slot(s); %d unresolvable slot(s); %d tenant(s) visited, %d skipped.',
            $totals['orphans'],
            $totals['columns_checked'],
            $totals['columns_unresolvable'],
            $totals['tenants_visited'],
            $totals['tenants_skipped'],
        ));

        if ($payload['complete'] !== true) {
            $this->error(sprintf(
                'CENSUS INCOMPLETE (%s) — %s Do NOT read this run as a GO for the DS-1 constraint lane.',
                (string) $payload['reason'],
                $this->reasonHint((string) $payload['reason']),
            ));
        }
    }

    /**
     * The one sentence an operator needs to act on each `reason`.
     */
    private function reasonHint(string $reason): string
    {
        return match ($reason) {
            self::REASON_NO_TENANTS => 'the central tenant directory yielded NO tenants, so nothing was measured — '.
                'check DB_CENTRAL_DATABASE / the DB_* environment of this shell before re-running.',
            self::REASON_TENANTS_SKIPPED => 'at least one tenant database could not be opened or probed, '.
                'so part of the fleet is unmeasured — restore it and re-run.',
            default => 'at least one tenant iteration failed, or a --tenant filter was never reached.',
        };
    }

    /**
     * @param  list<array{id: string, value: string}>  $samples
     */
    private function formatSamples(array $samples): string
    {
        if ($samples === []) {
            return '';
        }

        return implode(', ', array_map(static fn (array $s): string => $s['value'], $samples));
    }

    /**
     * `--limit` clamped to a sane band: negatives collapse to "counts only",
     * and an absurd value cannot turn a census into a full table dump on a
     * production database.
     */
    private function sampleLimit(): int
    {
        $raw = $this->stringOption('limit');

        if ($raw === null || ! ctype_digit($raw)) {
            return self::DEFAULT_SAMPLE_LIMIT;
        }

        return min((int) $raw, self::MAX_SAMPLE_LIMIT);
    }
}
