<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use ReflectionEnum;
use RuntimeException;

/**
 * EXPLICIT acknowledgements for the enum↔CHECK parity gate.
 *
 * Two verdicts in this gate are neither "covered" nor "debt", and filing them as
 * either is actively harmful — the register is the burn-down denominator a future
 * batch reads and acts on:
 *
 *  - **INTENDED_NARROWER.** A CHECK that is deliberately narrower than its enum
 *    because the column is one side of a PARTITION. `fiscal_event_quarantine`
 *    holds only the two NON-ledger-admissible integrity exception classes; the
 *    other four are quarantined in-table on `fiscal_events`. Filed as NARROWER
 *    debt, the plain reading of the register tells the next fiscal lane to "fix"
 *    it by widening the CHECK — which would DESTROY the ledger/quarantine
 *    partition and let a `sequence_gap` be written into the non-admissible table.
 *
 *  - **COVERED_BY_COMPOSITE.** A column whose value set is pinned by a CROSS-COLUMN
 *    constraint that `PgValueSetCheckReader` correctly refuses to parse as a value
 *    set. `pos_receipts.receipt_type` is pinned to exactly `{sale, return}` by
 *    `pos_receipts_return_logic`. Filed as MISSING it is a FALSE gap today, and — far
 *    worse — the day `ReceiptType` gains a third case that constraint silently
 *    becomes a live NARROWER write bomb on a fiscal table while the gate keeps
 *    reporting the same already-baselined MISSING key and stays green forever.
 *
 * An acknowledgement is NOT a waiver. It is a claim about the live schema, and
 * `EnumCheckParityTest` asserts every clause of that claim on the migrated
 * database, un-baselined. A change to EITHER side — the CHECK's admitted set, the
 * enum's cases, or the governing predicate — breaks the claim and fails the gate.
 * That is the whole point: relabelling the row without pinning it would just be a
 * quieter waiver.
 */
final class EnumCheckParityAcknowledgements
{
    public const KIND_INTENDED_NARROWER = 'INTENDED_NARROWER';

