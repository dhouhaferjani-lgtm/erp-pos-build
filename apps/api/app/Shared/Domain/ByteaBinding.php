<?php

declare(strict_types=1);

namespace App\Shared\Domain;

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
 * TEXT literal — so PostgreSQL parses it with the bytea *escape* input rules,
 * and any backslash that is not `\\` or `\NNN` fails the statement with
 * `SQLSTATE[22P02] invalid input syntax for type bytea`.
 *
 * RFC 8785 canonical JSON emits `\"` for every double quote in free text, so a
 * fiscal event whose payload carries a quote or a backslash (a product name, a
 * partner name, a cashier note) is un-writable on PostgreSQL unless the value
 * is bound as a stream. `PARAM_LOB` is transmitted as binary and round-trips
 * byte-identically on BOTH drivers.
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
     * The caller owns the returned stream. Close it with `closeAll()` once the
     * statement has executed; a stream that is simply dropped is closed by the
     * PHP engine when its last reference goes away.
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
     */
    public static function read(mixed $value): string
    {
        if (is_resource($value)) {
            $meta = stream_get_meta_data($value);

            if ($meta['seekable'] === true) {
                rewind($value);
            }

            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Replace the named columns' string values with `PARAM_LOB` streams,
     * returning the rewritten row alongside the streams the caller must close.
     *
     * Use for raw `INSERT` statements built by hand. Eloquent writes are
     * covered by the `BindsBinaryColumns` model concern instead.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array{0: array<string, mixed>, 1: list<resource>}
     */
    public static function prepareRow(array $row, array $columns): array
    {
        $streams = [];

        foreach ($columns as $column) {
            $value = $row[$column] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $stream = self::stream($value);
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
