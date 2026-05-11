<?php

declare(strict_types=1);

namespace Tests\Architecture\WebhookFixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Positive-control fixture for WebhookControllerTenantContextTest.
 *
 * Mirrors the shape of a clean STUB-classified webhook controller: zero
 * DB access, zero dispatch, zero side-effects. The stub-inspector must
 * return ZERO violations on this class. Used as a regression anchor —
 * if the inspector starts flagging clean stubs, this fixture catches
 * the false-positive at PR time.
 *
 * @cross-tenant-by-design STUB: positive-control fixture for the
 * stub-shape inspector. Zero side-effects, no DB, no dispatch, no
 * notifications. Inspector must return zero violations on this class.
 */
final class StubCleanFixture
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->input('event');
        Log::info('test fixture received', ['event' => $event]);

        return response()->json(['status' => 'received']);
    }
}
