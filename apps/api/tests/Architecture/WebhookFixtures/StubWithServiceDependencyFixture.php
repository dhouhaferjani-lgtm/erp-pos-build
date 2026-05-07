<?php

declare(strict_types=1);

namespace Tests\Architecture\WebhookFixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Negative-control fixture: bypass-shape #4 — service injected via
 * constructor performs the write inside `__invoke()`.
 *
 * `$this->svc->createFor(...)` is invisible to the regex set: there's
 * no `::create(`, no `->save(`, just a method call on a constructor-
 * injected dependency. The strengthened inspector MUST inspect
 * constructor parameter types and reject any non-allowlisted type
 * (Request and PSR LoggerInterface are the allowed dependency types
 * for a stub).
 *
 * @cross-tenant-by-design STUB: negative-control fixture exhibiting
 * the constructor-service-dependency bypass. The injected
 * NotARealService is not in the allowlist; inspector must flag the
 * constructor parameter type.
 */
final class StubWithServiceDependencyFixture
{
    public function __construct(
        private readonly NotARealService $svc,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->svc->createFor($request->all());

        return response()->json(['status' => 'ok']);
    }
}

/**
 * Companion phantom service class — exists only to give the constructor
 * fixture a non-allowlisted parameter type to inject. Never instantiated.
 */
final class NotARealService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createFor(array $payload): void {}
}
