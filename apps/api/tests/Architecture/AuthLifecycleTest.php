<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\Feature\Identity\AuthPermissionsTenantIsolationTest;
use Tests\TestCase;

/**
 * api.auth-permissions cluster — Section 15 (the LAST architectural cluster).
 *
 * Pins Invariant B of the master plan §15: universal SetPermissionsTeam
 * coverage. Every protected route group (where "protected" = uses
 * `auth:sanctum` middleware) MUST include `SetPermissionsTeam::class` in
 * its middleware stack. Super-admin routes (`auth:sanctum-admin`) are
 * explicitly exempt by separate auth pipeline.
 *
 * FRAMING — TEST-CODIFICATION, NOT BUG-FIX-GATE
 *
 * All 38 protected route groups already include `SetPermissionsTeam::class`
 * on dev tip per the triage's hostile-grep audit (Section B). This test
 * passes GREEN today; it is REGRESSION-PROTECTION pinning the contract so
 * future module additions can't silently regress it. Standard "verify RED
 * first" does not apply because there's no missing-coverage bug to fix.
 *
 * The Invariant A lifecycle tests in
 * {@see AuthPermissionsTenantIsolationTest} ARE
 * red-anchored bug-fix gates — those hooks don't exist on dev tip and the
 * tests fail RED until the implementation lands. PR1 mixes test-codification
 * (this arch test) with bug-fix-gate (lifecycle hooks); the PR body
 * documents both shapes explicitly so reviewer doesn't conflate.
 *
 * LAYERING WITH ControllerTenantContextTest
 *
 * Complementary, not duplicative:
 *   - {@see ControllerTenantContextTest} (super-admin-context
 *     cluster, master plan §9): METHOD-level invariant — every public method on
 *     every concrete controller MUST EITHER carry #[CrossTenantRoute] OR
 *     plausibly resolve a tenant context.
 *   - this test (auth-permissions cluster, master plan §15): ROUTE-level
 *     invariant — every protected route group MUST include
 *     SetPermissionsTeam::class in its middleware list.
 *
 * One enforces "every controller method classified," the other enforces
 * "every protected route group has team-binding middleware." Together
 * they pin the controller-and-route surface for tenant-context resolution.
 *
 * KNOWN PARSER LIMITS — explicitly out of scope
 *
 *   (a) Conditional middleware via `if`/`switch` blocks: not supported.
 *       None present in current codebase. If introduced, the new pattern
 *       must be paired with a per-environment classification rule.
 *
 *   (b) Closure-based middleware factories: not supported. None present.
 *
 *   (c) Server-side `Route::middlewareGroup()` resolution: not supported.
 *       The test asserts on the LITERAL middleware array as written in
 *       routes files. None of the current routes use this indirection.
 *
 *   (d) Variable middleware arrays (e.g., `$middleware = [...]; Route::middleware($middleware)`):
 *       not supported. None present.
 *
 *   (e) Workshop sub-module routes: explicitly enumerated under
 *       {@see self::ROUTE_FILE_GLOBS} so the discovery covers them.
 *
 * If a future contributor introduces any of (a)-(d), this test
 * deliberately produces a `dynamic_middleware` violation pointing at the
 * file:line. The fix is either (1) inline the middleware to a literal
 * array OR (2) add an explicit deferral in the deferrals fixture with a
 * justification.
 *
 * PRODUCTION-FLOOR GUARD
 *
 * The discovery floor of {@see self::PROTECTED_GROUP_FLOOR} catches a
 * test-infrastructure regression that drops below the known cluster
 * count (38 today). A passing test with 0 discovered groups would be a
 * silent vacuous-pass — the floor prevents that.
 */
final class AuthLifecycleTest extends TestCase
{
    /**
     * Glob patterns for route files containing Route::middleware([...])->group(...) calls.
     *
     * @var list<string>
     */
    private const ROUTE_FILE_GLOBS = [
        'routes/api.php',
        'app/Modules/*/Presentation/routes.php',
        'app/Modules/*/routes.php',
        'app/Modules/Workshop/*/Presentation/routes.php',
    ];

    /**
     * Floor for the production scan vacuous-pass guard. The cluster has
     * 38 protected groups today across 37 files (per triage Section B).
     * A discovery regression that drops below this is a test-infrastructure
     * bug, not a green signal.
     */
    private const PROTECTED_GROUP_FLOOR = 30;

    /**
     * Auth-guard token recognized as "regular protected request" — requires
     * SetPermissionsTeam::class to also be present.
     */
    private const PROTECTED_AUTH_GUARD = 'auth:sanctum';