    public const KIND_COVERED_BY_COMPOSITE = 'COVERED_BY_COMPOSITE';

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function __construct(private readonly array $entries) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new RuntimeException('The enum<->CHECK parity acknowledgements file is missing: '.$path);
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('The enum<->CHECK parity acknowledgements file is not a JSON array: '.$path);
        }

        /** @var list<array<string, mixed>> $decoded */
        return new self(array_values($decoded));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public static function fromArray(array $entries): self
    {
        return new self(array_values($entries));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The relabel instruction handed to the analyzer: the RAW verdict key an
     * acknowledgement supersedes => the verdict it is rendered as.
     *
     * Keying on the raw verdict (`…::NARROWER`, `…::MISSING`) is deliberate. An
     * acknowledgement can only ever relabel the exact situation it was written for:
     * if the CHECK is dropped, widened, or replaced, the raw verdict changes, the
     * key stops matching, and the column falls straight back into the ratchet.
     *
     * @return array<string, string>
     */
    public function verdictMap(): array
    {
        $map = [];
        foreach ($this->entries as $entry) {
            /** @var array{key: string, kind: string} $entry */
            $map[$entry['key']] = $entry['kind'];
        }

        return $map;
    }

    /**
     * The set of backing values for which `$enum::$method()` returns `$expects`.
     *
     * This is what pins an INTENDED_NARROWER acknowledgement to the CODE that makes
     * it intentional rather than to a comment: `IntegrityExceptionClass::isAdmissibleToLedger()`
     * IS the ledger/quarantine partition, so a seventh case — or a change to the
     * predicate — moves this set and breaks the acknowledgement.
     *
     * @param  class-string  $enum
     * @return list<string>
     */
    public static function casesWherePredicate(string $enum, string $method, bool $expects): array
    {
        if (! enum_exists($enum)) {
            throw new RuntimeException('Not an enum: '.$enum);
        }
        if (! method_exists($enum, $method)) {
            throw new RuntimeException('Enum '.$enum.' has no method '.$method.'() — the acknowledgement predicate has been renamed or removed.');
        }

        $reflection = new ReflectionEnum($enum);
        $values = [];
        foreach ($enum::cases() as $case) {
            if ($expects !== $case->{$method}()) {
                continue;
            }
            $values[] = $reflection->isBacked() ? (string) $case->value : $case->name;
        }
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * PURE drift check for ONE acknowledgement against the live facts.
     *
     * @param  array<string, mixed>  $entry
     * @param  array{enum_cases: list<string>, accepted: list<string>|null, predicate_cases: list<string>|null, constraint_definitions: array<string, string>, in_population: bool, column_exists: bool}  $live
     * @return list<string>
     */
    public static function problems(array $entry, array $live): array
    {
        /** @var array{key: string, kind: string, table: string, column: string, enum: class-string} $entry */
        $key = $entry['key'];
        $problems = [];

        if (! $live['column_exists']) {
            return [$key.' — acknowledged, but the live schema has no such column. Delete or repoint the entry.'];
        }
        if (! $live['in_population']) {
            return [$key.' — acknowledged, but the column is no longer in the derived population '
                .'(the cast was removed or repointed). An acknowledgement for a column the gate does not assert is dead weight.'];
        }

        $enumCases = $live['enum_cases'];

        if ($entry['kind'] === self::KIND_INTENDED_NARROWER) {
            /** @var array{intended_set: list<string>, enum_cases_at_acknowledgement: list<string>, predicate: array{method: string, expects: bool, ref: string}} $entry */
            $intended = $entry['intended_set'];

            if ($enumCases !== $entry['enum_cases_at_acknowledgement']) {
                $problems[] = $key.' — the ENUM changed since this partition was acknowledged (was ['
                    .implode(', ', $entry['enum_cases_at_acknowledgement']).'], now ['.implode(', ', $enumCases).']). '
                    .'Re-derive which cases belong on each side of the partition BEFORE re-acknowledging; '
                    .'do NOT widen the CHECK to make this pass.';
            }
            if ($live['accepted'] === null) {
                $problems[] = $key.' — the acknowledged CHECK is GONE. The partition is no longer enforced at the DB level.';
            } elseif ($live['accepted'] !== $intended) {
                $problems[] = $key.' — the CHECK no longer admits exactly the acknowledged set (acknowledged ['
                    .implode(', ', $intended).'], live ['.implode(', ', $live['accepted']).']). '
                    .'If the CHECK was WIDENED, that is the partition being destroyed, not a gap being closed.';
            }
            if ($live['predicate_cases'] === null) {
                $problems[] = $key.' — the governing predicate '.$entry['predicate']['method'].'() could not be evaluated.';
            } elseif ($live['predicate_cases'] !== $intended) {
                $problems[] = $key.' — the governing predicate '.$entry['predicate']['method'].'() ('.$entry['predicate']['ref'].') '
                    .'now selects ['.implode(', ', $live['predicate_cases']).'], not the acknowledged ['.implode(', ', $intended).']. '
                    .'The CHECK and the code that defines the partition have diverged.';
            }
            if (array_values(array_diff($intended, $enumCases)) !== []) {
                $problems[] = $key.' — the acknowledged set contains values the enum cannot produce.';
            }
            if ($intended === $enumCases) {
                $problems[] = $key.' — the acknowledged set now EQUALS the enum, so the CHECK is not narrower any more. '
                    .'Delete the acknowledgement; the column is plain COVERED.';
            }

            return $problems;
        }

        if ($entry['kind'] === self::KIND_COVERED_BY_COMPOSITE) {
            /** @var array{constraint: string, pinned_set: list<string>} $entry */
            $definition = $live['constraint_definitions'][$entry['constraint']] ?? null;

            if ($definition === null) {
                $problems[] = $key.' — the pinning constraint '.$entry['constraint'].' no longer exists on '.$entry['table']
                    .'. The column is now genuinely unconstrained: delete the acknowledgement so it returns to the ratchet as MISSING.';
            } else {
                foreach ($enumCases as $case) {
                    if (! str_contains($definition, "'".$case."'")) {
                        $problems[] = $key.' — the pinning constraint '.$entry['constraint'].' does not mention enum case \''
                            .$case.'\'. It no longer pins the whole enum; that is a NARROWER write bomb on a live table.';
                    }
                }
            }

            if ($entry['pinned_set'] !== $enumCases) {
                $problems[] = $key.' — the enum no longer matches the acknowledged pinned set (acknowledged ['
                    .implode(', ', $entry['pinned_set']).'], enum now ['.implode(', ', $enumCases).']). '
                    .'Add an EXPLICIT value-set CHECK for this column and delete the acknowledgement.';
            }
            if ($live['accepted'] !== null) {
                $problems[] = $key.' — the column now carries a real value-set CHECK ['.implode(', ', $live['accepted']).']. '
                    .'Delete the acknowledgement and let the gate assert the column directly.';
            }

            return $problems;
        }

        return [$key.' — unknown acknowledgement kind "'.$entry['kind'].'".'];
    }
}
