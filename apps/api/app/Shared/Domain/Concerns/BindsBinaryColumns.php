<?php

declare(strict_types=1);

namespace App\Shared\Domain\Concerns;

use App\Shared\Domain\ByteaBinding;

/**
 * Binds a model's BINARY columns (`bytea` on PostgreSQL, `blob` on SQLite) as
 * `PDO::PARAM_LOB` on INSERT, so canonical bytes carrying RFC 8785 `\"` / `\\`
 * escapes survive the write. See {@see ByteaBinding} for the full rationale.
 *
 * **Why the hook is here and not a mutator — measured, not assumed.** The
 * tempting fix is a `setXAttribute()` mutator (or a custom cast `set()`) that
 * stores a stream in the model's attribute bag. It was built and run against
 * the pin below, and it FAILS in the worst possible way:
 *
 *   - the first INSERT succeeds and is byte-correct, and
 *   - reading the attribute back also looks fine (the accessor rewinds a
 *     seekable stream), so nothing looks broken, BUT
 *   - `PDOStatement::execute()` leaves the stream at EOF, and re-saving those
 *     same in-memory attributes (`replicate()->save()`, or any second write)
 *     re-binds the CONSUMED resource and stores an EMPTY column — silently.
 *
 * An empty `canonical_bytes` breaks `sha256(bytes) == current_hash` for the
 * whole chain, so the mutator trades a loud `22P02` for silent ledger
 * corruption. The conversion must therefore happen AFTER the attributes leave
 * the model — which is exactly what `getAttributesForInsert()` is: the last
 * hook `Model::performInsert()` calls before handing the row to the query
 * builder. The attribute bag keeps the plain string at all times.
 * `FiscalEventsTableTest::test_canonical_bytes_survive_create_read_and_resave_in_one_request`
 * is the pin for that contract; it is what rejected the mutator.
 *
 * **INSERT only, deliberately.** Every binary column in this schema lives on an
 * append-only table (the `fiscal_events` / `pos_receipts` immutability triggers
 * forbid mutating them), so there is no UPDATE path to intercept. Hooking
 * `getDirty()` as well would corrupt `isDirty()` / `wasChanged()` / model-event
 * payloads for no gain.
 *
 * The streams created here are referenced only by the array handed to the query
 * builder; the engine closes them when that array goes out of scope at the end
 * of `performInsert()`.
 */
trait BindsBinaryColumns
{
    /**
     * Columns on this model that are BINARY in the database.
     *
     * @return list<string>
     */
    abstract protected function binaryColumns(): array;

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert(): array
    {
        /** @var array<string, mixed> $attributes */
        $attributes = parent::getAttributesForInsert();

        [$attributes] = ByteaBinding::prepareRow($attributes, $this->binaryColumns());

        return $attributes;
    }
}
