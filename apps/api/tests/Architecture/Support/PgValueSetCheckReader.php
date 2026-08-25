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
 * would make the parity comparison meaningless. A cross-column constraint that
 * nevertheless PINS a column's value set (`pos_receipts_return_logic`) is not
 * parsed here either — it is acknowledged explicitly, see
 * `EnumCheckParityAcknowledgements` (verdict `COVERED_BY_COMPOSITE`).
 *
 * The recognised shapes, as PostgreSQL renders them in
 * `pg_get_constraintdef()` (all of them are live in this schema):
 *
 *   CHECK (((status)::text = ANY ((ARRAY['a'::character varying, 'b'::character varying])::text[])))
 *   CHECK (((col IS NULL) OR ((col)::text = ANY ((ARRAY[…])::text[]))))
 *   CHECK ((col)::text = ANY (ARRAY['a'::text]))
 *   CHECK ((col = ANY (ARRAY[1, 2, 3])))          -- integer-backed enums
 *   CHECK (((col)::text = 'a'::text))             -- degenerate single-value set
 *   CHECK (((col)::text = 'a'::text) OR ((col)::text = 'b'::text))
 *                                                 -- OR-chain of equalities (F-5)
 *   … any of the above with a trailing ` NOT VALID` (F-2)
 *
 * `NOT VALID` MATTERS AND IS NOT COSMETIC. The slice-D burn-down batches are
 * mandated to add every CHECK as `NOT VALID` first and `VALIDATE CONSTRAINT` in a
 * second step. PostgreSQL enforces a `NOT VALID` CHECK on every NEW write from the
 * moment it lands — only pre-existing rows are exempt — so a parser that returns
 * null for that shape is blind exactly while a freshly added (and possibly WRONG)
 * CHECK is already rejecting production writes. The suffix is therefore stripped
 * and reported as a distinct `not_validated` flag, never as absence.
 *
 * The parser is a PURE static function so its liveness can be pinned on
 * synthetic definitions without a database (conv. 08): a reader that silently
 * stops matching is exactly the C6 rot mode that convention exists to prevent.
 */
