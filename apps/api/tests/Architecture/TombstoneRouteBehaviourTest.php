<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use ReflectionFunction;
use Symfony\Component\HttpFoundation\Response;
use Tests\Architecture\Support\RouteCoverageClassifier;
use Tests\Architecture\Support\SelfServiceRouteRegistry;
use Tests\Architecture\Support\TombstoneRouteRegistry;
use Tests\TestCase;

/**
 * Gate r1 B-1. The ratchet classifies four routes as TOMBSTONE, which excludes
 * them from the uncovered count. That exclusion is only honest while each of
 * them really is inert, so this test proves the behaviour rather than trusting
 * the name:
 *
 *   (a) the route's action is a CLOSURE, not a controller — nothing can be
 *       injected into it and nothing can grow inside it unnoticed;
 *   (b) THE SOURCE SHAPE IS PINNED. `ReflectionFunction` gives the closure's
 *       file and start/end lines; the test reads exactly that range, tokenises
 *       it, drops comments and whitespace, and asserts the result equals the
 *       `source` string in the fixture — AND that the token stream contains no
 *       control-flow token, exactly one `return`, and no call other than
 *       `response()->json(...)`. This is the half gate r2 B-3 required: rev 2
 *       invoked each closure ONCE with ONE parameter set and called that "410
 *       for ANY parameters, with no branch", which a closure conditional on a
 *       different identifier would have passed unchanged. A planted branch or
 *       mutation changes the source and fails the pin before it is ever run.
 *   (c) invoking it once returns HTTP 410 with the declared error code, and
 *       executes ZERO queries on EVERY configured database connection — not
 *       just the default one. Rev 2 read only `DB::getQueryLog()`, so a write
 *       against `pgsql`, `central` or any named connection was invisible.
 *
 * The route is invoked DIRECTLY rather than over HTTP on purpose: the four live
 * behind `auth:sanctum`, so an HTTP probe would need a user, a tenant database
 * and a token — and would then be a Feature test inside the feature-lane
 * manifest's scope. Invoking the action is the same code path minus the
 * middleware stack, needs no database, and is exactly what the 410 contract is.
 * The invocation is EVIDENCE; the SOURCE PIN is the proof.
 *
 * Coupling to the classifier (the half that makes this a guard rather than a
 * note): TombstoneRouteRegistry and RouteCoverageClassifier read the SAME
 * fixture. A route that stops returning 410 fails (b) here; its remediation is
 * to delete the fixture entry, whereupon the classifier stops exempting it and
 * RoutePermissionCoverageRatchetTest's growth direction fails because the key is
 * not in the baseline. That is "re-enters the uncovered count", mechanically.
 */
final class TombstoneRouteBehaviourTest extends TestCase
{
    private const REGENERATE_ENV = 'TOMBSTONE_SOURCE_REGENERATE';

    /**
     * Tokens a provably inert body cannot contain. Checked by PHP TOKEN ID, not
     * by substring: the retirement messages are long single-quoted strings and
     * contain ordinary English words — `... is retired for new-sale ...` — so a
     * substring search for `for` would fire on the message text and a substring
     * search is therefore not a usable rule here.
     *
     * @var list<int>
     */
    private const FORBIDDEN_TOKEN_IDS = [
        T_IF, T_ELSE, T_ELSEIF, T_SWITCH, T_CASE, T_DEFAULT, T_MATCH,
        T_WHILE, T_DO, T_FOR, T_FOREACH, T_CONTINUE, T_BREAK,
        T_TRY, T_CATCH, T_FINALLY, T_THROW, T_GOTO,
        T_ECHO, T_PRINT, T_YIELD, T_YIELD_FROM, T_EVAL, T_EXIT,
        T_NEW, T_CLONE, T_INSTANCEOF, T_GLOBAL, T_STATIC, T_UNSET, T_ISSET, T_EMPTY,
        T_COALESCE, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE,
    ];

    #[Test]
    public function the_classifier_exempts_exactly_the_fixture_entries(): void
    {
        self::assertSame(
            TombstoneRouteRegistry::keys(),
            (new RouteCoverageClassifier)->tombstoneKeys(),
            'The classifier\'s tombstone set drifted from tests/Architecture/fixtures/route-tombstones.json. '
            .'The fixture is the ONLY source: a key the behaviour test does not prove inert must not be exempt.',
        );
    }

