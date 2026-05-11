<?php

declare(strict_types=1);

namespace Tests\Architecture\WebhookFixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Negative-control fixture: bypass-shape #2 — `::updateOrCreate(...)`,
 * `::firstOrCreate(...)`, `::insert(...)`, `::upsert(...)`.
 *
 * @cross-tenant-by-design STUB: negative-control fixture exhibiting
 * the Eloquent updateOrCreate / firstOrCreate / insert / upsert
 * bypass family. Inspector must reject.
 */
final class StubWithUpdateOrCreateFixture
{
    public function __invoke(Request $request): JsonResponse
    {
        // Each line below should independently trigger a violation.
        BarModel::updateOrCreate(['id' => 1], ['payload' => $request->all()]);
        BarModel::firstOrCreate(['id' => 2], ['payload' => $request->all()]);
        BarModel::insert(['payload' => $request->all()]);
        BarModel::upsert([['payload' => $request->all()]], ['id'], ['payload']);

        return response()->json(['status' => 'ok']);
    }
}

/**
 * Phantom companion class — never instantiated, never extends Eloquent.
 * Exists only to satisfy PHPStan's class-existence resolver.
 */
final class BarModel
{
    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $values
     */
    public static function updateOrCreate(array $attrs, array $values): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $values
     */
    public static function firstOrCreate(array $attrs, array $values): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function insert(array $row): bool
    {
        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $uniqueBy
     * @param  array<int, string>  $update
     */
    public static function upsert(array $rows, array $uniqueBy, array $update): int
    {
        return 0;
    }
}