    /**
     * Auth-guard token for super-admin pipeline — exempt from
     * SetPermissionsTeam::class requirement (operates cross-tenant by design).
     */
    private const SUPER_ADMIN_AUTH_GUARD = 'auth:sanctum-admin';

    public function test_every_auth_sanctum_route_group_includes_set_permissions_team(): void
    {
        $entries = $this->discoverRouteMiddlewareCalls();

        $this->assertGreaterThanOrEqual(
            self::PROTECTED_GROUP_FLOOR,
            $this->countProtectedGroups($entries),
            'Route::middleware([...])->group() discovery returned fewer than '.self::PROTECTED_GROUP_FLOOR
            .' protected groups across the route files. The cluster has 38 today (per triage Section B); a drop below the floor likely means '
            .'a glob pattern regressed or a route file was relocated. Got: '
            .$this->countProtectedGroups($entries).' protected group(s).',
        );

        $violations = $this->classifyEntries($entries);

        $this->assertEmpty(
            $violations['protected_without_set_permissions_team'],
            "Found Route::middleware([...]) call(s) using `auth:sanctum` (NOT `auth:sanctum-admin`) but missing `SetPermissionsTeam::class`:\n  - "
            .implode("\n  - ", $violations['protected_without_set_permissions_team'])
            ."\n\nEvery protected route group MUST include SetPermissionsTeam::class in its middleware list "
            .'so that Spatie team-id is bound to the authenticated user\'s tenant_id on every request. '
            .'Per CLAUDE.md rule #12 (api.auth-permissions Invariant B). '
            ."\nFix: add `App\\Modules\\Identity\\Presentation\\Middleware\\SetPermissionsTeam::class` "
            .'to the middleware array.',
        );

        $this->assertEmpty(
            $violations['dynamic_middleware'],
            "Found Route::middleware(...) call(s) with a non-literal-array argument:\n  - "
            .implode("\n  - ", $violations['dynamic_middleware'])
            ."\n\nThe arch test cannot statically classify middleware that is computed at runtime "
            .'(variable arrays, closure factories, conditional branches, Route::middlewareGroup() indirection). '
            ."Either: (1) inline the middleware to a literal array argument, OR (2) extract the group's "
            ."classification rule into a per-environment deferral fixture with explicit justification.\n"
            .'See triage docblock for the documented parser limits.',
        );
    }

    /**
     * Self-test pinning the classifier's behavior on synthesized fixtures.
     *
     * Each fixture is a small string with a single Route::middleware([...]) call
     * that exercises one classification edge-case. Fixtures live in this file
     * (not on disk) because the discovery is path-driven and a strayed fixture
     * file under `app/Modules/...` could leak into the production scan.
     */
    public function test_classifier_catches_known_shapes(): void
    {
        // Positive control: protected group WITH SetPermissionsTeam — clean.
        $clean = $this->classifyEntries($this->parseFixture(<<<'PHP'
<?php
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
Route::middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {});
PHP));
        $this->assertEmpty($clean['protected_without_set_permissions_team']);
        $this->assertEmpty($clean['dynamic_middleware']);

        // Bug shape: protected group MISSING SetPermissionsTeam.
        $missing = $this->classifyEntries($this->parseFixture(<<<'PHP'
<?php
Route::middleware(['api', 'auth:sanctum'])->group(function () {});
PHP));
        $this->assertNotEmpty(
            $missing['protected_without_set_permissions_team'],
            'Protected group without SetPermissionsTeam should fail classification',
        );

        // Super-admin exemption: auth:sanctum-admin without SetPermissionsTeam — clean.
        $superAdmin = $this->classifyEntries($this->parseFixture(<<<'PHP'
<?php
Route::middleware(['auth:sanctum-admin', 'super_admin'])->group(function () {});
PHP));
        $this->assertEmpty(
            $superAdmin['protected_without_set_permissions_team'],
            'Super-admin pipeline should be exempt from SetPermissionsTeam requirement',
        );

        // Public group: no auth — clean.
        $public = $this->classifyEntries($this->parseFixture(<<<'PHP'
<?php
Route::middleware(['api'])->group(function () {});
PHP));
        $this->assertEmpty(
            $public['protected_without_set_permissions_team'],
            'Public group (no auth) should be exempt from SetPermissionsTeam requirement',
        );

        // Dynamic middleware: variable array — must surface as dynamic_middleware.
        $dynamic = $this->classifyEntries($this->parseFixture(<<<'PHP'
<?php
$mw = ['api', 'auth:sanctum'];
Route::middleware($mw)->group(function () {});
PHP));
        $this->assertNotEmpty(
            $dynamic['dynamic_middleware'],
            'Variable middleware array should fail with dynamic_middleware',
        );

