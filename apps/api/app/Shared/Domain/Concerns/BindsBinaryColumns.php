<?php

declare(strict_types=1);

namespace App\Shared\Domain\Concerns;

use App\Shared\Domain\ByteaBinding;

/**
 * Binds a model's BINARY columns (`bytea` on PostgreSQL, `blob` on SQLite) as
 * `PDO::PARAM_LOB` on write, so canonical bytes carrying RFC 8785 `\"` / `\\`
 * escapes survive. See {@see ByteaBinding} for the full rationale — including
 * the silent byte-DROP a `PARAM_STR` bind can cause, which is worse than the
 * loud `22P02`.
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
 * the model — which is what `getAttributesForInsert()` / `getDirtyForUpdate()`
 * are: the last hooks `performInsert()` / `performUpdate()` call before handing
 * the row to the query builder. The attribute bag keeps the plain string at all
 * times.
 * `FiscalEventsTableTest::test_canonical_bytes_survive_create_read_and_resave_in_one_request`
 * is the pin for that contract; it is what rejected the mutator.
 *
 * **BOTH write paths are covered — INSERT and UPDATE.** An earlier revision of
 * this trait hooked only the INSERT and claimed binary columns were
 * insert-only "because every one sits on an append-only table". That claim was
 * FALSE. `pos_z_reports` has a one-time legacy-row upgrade
 * (`ZReportProjection::apply()` → `$existing->fill($row); $existing->save()`)
 * that its own immutability trigger explicitly sanctions for the
 * `fiscal_event_id IS NULL -> value` transition, and the legacy row is always
 * created without canonical bytes — so the binary column is ALWAYS dirty on
 * that upgrade. Hooking only inserts left every cutover terminal broken.
 *
 * `getDirtyForUpdate()` is overridden rather than `getDirty()` deliberately:
 * `performUpdate()` calls `syncChanges()` right after the write, which reads
 * `getDirty()` again. Hooking `getDirty()` itself would put consumed stream
 * resources into `$model->getChanges()` and corrupt
 * `isDirty()` / `wasChanged()` / model-event payloads.
 *
 * The streams created here are referenced only by the array handed to the query
 * builder; the engine closes them when that array goes out of scope at the end
 * of `performInsert()` / `performUpdate()`.
 *
 * Living in `Shared/Domain` while touching an Eloquent concern follows the
 * existing `Shared\Domain\Concerns\HasTranslations` precedent; deptrac allows
 * both `ModuleDomain` and `ModuleApplication` to depend on `SharedDomain`,
 * which is what lets models and services share one implementation.
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

    /**
     * @return array<string, mixed>
     */
    protected function getDirtyForUpdate(): array
    {
        /** @var array<string, mixed> $dirty */
        $dirty = parent::getDirtyForUpdate();

        [$dirty] = ByteaBinding::prepareRow($dirty, $this->binaryColumns());

        return $dirty;
    }
}
