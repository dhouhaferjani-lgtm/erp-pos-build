<?php

declare(strict_types=1);

namespace Tests\Architecture\WebhookFixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Negative-control fixture: bypass-shape #1 — `Model::query()->insert(...)`.
 *
 * The original STUB_FORBIDDEN_PATTERNS regex set caught `::create(`,
 * `::find(`, etc. but did NOT catch `Model::query()` followed by
 * `->insert(...)`. This fixture exhibits that exact bypass shape.
 * The stub inspector MUST flag this fixture with at least one
 * violation.
 *
 * @cross-tenant-by-design STUB: negative-control fixture exhibiting
 * Model::query()->insert(...) bypass. The inspector must reject it.
 */
final class StubWithModelQueryInsertFixture
{
    public function __invoke(Request $request): JsonResponse
    {
        // The regex must catch this static-call chain regardless of
        // whether FooModel is a real Eloquent model — the inspector
        // operates on source-text patterns, not runtime resolution.
        FooModel::query()->insert(['payload' => $request->all()]);

        return response()->json(['status' => 'ok']);
    }
}

/**
 * Phantom companion class — never instantiated, never extends Eloquent.
 * Exists only to satisfy PHPStan's class-existence resolver while the
 * fixture exhibits the static-call shape the inspector must catch.
 */
final class FooModel
{
    public static function query(): self
    {
        return new self;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function insert(array $row): bool
    {
        return false;
    }
}