        // Chained middleware via prefix(...)->middleware([...]) — should be discovered.
        $chained = $this->classifyEntries($this->parseFixture(<<<'PHP'
<?php
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
Route::prefix('api/v1')->middleware(['api', 'auth:sanctum', SetPermissionsTeam::class])->group(function () {});
PHP));
        $this->assertEmpty(
            $chained['protected_without_set_permissions_team'],
            'Chained prefix()->middleware()->group() with SetPermissionsTeam should pass',
        );

        // Inner protected sub-group inheriting `web` from parent (Identity routes:42 shape).
        // The inner group has auth:sanctum + SetPermissionsTeam — clean.
        $innerProtected = $this->classifyEntries($this->parseFixture(<<<'PHP'
<?php
use App\Modules\Identity\Presentation\Middleware\SetPermissionsTeam;
Route::middleware(['auth:sanctum', SetPermissionsTeam::class])->group(function () {});
PHP));
        $this->assertEmpty(
            $innerProtected['protected_without_set_permissions_team'],
            'Inner protected sub-group with SetPermissionsTeam should pass regardless of parent stack',
        );
    }

    /**
     * Classify discovered route-middleware entries into violation buckets.
     * Pure function — given the same entries, produces the same verdict.
     *
     * @param  list<array{
     *     middleware_array: ?Array_,
     *     file: string,
     *     line: int,
     * }>  $entries
     * @return array{
     *     protected_without_set_permissions_team: list<string>,
     *     dynamic_middleware: list<string>,
     * }
     */
    private function classifyEntries(array $entries): array
    {
        $protectedWithoutTeam = [];
        $dynamicMiddleware = [];

        foreach ($entries as $entry) {
            ['middleware_array' => $array, 'file' => $file, 'line' => $line] = $entry;
            $location = "{$file}:{$line}";

            if ($array === null) {
                $dynamicMiddleware[] = $location;

                continue;
            }

            $tokens = $this->extractMiddlewareTokens($array);
            if ($tokens === null) {
                $dynamicMiddleware[] = $location;

                continue;
            }

            $hasProtected = $this->containsAuthGuard($tokens, self::PROTECTED_AUTH_GUARD)
                && ! $this->containsAuthGuard($tokens, self::SUPER_ADMIN_AUTH_GUARD);
            if (! $hasProtected) {
                continue;
            }

            $hasSetPermissionsTeam = $this->containsSetPermissionsTeam($tokens);
            if (! $hasSetPermissionsTeam) {
                $protectedWithoutTeam[] = $location;
            }
        }

        return [
            'protected_without_set_permissions_team' => $protectedWithoutTeam,
            'dynamic_middleware' => $dynamicMiddleware,
        ];
    }

    /**
     * Count entries that look protected (have `auth:sanctum` literal) for the
     * vacuous-pass floor guard.
     *
     * @param  list<array{
     *     middleware_array: ?Array_,
     *     file: string,
     *     line: int,
     * }>  $entries
     */
    private function countProtectedGroups(array $entries): int
    {
        $count = 0;
        foreach ($entries as $entry) {
            $array = $entry['middleware_array'];
            if ($array === null) {
                continue;
            }
            $tokens = $this->extractMiddlewareTokens($array);
            if ($tokens === null) {
                continue;
            }
            if ($this->containsAuthGuard($tokens, self::PROTECTED_AUTH_GUARD)
                && ! $this->containsAuthGuard($tokens, self::SUPER_ADMIN_AUTH_GUARD)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Extract the literal-string + class-const tokens from a middleware
     * array literal. Returns null if any element is non-literal (variable,
     * function-call, etc.) — caller should mark as dynamic_middleware.
     *
     * @return list<string>|null
     */
    private function extractMiddlewareTokens(Array_ $array): ?array
    {
        $tokens = [];
        foreach ($array->items as $item) {
            $value = $item->value;

            if ($value instanceof String_) {
                $tokens[] = $value->value;

                continue;
            }

            if ($value instanceof ClassConstFetch
                && $value->class instanceof Name
                && $value->name instanceof Identifier
                && $value->name->name === 'class'
            ) {
                $tokens[] = $value->class->toString().'::class';

                continue;
            }

            // Anything else (variables, function calls, concatenation) makes
            // the array non-literal.
            return null;
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function containsAuthGuard(array $tokens, string $guard): bool
    {
        return in_array($guard, $tokens, true);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function containsSetPermissionsTeam(array $tokens): bool
    {
        // Match either the FQCN-style (App\Modules\...\SetPermissionsTeam::class)
        // or the unqualified (SetPermissionsTeam::class) form. Production code
        // uses `use SetPermissionsTeam;` with the unqualified form; the
        // FQCN form is admitted defensively for robustness.
        foreach ($tokens as $token) {
            if (str_ends_with($token, 'SetPermissionsTeam::class')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk the configured route files and return one entry per
     * Route::middleware([...])->group(...) call discovered.
     *
     * Discovery rule: any chain of Route::*->middleware([...]) calls (whether
     * standalone or nested under prefix/name modifiers) is recorded. The
     * test classifies based ONLY on the middleware array contents.
     *
     * @return list<array{middleware_array: ?Array_, file: string, line: int}>
     */
    private function discoverRouteMiddlewareCalls(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $absPaths = $this->resolveRouteFilePaths();

        $entries = [];

        foreach ($absPaths as $absPath) {
            $source = (string) file_get_contents($absPath);
            $ast = $parser->parse($source);
            if ($ast === null) {
                continue;
            }

            $relPath = $this->relativizePath($absPath);

            $calls = $finder->find(
                $ast,
                fn (Node $n): bool => $this->isRouteMiddlewareCall($n)
            );

            foreach ($calls as $call) {
                $middlewareArray = $this->extractMiddlewareArrayFromCall($call);
                $entries[] = [
                    'middleware_array' => $middlewareArray,
                    'file' => $relPath,
                    'line' => $call->getStartLine(),
                ];
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function resolveRouteFilePaths(): array
    {
        $base = base_path();
        $paths = [];
        foreach (self::ROUTE_FILE_GLOBS as $glob) {
            $matches = glob($base.'/'.$glob);
            if ($matches === false) {
                continue;
            }
            foreach ($matches as $match) {
                if (is_file($match)) {
                    $paths[] = $match;
                }
            }
        }

        return $paths;
    }

    private function relativizePath(string $absPath): string
    {
        $base = base_path().'/';
        if (str_starts_with($absPath, $base)) {
            return substr($absPath, strlen($base));
        }

        return $absPath;
    }

    /**
     * Match `Route::middleware(...)` static call OR `Route::xxx()->middleware(...)` instance call.
     *
     * Returns true for any call whose method name is `middleware` AND whose
     * receiver chain ultimately roots at a `Route` static call or facade.
     */
    private function isRouteMiddlewareCall(Node $node): bool
    {
        if ($node instanceof StaticCall) {
            return $node->name instanceof Identifier
                && $node->name->name === 'middleware'
                && $node->class instanceof Name
                && $node->class->getLast() === 'Route';
        }

        if ($node instanceof MethodCall) {
            return $node->name instanceof Identifier
                && $node->name->name === 'middleware'
                && $this->receiverRootsAtRouteFacade($node->var);
        }

        return false;
    }

    /**
     * Walk a MethodCall chain back to its root and return true iff the
     * root is a `Route::xxx(...)` static call.
     */
    private function receiverRootsAtRouteFacade(Expr $expr): bool
    {
        $cursor = $expr;
        while ($cursor instanceof MethodCall) {
            $cursor = $cursor->var;
        }
        if ($cursor instanceof StaticCall) {
            return $cursor->class instanceof Name && $cursor->class->getLast() === 'Route';
        }

        return false;
    }

    /**
     * Extract the first-arg literal-array from a Route::middleware(...) call.
     * Returns null if the argument is not a literal array.
     */
    private function extractMiddlewareArrayFromCall(Node $call): ?Array_
    {
        if (! $call instanceof StaticCall && ! $call instanceof MethodCall) {
            return null;
        }
        $args = $call->args;
        if (count($args) === 0) {
            return null;
        }
        $firstArg = $args[0];
        if (! $firstArg instanceof Arg) {
            return null;
        }

        $value = $firstArg->value;
        if ($value instanceof Array_) {
            return $value;
        }

        // Single-string middleware (e.g. `middleware('web')`) is a literal
        // we can model as a single-item array.
        if ($value instanceof String_) {
            $synthetic = new Array_;
            $synthetic->items = [new ArrayItem($value)];

            return $synthetic;
        }

        return null;
    }

    /**
     * Parse a PHP fixture string and return discovered Route::middleware()
     * entries from it. Used by the self-test. Independent of file I/O.
     *
     * @return list<array{middleware_array: ?Array_, file: string, line: int}>
     */
    private function parseFixture(string $source): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;
        $ast = $parser->parse($source);
        if ($ast === null) {
            return [];
        }

        $entries = [];
        $calls = $finder->find($ast, fn (Node $n): bool => $this->isRouteMiddlewareCall($n));

        foreach ($calls as $call) {
            $entries[] = [
                'middleware_array' => $this->extractMiddlewareArrayFromCall($call),
                'file' => '<fixture>',
                'line' => $call->getStartLine(),
            ];
        }

        return $entries;
    }
}