final class PgValueSetCheckReader
{
    /**
     * table => column => array{accepted: list<string>, constraints: list<string>, nullable: bool, not_validated: bool}
     *
     * When a column carries MORE THAN ONE value-set CHECK the effective admissible
     * set is their INTERSECTION — every constraint must hold — so that is what is
     * reported, together with every contributing constraint name.
     *
     * `not_validated` is true when ANY contributing constraint is `NOT VALID`:
     * the column's guarantee over EXISTING rows is only as strong as its weakest
     * constraint.
     *
     * @return array<string, array<string, array{accepted: list<string>, constraints: list<string>, nullable: bool, not_validated: bool}>>
     */
    public function read(ConnectionInterface $connection): array
    {
        // `convalidated` is selected explicitly rather than inferred only from the
        // rendered text: the catalogue column is the authoritative statement of
        // whether the constraint was validated against existing rows, and reading
        // it means a rendering change cannot turn a NOT VALID CHECK into a
        // silently-validated-looking one. There is deliberately NO filter on it —
        // an unvalidated CHECK is still enforced on every new write.
        $rows = $connection->select(
            <<<'SQL'
            SELECT rel.relname AS table_name,
                   con.conname AS constraint_name,
                   con.convalidated AS validated,
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
            /** @var object{table_name: string, constraint_name: string, validated: bool, definition: string} $row */
            $parsed = self::parseValueSet($row->definition);
            if ($parsed === null) {
                continue;
            }

            $table = $row->table_name;
            $column = $parsed['column'];
            $notValidated = $parsed['not_validated'] || ! (bool) $row->validated;

            if (! isset($out[$table][$column])) {
                $out[$table][$column] = [
                    'accepted' => $parsed['values'],
                    'constraints' => [$row->constraint_name],
                    'nullable' => $parsed['nullable'],
                    'not_validated' => $notValidated,
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
                'not_validated' => $existing['not_validated'] || $notValidated,
            ];
        }

        return $out;
    }

    /**
     * PURE. Returns null when the definition is not a single-column value-set CHECK.
     *
     * @return array{column: string, values: list<string>, nullable: bool, not_validated: bool}|null
     */
    public static function parseValueSet(string $definition): ?array
    {
        $flat = self::flatten($definition);

        // `… ) NOT VALID` is a validation-state suffix, not part of the predicate.
        // Stripped BEFORE matching (every shape regex anchors on end-of-string) and
        // reported as a flag. The pattern requires the suffix to be unquoted, so a
        // constraint whose last literal is the string 'NOT VALID' — which flattens
        // to a trailing apostrophe — is not mistaken for one.
        $notValidated = false;
        $stripped = (string) preg_replace('/\s+NOT VALID$/', '', $flat, 1, $count);
        if ($count === 1) {
            $notValidated = true;
            $flat = $stripped;
        }

        // R2-1 — A CONJUNCTION IS NEVER A SINGLE-COLUMN VALUE SET.
        //
        // PostgreSQL renders `CHECK (status IN (…) AND origin IN (…))` as two
        // `= ANY (ARRAY[…])` predicates joined by AND, and the flattened text ends
        // in `]` just like a plain ARRAY form does. Before this guard the ARRAY
        // body capture was greedy (`ARRAY\[(.*)\]\s*$`), so it swallowed the
        // intervening `] … ARRAY[` and returned ONE column admitting the UNION of
        // both halves. That union is routinely EXACTLY the governing enum's case
        // list — reproduced live on `payments.status`, where the gate reported
        // COVERED for a column PostgreSQL restricts to two of its four cases, i.e.
        // a NARROWER write bomb wearing a COVERED badge (gate r2 §R2-1). A FALSE
        // COVERED is the one failure mode that makes this whole gate lie, so a
        // conjunction is rejected outright, BEFORE any shape is matched.
        //
        // Belt and braces: the ARRAY bodies below additionally forbid `]` inside
        // the captured body, so even a conjunction this scan somehow misses cannot
        // produce a spanning capture. The residual cost of that second brace is
        // that a value set one of whose LITERALS contains `]` now reads null —
        // MISSING, the safe direction, and no such literal exists in this schema.
        //
        // The scan is QUOTE-AWARE. A naive `str_contains($flat, ' AND ')` would
        // reject `CHECK (label IN ('salt AND pepper', 'z'))`, which IS an ordinary
        // value set: a false MISSING is safe but it silently inflates the burn-down
        // denominator, so it is pinned against in the liveness provider.
        if (self::containsUnquotedAnd($flat)) {
            return null;
        }

        // ARRAY form, with an optional `col IS NULL OR` prefix.
        if (preg_match('/^CHECK (?:(\w+) IS NULL OR )?(\w+) = ANY ARRAY\[([^\]]*)\]\s*$/', $flat, $m) === 1) {
            $nullGuard = $m[1] === '' ? null : $m[1];
            $column = $m[2];
            if ($nullGuard !== null && $nullGuard !== $column) {
                return null;
            }

            return [
                'column' => $column,
                'values' => self::literals($m[3]),
                'nullable' => $nullGuard !== null,
                'not_validated' => $notValidated,
            ];
        }

        // ARRAY form with the null guard written AFTER the set.
        if (preg_match('/^CHECK (\w+) = ANY ARRAY\[([^\]]*)\] OR (\w+) IS NULL\s*$/', $flat, $m) === 1) {
            if ($m[1] !== $m[3]) {
                return null;
            }

            return [
                'column' => $m[1],
                'values' => self::literals($m[2]),
                'nullable' => true,
                'not_validated' => $notValidated,
            ];
        }

        // OR-chain of single-column equalities, with an optional `IS NULL` branch
        // anywhere in the chain. This subsumes the degenerate single-value form
        // (`CHECK ((kind)::text = 'only'::text)`), which is a one-element chain.
        //
        // Every branch must be either `col = 'literal'` or `col IS NULL` for the
        // SAME column. A branch carrying an AND clause (`pos_receipts_return_logic`)
        // or naming a second column is a cross-column invariant, not a value set,
        // and yields null — which reads as MISSING, i.e. the safe direction.
        return self::parseOrChain($flat, $notValidated);
    }

    /**
     * R2-1 — is there an ` AND ` OUTSIDE every single-quoted literal?
     *
     * `flatten()` has already dropped PostgreSQL's parenthesisation, so there is
     * no nesting left to distinguish a "top-level" AND from a nested one — which
     * is the correct answer anyway: a conjunction ANYWHERE in the predicate means
     * the constraint expresses more than one column's admissible enumeration, and
     * `parseValueSet()` must not claim to have read a single-column value set out
     * of it. The OR-chain path is unaffected: it rejects an AND-carrying branch on
     * its own (`pos_receipts_return_logic`), and now never sees one.
     */
    private static function containsUnquotedAnd(string $flat): bool
    {
        $length = strlen($flat);

        for ($i = 0; $i < $length;) {
            if ($flat[$i] === "'") {
                [, $i] = self::consumeLiteral($flat, $i);

                continue;
            }

            if (substr_compare($flat, ' AND ', $i, 5) === 0) {
                return true;
            }

            $i++;
        }

        return false;
    }

    /**
     * @return array{column: string, values: list<string>, nullable: bool, not_validated: bool}|null
     */
    private static function parseOrChain(string $flat, bool $notValidated): ?array
    {
        if (! str_starts_with($flat, 'CHECK ')) {
            return null;
        }

        $column = null;
        $values = [];
        $nullable = false;

        foreach (explode(' OR ', substr($flat, 6)) as $branch) {
            $branch = trim($branch);

            if (preg_match('/^(\w+) IS NULL$/', $branch, $m) === 1) {
                if ($column !== null && $m[1] !== $column) {
                    return null;
                }
                $column ??= $m[1];
                $nullable = true;

                continue;
            }

            if (preg_match('/^(\w+) = \'((?:[^\']|\'\')*)\'$/', $branch, $m) === 1) {
                if ($column !== null && $m[1] !== $column) {
                    return null;
                }
                $column = $m[1];
                $values[] = str_replace("''", "'", $m[2]);

                continue;
            }

            return null;
        }

        if ($column === null || $values === []) {
            return null;
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return ['column' => $column, 'values' => $values, 'nullable' => $nullable, 'not_validated' => $notValidated];
    }

    /**
     * QUOTE-AWARE normalisation.
     *
     * Outside single-quoted literals: explicit casts (`::text`,
     * `::character varying[]`, `::bpchar`) are dropped, PostgreSQL's
     * over-parenthesisation is flattened to whitespace, and whitespace runs are
     * collapsed. INSIDE a literal nothing is touched — the previous
     * implementation flattened parens across the whole string and then extracted
     * literals from the flattened body, so `'a(b)'` was silently read as `a b `
     * (a corrupted accepted-set, i.e. a false COVERED / false DIVERGENT vector).
     */
    private static function flatten(string $definition): string
    {
        $out = '';
        $pendingSpace = false;
        $length = strlen($definition);

        for ($i = 0; $i < $length;) {
            $char = $definition[$i];

            if ($char === "'") {
                if ($pendingSpace) {
                    $out .= ' ';
                    $pendingSpace = false;
                }
                [$literal, $i] = self::consumeLiteral($definition, $i);
                $out .= $literal;

                continue;
            }

            if ($char === ':' && ($definition[$i + 1] ?? '') === ':') {
                $consumed = self::consumeCast($definition, $i);
                if ($consumed !== null) {
                    $i = $consumed;

                    continue;
                }
            }

            if ($char === '(' || $char === ')' || ctype_space($char)) {
                $pendingSpace = $out !== '';
                $i++;

                continue;
            }

            if ($pendingSpace) {
                $out .= ' ';
                $pendingSpace = false;
            }
            $out .= $char;
            $i++;
        }

        return trim($out);
    }

    /**
     * Copies a single-quoted literal verbatim, `''` escapes included.
     *
     * @return array{0: string, 1: int} the literal (quotes included) and the offset just past it
     */
    private static function consumeLiteral(string $definition, int $offset): array
    {
        $literal = "'";
        $i = $offset + 1;
        $length = strlen($definition);

        while ($i < $length) {
            if ($definition[$i] === "'") {
                if (($definition[$i + 1] ?? '') === "'") {
                    $literal .= "''";
                    $i += 2;

                    continue;
                }
                $literal .= "'";
                $i++;
                break;
            }
            $literal .= $definition[$i];
            $i++;
        }

        return [$literal, $i];
    }

    /**
     * `::` followed by a lowercase type name (which may contain spaces, as in
     * `character varying`) and an optional `[]`. Mirrors the previous
     * `/::[a-z][a-z ]*(\[\])?/` exactly, but only ever applied outside literals.
     *
     * @return int|null the offset just past the cast, or null when this `::` does not start one
     */
    private static function consumeCast(string $definition, int $offset): ?int
    {
        if (preg_match('/\G::[a-z][a-z ]*(\[\])?/', $definition, $m, 0, $offset) !== 1) {
            return null;
        }

        return $offset + strlen($m[0]);
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
