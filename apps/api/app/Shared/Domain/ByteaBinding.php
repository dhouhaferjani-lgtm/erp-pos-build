<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use InvalidArgumentException;
use RuntimeException;

/**
 * Binding + reading rules for BINARY database columns (`bytea` on PostgreSQL,
 * `blob` on SQLite).
 *
 * **Why this exists.** `Illuminate\Database\Connection::bindValues()` picks the
 * PDO parameter type from the PHP type:
 *
 *     is_int($value)      => PDO::PARAM_INT
 *     is_resource($value) => PDO::PARAM_LOB
 *     default             => PDO::PARAM_STR
 *
 * A plain PHP string therefore binds as `PARAM_STR` and is transmitted as a
 * TEXT literal — so PostgreSQL parses it with the bytea *escape* input rules.
 * That has TWO failure modes, and the quiet one is the dangerous one:
 *
 *   - `\"` (and any other backslash that is not `\\` or `\NNN`) is an INVALID
 *     escape: the statement dies with
 *     `SQLSTATE[22P02] invalid input syntax for type bytea`. Loud.
 *   - `\\` is a VALID escape meaning "one literal backslash": PostgreSQL
 *     silently COLLAPSES the pair and stores one byte where two were sent. The
 *     write succeeds, the row looks fine, and `sha256(stored) != current_hash`
 *     — a corrupted fiscal chain with no error anywhere. A 22-byte probe
 *     round-trips as 20 bytes.
 *
 * RFC 8785 canonical JSON emits `\"` for every double quote and `\\` for every
 * backslash in free text, so a fiscal event whose payload carries a quote, a
 * backslash, or a `\uXXXX` escape (a product name, a partner name, a cashier
 * note) is either un-writable or silently corrupted on PostgreSQL unless the
 * value is bound as a stream. `PARAM_LOB` is transmitted as binary and
 * round-trips byte-identically on BOTH drivers.
 *
 * **Reading.** The inverse asymmetry applies on the way out: PDO hands back a
 * PostgreSQL `bytea` as a stream resource when the row is read through the
 * query builder, and as a plain string on SQLite. `read()` normalizes both.
 *
 * Pure PHP by construction (SharedDomain layer) — no framework dependency.
 */
final class ByteaBinding
{
    /**
     * Wrap raw bytes in an in-memory stream so PDO binds them as
     * `PDO::PARAM_LOB` rather than `PDO::PARAM_STR`.
     *
     * **The caller owns the returned stream, and a stream is single-use.**
     * `PDOStatement::execute()` reads it to EOF; binding the SAME resource a
     * second time writes an EMPTY value. Two consequences:
     *
     *   - Close it with `closeAll()` once the statement has executed. A stream
     *     that is simply dropped is closed by the engine when its last
     *     reference goes away.
     *   - Never let a statement holding one be RE-EXECUTED. Laravel's
     *     `Connection::tryAgainIfCausedByLostConnection()` does exactly that on
     *     a dropped connection — but only when `$this->transactions === 0`
     *     (`Connection.php:974` re-throws inside a transaction). Any raw
     *     binary-column write that is not already inside a transaction must
     *     open one, or a reconnect will silently persist an empty column.
     *
     * @return resource
     */
    public static function stream(string $bytes)
    {
        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('ByteaBinding: unable to open an in-memory stream for a binary column binding.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    /**
     * Normalize a binary column value that was just read from the database.
     *
     * A `bytea` read through the query builder arrives as a stream resource on
     * PostgreSQL and as a string on SQLite. Rewinds seekable streams so a value
     * that has already been read once still yields its full contents.
     *
     * Fails loud on anything else: a silent `''` for an unexpected type is how
     * a corrupted binary column reads as "empty but fine" instead of raising.
     * A caller reading a NULLABLE column must handle the null itself.
     */
    public static function read(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_resource($value)) {
            throw new InvalidArgumentException(sprintf(
                'ByteaBinding: expected a string or a stream resource for a binary column, got %s.',
                get_debug_type($value),
            ));
        }

        $meta = stream_get_meta_data($value);

        if ($meta['seekable'] === true) {
            rewind($value);
        }

        $contents = stream_get_contents($value);

        if ($contents === false) {
            throw new RuntimeException('ByteaBinding: failed to read a binary column stream.');
        }

        return $contents;
    }

    /**
     * Replace the named columns' values with `PARAM_LOB` streams, returning the
     * rewritten row alongside the streams the caller must close.
     *
     * Never adds or removes keys — only the value of an already-present column
     * changes — so a caller may derive its column list from the row either
     * before or after this call.
     *
     * A value that is ALREADY a resource is re-streamed from its contents
     * rather than passed through: an Eloquent model that has been `refresh()`ed
     * on PostgreSQL holds a bytea stream in its attribute bag, and rebinding
     * that (possibly already-consumed) resource would write an empty column.
     *
     * Use for raw `INSERT` / `UPDATE` statements built by hand. Eloquent writes
     * are covered by the `BindsBinaryColumns` model concern instead.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array{0: array<string, mixed>, 1: list<resource>}
     */
    public static function prepareRow(array $row, array $columns): array
    {
        $streams = [];

        foreach ($columns as $column) {
            if (! array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if (! is_string($value) && ! is_resource($value)) {
                continue;
            }

            $stream = self::stream(self::read($value));
            $streams[] = $stream;
            $row[$column] = $stream;
        }

        return [$row, $streams];
    }

    /**
     * @param  list<resource>  $streams
     */
    public static function closeAll(array $streams): void
    {
        foreach ($streams as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
