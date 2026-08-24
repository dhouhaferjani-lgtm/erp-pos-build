<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

use Illuminate\Database\ConnectionInterface;

/**
 * Reads the VALUE-SET CHECK constraints out of `pg_constraint` and turns each
 * one into the exact set of literals it admits for one column.
 *
 * Only single-column value-set shapes are recognised. Everything else on the
 * table — `pos_shifts_closed_logic`, `chk_fiscal_mandatory_core`,
 * `pos_terminals_code_format`, length and range checks — is deliberately NOT a
 * value-set constraint and is ignored: those express invariants BETWEEN columns
 * or on a column's shape, not its admissible enumeration, and folding them in
 * would make the parity comparison meaningless.
 *
 * The recognised shapes, as PostgreSQL renders them in
 * `pg_get_constraintdef()` (all four are live in this schema):
 *
 *   CHECK (((status)::text = ANY ((ARRAY['a'::character varying, 'b'::character varying])::text[])))
 *   CHECK (((col IS NULL) OR ((col)::text = ANY ((ARRAY[…])::text[]))))
 *   CHECK ((col)::text = ANY (ARRAY['a'::text]))
 *   CHECK ((col = ANY (ARRAY[1, 2, 3])))          -- integer-backed enums
 *   CHECK (((col)::text = 'a'::text))             -- degenerate single-value set
 *
 * The parser is a PURE static function so its liveness can be pinned on
 * synthetic definitions without a database (conv. 08): a reader that silently
 * stops matching is exactly the C6 rot mode that convention exists to prevent.
 */
final class PgValueSetCheckReader
{
    /**
     * table => column => array{accepted: list<string>, constraints: list<string>, nullable: bool}
     *
     * When a column carries MORE THAN ONE value-set CHECK the effective admissible
     * set is their INTERSECTION — every constraint must hold — so that is what is
     * reported, together with every contributing constraint name.
     *
     * @return array<string, array<string, array{accepted: list<string>, constraints: list<string>, nullable: bool}>>
     */
    public function read(ConnectionInterface $connection): array
    {
        $rows = $connection->select(
            <<<'SQL'
            SELECT rel.relname AS table_name,
                   con.conname AS constraint_name,
                   pg_get_constraintdef(con.oid) AS definition
              FROM pg_constraint con
              JOIN pg_class rel ON rel.oid = con.conrelid
              JOIN pg_namespace ns ON ns.oid = rel.relnamespace
             WHERE con.contype = 'c'
               AND ns.nspname = current_schema()
             ORDER BY rel.relname, con.conname
            SQL
        );

        $out = [];
        foreach ($rows as $row) {
            /** @var object{table_name: string, constraint_name: string, definition: string} $row */
            $parsed = self::parseValueSet($row->definition);
            if ($parsed === null) {
                continue;
            }

            $table = $row->table_name;
            $column = $parsed['column'];

            if (! isset($out[$table][$column])) {
                $out[$table][$column] = [
                    'accepted' => $parsed['values'],
                    'constraints' => [$row->constraint_name],
                    'nullable' => $parsed['nullable'],
                ];

                continue;
            }

            $existing = $out[$table][$column];
            $intersection = array_values(array_intersect($existing['accepted'], $parsed['values']));
            sort($intersection, SORT_STRING);

            $out[$table][$column] = [
                'accepted' => $intersection,
                'constraints' => [...$existing['constraints'], $row->constraint_name],
                'nullable' => $existing['nullable'] && $parsed['nullable'],
            ];
        }

        return $out;
    }

    /**
     * PURE. Returns null when the definition is not a single-column value-set CHECK.
     *
     * @return array{column: string, values: list<string>, nullable: bool}|null
     */
    public static function parseValueSet(string $definition): ?array
    {
        // 1. Drop every explicit cast — `::text`, `::character varying`,
        //    `::character varying[]`, `::bpchar` — they carry no information
        //    about the admissible set.
        $normalised = (string) preg_replace('/::[a-z][a-z ]*(\[\])?/', '', $definition);

        // 2. Parentheses in a rendered constraintdef are pure noise for these
        //    shapes (PostgreSQL over-parenthesises), so flatten them and collapse
        //    whitespace. Literals are extracted from the ORIGINAL bracket body
        //    below, so this cannot corrupt a value containing a parenthesis.
        $flat = str_replace(['(', ')'], ' ', $normalised);
        $flat = trim((string) preg_replace('/\s+/', ' ', $flat));

        // ARRAY form, with an optional `col IS NULL OR` prefix.
        if (preg_match('/^CHECK (?:(\w+) IS NULL OR )?(\w+) = ANY ARRAY\[(.*)\]\s*$/', $flat, $m) === 1) {
            $nullGuard = $m[1] === '' ? null : $m[1];
            $column = $m[2];
            if ($nullGuard !== null && $nullGuard !== $column) {
                return null;
            }

            return [
                'column' => $column,
                'values' => self::literals($m[3]),
                'nullable' => $nullGuard !== null,
            ];
        }

        // ARRAY form with the null guard written AFTER the set.
        if (preg_match('/^CHECK (\w+) = ANY ARRAY\[(.*)\] OR (\w+) IS NULL\s*$/', $flat, $m) === 1) {
            if ($m[1] !== $m[3]) {
                return null;
            }

            return ['column' => $m[1], 'values' => self::literals($m[2]), 'nullable' => true];
        }

        // Degenerate single-value form.
        if (preg_match('/^CHECK (?:(\w+) IS NULL OR )?(\w+) = \'((?:[^\']|\'\')*)\'\s*$/', $flat, $m) === 1) {
            $nullGuard = $m[1] === '' ? null : $m[1];
            if ($nullGuard !== null && $nullGuard !== $m[2]) {
                return null;
            }

            return [
                'column' => $m[2],
                'values' => [str_replace("''", "'", $m[3])],
                'nullable' => $nullGuard !== null,
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function literals(string $body): array
    {
        $values = [];
        if (preg_match_all('/\'((?:[^\']|\'\')*)\'/', $body, $matches) > 0 && $matches[1] !== []) {
            foreach ($matches[1] as $literal) {
                $values[] = str_replace("''", "'", $literal);
            }
        } else {
            // Integer-backed enums render unquoted: ARRAY[1, 2, 3].
            foreach (explode(',', $body) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $values[] = $piece;
                }
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