    /**
     * THE PIN (gate r2 B-3). The exemption rests on this, not on the invocation
     * below: a closure whose normalised source is a bare
     * `return response()->json([...], 410);` cannot branch, cannot mutate and
     * cannot call anything else, FOR ANY PARAMETERS — which is the claim the
     * classification actually makes.
     */
    #[Test]
    public function every_tombstone_closure_has_the_pinned_inert_source_shape(): void
    {
        $regenerated = [];

        foreach (TombstoneRouteRegistry::entries() as $entry) {
            $action = $this->closureAction($entry);
            $observed = self::normalisedSource($action);
            $regenerated[$entry['key']] = $observed;

            if (getenv(self::REGENERATE_ENV) === '1') {
                continue;
            }

            self::assertSame(
                $entry['source'],
                $observed,
                $entry['key'].' ('.$entry['declared_at'].') no longer has the pinned inert source shape. '
                ."Observed:\n".$observed."\n\nA tombstone is exempt from the uncovered-route ceilings ONLY "
                .'because its body is provably a single unconditional 410. If this change is deliberate, it is '
                .'not a tombstone any more: gate the route with `can:<permission>` and delete its entry from '
                .TombstoneRouteRegistry::FIXTURE_RELATIVE.', which puts the key back in the uncovered count.',
            );

            // Independent of the pasted string, so a regenerated `source` can
            // never launder a planted branch: the TOKEN STREAM itself must be
            // inert. Every check below is on token IDs or on the tail of the
            // normalised string — never a substring search that a message
            // literal could satisfy.
            $tokens = self::closureTokens($action);

            $ids = array_values(array_filter(array_column($tokens, 0), static fn (?int $id): bool => $id !== null));
            $forbidden = array_values(array_intersect($ids, self::FORBIDDEN_TOKEN_IDS));
            self::assertSame(
                [],
                $forbidden,
                $entry['key'].' contains forbidden token(s) '
                .implode(', ', array_map(static fn (int $id): string => (string) token_name($id), $forbidden))
                .'. A tombstone body has no control flow, no throw and no side effect; anything that needs one '
                .'is not inert, and the route must be gated instead of exempted.',
            );

            self::assertSame(
                1,
                count(array_keys($ids, T_RETURN, true)),
                $entry['key'].' does not have exactly one `return`.',
            );

            // Identifiers in the BODY only. TWO of the four closures declare
            // `function (string $id)` — payments (POS/routes.php:231) and close
            // (POS/routes_orders.php:60); receipts (:187) and void (:222) are
            // `function ()` (gate r3 m-3, re-read at `dev` 630afa86f). For the
            // two typed ones `string` is a T_STRING token in the SIGNATURE, so a
            // whole-span check would read as a third call — which is why the
            // slice starts after the body's opening brace for all four.
            $body = array_slice($tokens, (int) array_search('{', array_column($tokens, 1), true) + 1);
            $names = array_values(array_map(
                static fn (array $token): string => $token[1],
                array_filter($body, static fn (array $token): bool => $token[0] === T_STRING),
            ));
            self::assertSame(
                ['response', 'json'],
                $names,
                $entry['key'].' calls something other than response()->json(...) in its body: '
                .implode(', ', $names).'. Observed source: '.$observed,
            );

            // `?` as a raw token covers both the ternary and `?->`; T_COALESCE
            // is already in FORBIDDEN_TOKEN_IDS.
            self::assertNotContains('?', array_column($tokens, 1), $entry['key'].' contains a ternary or nullsafe operator.');

            self::assertStringEndsWith(
                ', 410 ) ; }',
                $observed,
                $entry['key'].' does not end in an unconditional `, 410);` return. Observed: '.$observed,
            );
        }

        if (getenv(self::REGENERATE_ENV) === '1') {
            self::fail(
                'Regenerated tombstone source pins — paste each into the matching `source` field of '
                .TombstoneRouteRegistry::FIXTURE_RELATIVE.', review the diff, then re-run WITHOUT '
                .self::REGENERATE_ENV.":\n\n".json_encode($regenerated, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * The evidence half: the pinned body, actually run once, answering 410 with
     * zero queries on EVERY configured connection — not just the default one
     * (gate r2 B-3: rev 2 read only `DB::getQueryLog()`, so a write against
     * `pgsql`, `central` or any named connection was invisible).
     */
    #[Test]
    public function every_tombstone_returns_410_and_queries_no_configured_connection(): void
    {
        /** @var array<string, mixed> $configured */
        $configured = (array) config('database.connections');
        $names = array_keys($configured);
        self::assertNotSame([], $names, 'config(\'database.connections\') is empty; the query assertion below would be vacuous.');

        foreach (TombstoneRouteRegistry::entries() as $entry) {
            $action = $this->closureAction($entry);

            foreach ($names as $name) {
                DB::connection($name)->flushQueryLog();
                DB::connection($name)->enableQueryLog();
            }

            $response = $action(...array_values($entry['parameters']));

            $queries = [];
            foreach ($names as $name) {
                $log = DB::connection($name)->getQueryLog();
                DB::connection($name)->disableQueryLog();

                if ($log !== []) {
                    $queries[$name] = count($log);
                }
            }

            self::assertInstanceOf(Response::class, $response, $entry['key'].' did not return a response.');
            self::assertSame(
                410,
                $response->getStatusCode(),
                $entry['key'].' returned '.$response->getStatusCode().' instead of an unconditional 410 '
                .'('.$entry['declared_at'].'). It is counted as a TOMBSTONE and therefore excluded from the '
                .'uncovered-route ceilings; a route that answers anything else is a live surface with no '
                .'action gate. Either restore the 410, or gate the route and delete its fixture entry so it '
                .'re-enters the uncovered count.',
            );
            self::assertStringContainsString(
                $entry['error_code'],
                (string) $response->getContent(),
                $entry['key'].' no longer carries its structured retirement code '.$entry['error_code']
                .'. A legacy terminal must be able to tell "retired" from "routing glitch".',
            );
            self::assertSame(
                [],
                $queries,
                $entry['key'].' executed queries on '.implode(', ', array_keys($queries)).'. A tombstone '
                .'mutates nothing on ANY connection; if it now reads or writes, it is not inert and must not '
                .'be exempt from the ratchet.',
            );
        }
    }

    /**
     * @param  array{key: string, declared_at: string, error_code: string, parameters: array<string, string>, source: string}  $entry
     */
    private function closureAction(array $entry): Closure
    {
        $action = $this->findRoute($entry['key'])->getAction('uses');

        self::assertInstanceOf(
            Closure::class,
            $action,
            $entry['key'].' is no longer a closure ('.$entry['declared_at'].'). A tombstone that dispatches '
            .'to a controller is not provably inert — gate it with `can:<permission>` and delete its entry '
            .'from '.TombstoneRouteRegistry::FIXTURE_RELATIVE.'.',
        );

        return $action;
    }

    /**
     * The space-joined normalised source: every token of {@see closureTokens()}
     * separated by one space. Comment-insensitive on purpose — a reworded
     * comment must not fail the pin — and CODE-sensitive by construction: any
     * added branch, call, literal or operator changes the string.
     */
    private static function normalisedSource(Closure $closure): string
    {
        return implode(' ', array_column(self::closureTokens($closure), 1));
    }

    /**
     * The closure's OWN token span: `ReflectionFunction` gives the file and the
     * start/end line, the test reads exactly that range, tokenises it, skips
     * everything before the first `function` / `fn`, drops comments and
     * whitespace, and stops at the brace that closes the body. The route
     * registration around it (`Route::post('...', ` … `);`) is therefore not
     * part of the pin, and neither is anything after the closure.
     *
     * @return list<array{0: int|null, 1: string}>
     */
    private static function closureTokens(Closure $closure): array
    {
        $reflection = new ReflectionFunction($closure);
        $file = $reflection->getFileName();
        self::assertIsString($file, 'Tombstone closure has no source file.');

        $lines = file($file);
        self::assertIsArray($lines, 'Could not read '.$file);

        $range = array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        );

        $pieces = [];
        $started = false;
        $depth = 0;

        foreach (token_get_all('<?php '.implode('', $range)) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;

            if (! $started) {
                if ($id !== T_FUNCTION && $id !== T_FN) {
                    continue;
                }
                $started = true;
            }

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            $pieces[] = [$id, $text];

            if ($text === '{') {
                $depth++;
            }

            if ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }
        }

        self::assertTrue($started, 'No closure found in the reflected source range.');
        self::assertSame(0, $depth, 'The closure body\'s braces did not balance inside its own line range.');

        return $pieces;
    }

    #[Test]
    public function the_fixture_holds_exactly_four_entries_with_no_duplicates(): void
    {
        $keys = TombstoneRouteRegistry::keys();

        self::assertCount(4, $keys);
        self::assertSame($keys, array_values(array_unique($keys)));
    }

    private function findRoute(string $key): Route
    {
        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (SelfServiceRouteRegistry::routeKey($route) === $key) {
                return $route;
            }
        }

        self::fail('Tombstone route not registered: '.$key.'. If the route was DELETED rather than retired, '
            .'delete its fixture entry too — but read the tombstone rationale at '
            .'apps/api/app/Modules/POS/routes.php:204-218 first: three contracts depend on it still resolving.');
    }
}
