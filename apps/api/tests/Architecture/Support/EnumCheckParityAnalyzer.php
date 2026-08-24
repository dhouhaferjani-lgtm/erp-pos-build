<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * PURE comparison engine for the enum↔CHECK parity gate.
 *
 * Separating it from both the derivation and the database read is what makes the
 * gate testable in the sense conv. 08 requires: every tamper case the lane brief
 * names — a CHECK removed, a CHECK widened, a new enum-backed column with no
 * CHECK, a baseline entry that has gone stale — is expressible as a synthetic
 * input to `analyze()` + `partition()` and needs no PostgreSQL to demonstrate.
 */
final class EnumCheckParityAnalyzer
{
    /** The DB admits exactly the enum's cases. */
    public const VERDICT_COVERED = 'COVERED';

    /** No value-set CHECK on the column at all. */
    public const VERDICT_MISSING = 'MISSING';

    /** The CHECK admits values the enum cannot hydrate — a latent READ bomb. */
    public const VERDICT_WIDER = 'WIDER';

    /** The CHECK rejects values the enum can produce — a latent WRITE bomb. */
    public const VERDICT_NARROWER = 'NARROWER';

    /** The two sets overlap but neither contains the other. */
    public const VERDICT_DIVERGENT = 'DIVERGENT';

    /** The registry names a column the live schema does not have. */
    public const VERDICT_ABSENT = 'ABSENT';

    /**
     * @param  list<array{table: string, column: string, enum: class-string, cases: list<string>, backing: string, origin: string, model: string|null, scope: string}>  $columns
     * @param  array<string, array<string, array{accepted: list<string>, constraints: list<string>, nullable: bool}>>  $checks
     * @param  array<string, list<string>>  $schemaColumns  table => list of column names present in the live schema
     * @return list<array{key: string, table: string, column: string, enum: class-string, verdict: string, enum_cases: list<string>, accepted: list<string>|null, constraints: list<string>, extra: list<string>, absent_from_check: list<string>, origin: string}>
     */
    public function analyze(array $columns, array $checks, array $schemaColumns): array
    {
        $findings = [];

        foreach ($columns as $entry) {
            $table = $entry['table'];
            $column = $entry['column'];
            $enumCases = $entry['cases'];
            sort($enumCases, SORT_STRING);

            $present = isset($schemaColumns[$table]) && in_array($column, $schemaColumns[$table], true);

            if (! $present) {
                $findings[] = $this->finding($entry, self::VERDICT_ABSENT, $enumCases, null, [], [], []);

                continue;
            }

            $check = $checks[$table][$column] ?? null;
            if ($check === null) {
                $findings[] = $this->finding($entry, self::VERDICT_MISSING, $enumCases, null, [], [], []);

                continue;
            }

            $accepted = $check['accepted'];
            sort($accepted, SORT_STRING);

            $extra = array_values(array_diff($accepted, $enumCases));      // DB admits, enum cannot hydrate
            $unreachable = array_values(array_diff($enumCases, $accepted)); // enum produces, DB rejects

            $verdict = match (true) {
                $extra === [] && $unreachable === [] => self::VERDICT_COVERED,
                $extra !== [] && $unreachable === [] => self::VERDICT_WIDER,
                $extra === [] && $unreachable !== [] => self::VERDICT_NARROWER,
                default => self::VERDICT_DIVERGENT,
            };

            $findings[] = $this->finding($entry, $verdict, $enumCases, $accepted, $check['constraints'], $extra, $unreachable);
        }

        usort($findings, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return $findings;
    }

    /**
     * Every finding that is NOT a pass. The baseline key carries the VERDICT, so
     * a baselined MISSING column that later grows a WRONG (wider / narrower)
     * CHECK produces a key the baseline does not contain and fails.
     *
     * @param  list<array{key: string, table: string, column: string, enum: class-string, verdict: string, enum_cases: list<string>, accepted: list<string>|null, constraints: list<string>, extra: list<string>, absent_from_check: list<string>, origin: string}>  $findings
     * @return list<array{key: string, table: string, column: string, enum: class-string, verdict: string, enum_cases: list<string>, accepted: list<string>|null, constraints: list<string>, extra: list<string>, absent_from_check: list<string>, origin: string}>
     */
    public function failures(array $findings): array
    {
        return array_values(array_filter(
            $findings,
            static fn (array $f): bool => $f['verdict'] !== self::VERDICT_COVERED,
        ));
    }

    /**
     * Shrink-only partition, both directions fail-closed.
     *
     *  new   — a failure whose key the baseline does not carry (GROWTH).
     *  stale — a baseline key with no matching failure (the gap closed, or the
     *          column/verdict changed): the entry must be REMOVED, because a
     *          baseline that never shrinks is a permanent waiver.
     *
     * @param  list<array{key: string, table: string, column: string, enum: class-string, verdict: string, enum_cases: list<string>, accepted: list<string>|null, constraints: list<string>, extra: list<string>, absent_from_check: list<string>, origin: string}>  $findings
     * @param  list<string>  $baseline
     * @return array{new: list<array<string, mixed>>, stale: list<string>}
     */
    public function partition(array $findings, array $baseline): array
    {
        $baselineSet = array_fill_keys($baseline, false);

        $new = [];
        foreach ($this->failures($findings) as $failure) {
            if (array_key_exists($failure['key'], $baselineSet)) {
                $baselineSet[$failure['key']] = true;

                continue;
            }
            $new[] = $failure;
        }

        $stale = [];
        foreach ($baselineSet as $key => $matched) {
            if (! $matched) {
                $stale[] = (string) $key;
            }
        }
        sort($stale, SORT_STRING);

        return ['new' => $new, 'stale' => $stale];
    }

    /**
     * @param  array{table: string, column: string, enum: class-string, cases: list<string>, backing: string, origin: string, model: string|null, scope: string}  $entry
     * @param  list<string>  $enumCases
     * @param  list<string>|null  $accepted
     * @param  list<string>  $constraints
     * @param  list<string>  $extra
     * @param  list<string>  $unreachable
     * @return array{key: string, table: string, column: string, enum: class-string, verdict: string, enum_cases: list<string>, accepted: list<string>|null, constraints: list<string>, extra: list<string>, absent_from_check: list<string>, origin: string}
     */
    private function finding(array $entry, string $verdict, array $enumCases, ?array $accepted, array $constraints, array $extra, array $unreachable): array
    {
        return [
            'key' => $entry['table'].'.'.$entry['column'].'::'.$verdict,
            'table' => $entry['table'],
            'column' => $entry['column'],
            'enum' => $entry['enum'],
            'verdict' => $verdict,
            'enum_cases' => $enumCases,
            'accepted' => $accepted,
            'constraints' => $constraints,
            'extra' => $extra,
            'absent_from_check' => $unreachable,
            'origin' => $entry['origin'],
        ];
    }
}
