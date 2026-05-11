<?php

declare(strict_types=1);

namespace Tests\Architecture\WebhookFixtures;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Negative-control fixture: bypass-shape #3 — same-class private
 * helper performs the write while `__invoke()` looks clean.
 *
 * The original test only inspected `__invoke()` and `handle()`, so
 * a developer could refactor the write into `private function persist()`
 * and slip past every regex. The strengthened inspector MUST scan ALL
 * methods declared on the class, including private helpers.
 *
 * @cross-tenant-by-design STUB: negative-control fixture exhibiting
 * the same-class private-helper bypass. __invoke() looks clean, but
 * persist() does the write. Inspector must walk all methods on the
 * class and flag the private helper.
 */
final class StubWithHelperMethodWriteFixture
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->persist($request->all());

        return response()->json(['status' => 'ok']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persist(array $payload): void
    {
        BazModel::create(['payload' => $payload]);
    }
}

/**
 * Phantom companion class — never instantiated, never extends Eloquent.
 * Exists only to satisfy PHPStan's class-existence resolver.
 */
final class BazModel
{
    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function create(array $attrs): self
    {
        return new self;
    }
}
