<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Shared\Architecture\CrossTenantRoute;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\Architecture\ControllerFixtures\FixtureControllerWithAttribute;
use Tests\Architecture\ControllerFixtures\FixtureControllerWithClassLevelCompanyContext;
use Tests\Architecture\ControllerFixtures\FixtureControllerWithCompanyContext;
use Tests\Architecture\ControllerFixtures\FixtureControllerWithRequestUser;
use Tests\Architecture\ControllerFixtures\FixtureControllerWithUserTenant;
use Tests\Architecture\ControllerFixtures\FixtureUnclassifiedController;
use Tests\TestCase;

/**
 * Step 4 of the api.super-admin-context cluster (master plan §9). The universal
 * controller-level invariant — every public method on every concrete controller
 * MUST EITHER:
 *
 *   (a) Carry the {@see CrossTenantRoute} method attribute with a non-blank
 *       reason naming the specific cross-tenant / pre-auth / fleet-wide
 *       behavior. The attribute's constructor enforces the non-blank
 *       reason at instantiation time; this test additionally verifies the
 *       reason is non-blank at scan time as a defense-in-depth honesty
 *       check.
 *
 *   (b) Plausibly resolve a tenant context, evidenced by a heuristic
 *       string-match on the (comment-stripped) method body OR the
 *       (comment-stripped) class body for one of:
 *       `CompanyContext`, `tenantId`, `tenant_id`, `companyId`, `company_id`,
 *       `Auth::user`, `auth()->user`, `request()->user`, `$request->user`,
 *       `->tenant`.
 *
 *       The class-body fallback admits the common shape where tenant-
 *       scoping is centralized in a private helper, the constructor
 *       (e.g. `private readonly CompanyContext $companyContext`), or a
 *       parent-class trait/concern, and the public method delegates to
 *       that helper without literally re-naming `tenant_id` in its own
 *       body. Public methods on a class that has CompanyContext anywhere
 *       in declarations are presumed to leverage that context unless
 *       the method explicitly carries `#[CrossTenantRoute]` to opt out.
 *
 *       Super-admin / fleet-wide controllers (SuperAdminController,
 *       AdminBillingController, MonitoringController, etc.) do NOT
 *       inject CompanyContext and do NOT use the heuristic substrings
 *       in their bodies — their methods MUST take branch (a). The
 *       class-level fallback is structurally distinguished from
 *       fleet-wide controllers because the latter never reference the
 *       heuristic substrings at all.
 *
 * The deferrals fixture
 * (`tests/Architecture/fixtures/controller-tenant-context-deferrals.json`)
 * lists `class::method` pairs whose tenant-binding shape genuinely cannot
 * be detected by the heuristic — Route Model Binding via tenant-scoped
 * Eloquent globalScope is the canonical example. Each fixture entry MUST
 * carry a `reason` field documenting the alternate binding mechanism so
 * the deferral is auditable.
 *
 * Honest limit of the heuristic (Codex review point):
 *
 *   The string-match heuristic is a static-analysis approximation. A
 *   method body that contains `tenant_id` only in a comment passes; a
 *   method that legitimately scopes via Route Model Binding without
 *   any of the heuristic substrings fails (and must enter the deferrals
 *   fixture). Comments are stripped before matching to remove the
 *   first false-positive vector. The runtime invariant — does the
 *   method ACTUALLY scope correctly to one tenant — is enforced by
 *   the per-cluster tenant-isolation feature tests already shipped
 *   (TreasuryClusterTenantIsolationTest, AccountingClusterTenantIsolationTest,
 *   etc.). This static test is the universal catchnet that a per-cluster
 *   test cannot replace because there is no per-cluster test for
 *   admin/auth/health controllers, and because new controllers in
 *   future PRs do not yet have per-cluster tests.
 *
 * Discovery scope:
 *
 *   - app/Http/Controllers/Api/**\/{*Controller,*.php} — recursive walk
 *     of every Api controller subtree (currently Api/, Api/Admin/).
 *   - app/Modules/**\/Presentation/Controllers/*Controller*.php — every
 *     module's Presentation/Controllers/ directory (handles nested
 *     Workshop/Bundle, Workshop/Technician, Workshop/WorkOrder shapes).
 *
 * Excluded:
 *
 *   - The base abstract `App\Http\Controllers\Controller`.
 *   - Abstract classes (skipped via ReflectionClass::isAbstract()).
 *   - Methods inherited from a parent class (only methods declared on
 *     the class itself count — a parent's classification doesn't
 *     pre-classify a subclass's overrides).
 *   - Non-public methods (private + protected helpers are not route
 *     handlers; the route-binder reflection only resolves public methods).
 *   - PHP magic methods (`__construct`, `__call`, `__callStatic`, etc.)
 *     and non-handler magic methods. The lone exception is `__invoke`
 *     which IS a route handler when bound to a single-action controller.
 */
final class ControllerTenantContextTest extends TestCase
{
    /**
     * Heuristic substrings that indicate plausible tenant-context
     * resolution in a controller method body. A match in the
     * (comment-stripped) body satisfies branch (b) of the invariant.
     *
     * Conservative set per orchestrator decision Q1 of 2026-05-08:
     * legitimate edge cases that don't match (e.g. Route Model Binding
     * via tenant-scoped Eloquent globalScope) enter the deferrals
     * fixture rather than expanding the regex toward false-positive
     * territory.
     *
     * @var list<string>
     */
    private const TENANT_SCOPING_HEURISTICS = [
        '/\bCompanyContext\b/',
        '/\btenantId\b/',
        '/\btenant_id\b/',
        '/\bcompanyId\b/',
        '/\bcompany_id\b/',
        '/\bAuth::user\b/',
        '/\bauth\(\)->user\b/',
        '/\brequest\(\)->user\b/',
        '/\$request->user\b/',
        '/->tenant\b/',
    ];

    public function test_every_controller_method_is_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $classes = $this->discoverControllerClasses();

        // Guard against vacuous-pass: if discovery finds nothing, fail
        // loudly. The cluster-shipped reference points are the floor.
        $this->assertNotEmpty(
            $classes,
            'Controller discovery returned 0 classes. Expected at least the SuperAdminController + AdminBillingController + tenant-scoped controllers under app/Modules/*/Presentation/Controllers/. If discovery globs (app/Http/Controllers/Api/** + app/Modules/**\\/Presentation/Controllers/*Controller*.php) are correct, confirm controllers exist and the file/class name pattern is unchanged.',
        );

        $unclassified = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $classBodyMatchesHeuristic = $this->classBodyMatchesHeuristic($reflection);

            foreach ($this->methodsToScan($reflection) as $method) {
                $key = $class.'::'.$method->getName();

                if (in_array($key, $deferrals, true)) {
                    continue;
                }

                $classification = $this->classifyMethod($method, $classBodyMatchesHeuristic);

                if ($classification === null) {
                    $unclassified[] = $key;
                }
            }
        }

        $this->assertEmpty(
            $unclassified,
            "Found controller method(s) that are neither tenant-scoped (heuristic match in body) nor carry the #[CrossTenantRoute(reason: ...)] attribute:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nEvery public method declared on a concrete controller MUST EITHER:"
            ."\n  (a) Carry #[CrossTenantRoute(reason: \"<non-blank>\")] naming the specific"
            .' cross-tenant / pre-auth / fleet-wide behavior — see'
            .' app/Shared/Architecture/CrossTenantRoute.php for the attribute contract.'
            ."\n  (b) Plausibly use a tenant context, evidenced by one of these substrings"
            .' in the method body: '.implode(', ', array_map(static fn (string $p): string => trim($p, '/'), self::TENANT_SCOPING_HEURISTICS))
            ."\n\nIf neither (a) nor (b) is true but the method is genuinely tenant-scoped via"
            .' a non-heuristic shape (e.g. Route Model Binding through a tenant-scoped'
            .' Eloquent globalScope), add a `class::method` entry with a `reason` field to'
            .' tests/Architecture/fixtures/controller-tenant-context-deferrals.json.'
            ."\n\nSee docs/superpowers/audits/2026-05-08-api-super-admin-context-and-web-super-admin-frontend-triage.md.",
        );
    }

    /**
     * Negative-control / honesty test. Uses fixture controller classes
     * (Tests\Architecture\ControllerFixtures\*) to pin each branch of
     * the classifier:
     *
     *   1. Unclassified — public method with no attribute and no
     *      heuristic match → MUST be flagged.
     *   2. Attribute-classified — public method with #[CrossTenantRoute]
     *      → MUST pass.
     *   3. CompanyContext-injected — public method calling
     *      $this->companyContext->requireCompanyId() → MUST pass.
     *   4. $user->tenant_id derivation — public method using
     *      $user->tenant_id → MUST pass via the tenant_id substring.
     *   5. $request->user() resolution — public method using
     *      $request->user()->tenant_id → MUST pass.
     *
     * If a future change weakens the heuristic regex set (e.g. one of
     * the substring patterns is dropped), the corresponding fixture
     * test fails BEFORE the production controllers do, giving an
     * actionable signal pinned to the test infrastructure rather
     * than a noisy production-controller test failure.
     *
     * The deliberate-bypass shape (FixtureUnclassifiedController) is
     * the test honesty pin: if the classifier is ever weakened to
     * accept everything (regression in the negative path), this test
     * fails before any green prod-controller signal can mask it.
     */
    public function test_classifier_pins_each_branch_of_the_invariant(): void
    {
        // 1. Unclassified MUST be flagged.
        $unclassifiedReflection = new ReflectionClass(FixtureUnclassifiedController::class);
        $unclassifiedMethod = $unclassifiedReflection->getMethod('handle');
        $this->assertNull(
            $this->classifyMethod($unclassifiedMethod),
            'FixtureUnclassifiedController::handle has no attribute and no heuristic substring; classifier MUST flag it as unclassified. If this assertion fires, the classifier was weakened to accept the bypass shape (regression).',
        );

        // 2-6. Each classified shape MUST pass.
        $passingFixtures = [
            [FixtureControllerWithAttribute::class, 'handle', 'attribute'],
            [FixtureControllerWithCompanyContext::class, 'handle', 'method-heuristic:CompanyContext'],
            [FixtureControllerWithUserTenant::class, 'handle', 'method-heuristic:tenant_id'],
            [FixtureControllerWithRequestUser::class, 'handle', 'method-heuristic:$request->user'],
            // Class-level fallback: method body has no heuristic substring,
            // but the constructor injects CompanyContext. Pins the b2
            // branch so a regression that drops class-level fallback fails
            // here before the production tenant-scoped controllers do.
            [FixtureControllerWithClassLevelCompanyContext::class, 'show', 'class-heuristic:CompanyContext'],
        ];

        foreach ($passingFixtures as [$class, $methodName, $expectedClassification]) {
            $reflection = new ReflectionClass($class);
            $method = $reflection->getMethod($methodName);
            $classification = $this->classifyMethod($method);
            $this->assertNotNull(
                $classification,
                "Fixture {$class}::{$methodName} expected to pass classification ({$expectedClassification}) but was flagged as unclassified. The corresponding heuristic OR attribute branch may have regressed.",
            );
        }
    }

    /**
     * Returns 'attribute' / 'heuristic:<pattern>' / 'class-heuristic:<pattern>'
     * for a classified method, null for an unclassified one. Centralized
     * so the main test and the negative-control test share the exact same
     * decision logic.
     *
     * The optional $classBodyMatchesHeuristic argument is a class-wide
     * pre-computed flag (caller computes once per class then reuses).
     * Pass null when calling this method outside the main scan loop and
     * the class-body fallback should be re-derived on demand.
     */
    private function classifyMethod(ReflectionMethod $method, ?string $classBodyMatchesHeuristic = null): ?string
    {
        // Branch (a): #[CrossTenantRoute] attribute.
        $attributes = $method->getAttributes(CrossTenantRoute::class);
        if (! empty($attributes)) {
            // Defense-in-depth honesty check: the attribute's constructor
            // already rejects blank reasons, but verify at scan time too.
            // If the attribute exists but the reason is blank, the
            // attribute construction would have thrown at autoload — so
            // this branch implies a non-blank reason. We still call
            // newInstance() to surface a more actionable error if the
            // contract is somehow violated.
            $instance = $attributes[0]->newInstance();
            if (trim($instance->reason) === '') {
                // Should never happen — attribute ctor would have thrown.
                // If it does, return null so the method gets reported as
                // unclassified with the standard message.
                return null;
            }

            return 'attribute';
        }

        // Branch (b1): method-body heuristic match.
        $body = $this->extractMethodBody($method);
        if ($body !== null) {
            $stripped = $this->stripPhpComments($body);

            foreach (self::TENANT_SCOPING_HEURISTICS as $pattern) {
                if (preg_match($pattern, $stripped) === 1) {
                    return 'method-heuristic:'.trim($pattern, '/');
                }
            }
        }

        // Branch (b2): class-body heuristic fallback. Caller pre-computed
        // the class-wide match per-class to avoid repeating the scan for
        // every method. If the caller didn't pass it (null), re-derive.
        if ($classBodyMatchesHeuristic === null) {
            $declaringClass = $method->getDeclaringClass();
            $classBodyMatchesHeuristic = $this->classBodyMatchesHeuristic($declaringClass);
        }

        if ($classBodyMatchesHeuristic !== null && $classBodyMatchesHeuristic !== '') {
            return 'class-heuristic:'.$classBodyMatchesHeuristic;
        }

        return null;
    }

    /**
     * Returns the matching heuristic pattern (without slashes) if the
     * (comment-stripped) class body contains a STRUCTURAL tenant-context
     * signal, OR the empty string if no match.
     *
     * Class-level fallback uses a TIGHTER pattern set than method-level:
     * only the literal `CompanyContext` substring is admitted. This is
     * deliberate — fleet-wide controllers (SuperAdminController,
     * AdminBillingController, MonitoringController) DO mention `tenant_id`
     * literally in their bodies because they navigate the tenant graph
     * for fleet-wide operations (`->where('tenant_id', $request->input(...))`).
     * Admitting `tenant_id` at the class level would falsely classify
     * those fleet-wide methods as cat-(b), defeating the whole invariant.
     *
     * `CompanyContext` is a structural signal: only tenant-scoped
     * controllers depend on it (constructor inject); fleet-wide ones
     * don't. A class file that contains `CompanyContext` anywhere has
     * either imported it for use OR declared it as a property/parameter
     * — both are honest indicators of tenant-context awareness.
     *
     * @param  ReflectionClass<object>  $class
     */
    private function classBodyMatchesHeuristic(ReflectionClass $class): string
    {
        $filename = $class->getFileName();
        if ($filename === false || ! is_file($filename)) {
            return '';
        }

        $source = file_get_contents($filename);
        if ($source === false) {
            return '';
        }

        $stripped = $this->stripPhpComments($source);

        // Structural class-level signals only (NOT the full method-body
        // heuristic set; see docblock above for the asymmetry rationale).
        $classLevelPatterns = ['/\bCompanyContext\b/'];

        foreach ($classLevelPatterns as $pattern) {
            if (preg_match($pattern, $stripped) === 1) {
                return trim($pattern, '/');
            }
        }

        return '';
    }

    /**
     * Methods declared on the controller class itself (not inherited),
     * non-abstract, public, and not a non-handler magic method.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<ReflectionMethod>
     */
    private function methodsToScan(ReflectionClass $class): array
    {
        $methods = [];

        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isAbstract()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $name = $method->getName();
            // Skip non-handler magic methods. __invoke IS a handler.
            if (in_array($name, ['__construct', '__destruct', '__call', '__callStatic', '__get', '__set', '__isset', '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize', '__toString', '__set_state', '__clone', '__debugInfo'], true)) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * Discover every concrete (non-abstract) controller class under:
     *   - app/Http/Controllers/Api/ (recursive)
     *   - app/Modules/**\/Presentation/Controllers/*Controller*.php
     *
     * The base abstract App\Http\Controllers\Controller is skipped via
     * isAbstract().
     *
     * @return list<class-string>
     */
    private function discoverControllerClasses(): array
    {
        $basePath = base_path();

        /** @var array<class-string, true> $seen */
        $seen = [];

        // Root 1: app/Http/Controllers/Api/** (Api + Api/Admin)
        $apiRoot = $basePath.'/app/Http/Controllers/Api';
        if (is_dir($apiRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($apiRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = $this->resolveClassFromFile($file->getPathname());
                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                /** @var class-string $class */
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $seen[$class] = true;
            }
        }

        // Root 2: app/Modules/**\/Presentation/Controllers/*Controller*.php
        $modulesRoot = $basePath.'/app/Modules';
        if (is_dir($modulesRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($modulesRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                if (preg_match('@/Presentation/Controllers/@', $path) !== 1) {
                    continue;
                }
                if (preg_match('@/[^/]*Controller[^/]*\.php$@', $path) !== 1) {
                    continue;
                }

                $class = $this->resolveClassFromFile($path);
                if ($class === null || ! class_exists($class)) {
                    continue;
                }

                /** @var class-string $class */
                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $seen[$class] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Extract the source code of a method's body via start/end line
     * numbers. Returns null if the file cannot be read.
     */
    private function extractMethodBody(ReflectionMethod $method): ?string
    {
        $filename = $method->getFileName();
        if ($filename === false || ! is_file($filename)) {
            return null;
        }

        $source = file_get_contents($filename);
        if ($source === false) {
            return null;
        }

        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();
        if ($startLine === false || $endLine === false) {
            return null;
        }

        $lines = explode("\n", $source);
        $slice = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);

        return implode("\n", $slice);
    }

    /**
     * Strip PHP comments via token_get_all so heuristic-pattern
     * detection doesn't false-positive on documentation strings inside
     * the method body. Handles `//`, `#`, slash-star block, and
     * docblock comments.
     */
    private function stripPhpComments(string $source): string
    {
        $tokens = token_get_all('<?php '.$source);
        $result = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }
                $result .= $text;
            } else {
                $result .= $token;
            }
        }

        return preg_replace('/^<\?php\s*/', '', $result) ?? $result;
    }

    /**
     * Parse the namespace + class name from a PHP source file. Returns
     * the FQCN or null if the file does not declare a namespaced class.
     */
    private function resolveClassFromFile(string $path): ?string
    {
        $contents = (string) file_get_contents($path);
        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatches) !== 1) {
            return null;
        }
        if (preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $classMatches) !== 1) {
            return null;
        }

        return trim($nsMatches[1]).'\\'.$classMatches[1];
    }

    /**
     * Read the deferrals fixture FRESH on every test invocation so that
     * a parallel cluster mutating the file mid-run is observable after
     * the next `git pull --ff-only`.
     *
     * Each entry has keys `class`, `method`, `reason`. The test pairs
     * class+method into the deferral key `<FQCN>::<method>`.
     *
     * @return list<string>
     */
    private function loadDeferralsFresh(): array
    {
        $path = base_path('tests/Architecture/fixtures/controller-tenant-context-deferrals.json');
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        /** @var mixed $data */
        $data = json_decode($contents, true);
        if (! is_array($data)) {
            return [];
        }

        $keys = [];
        foreach ($data as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (! isset($entry['class'], $entry['method']) || ! is_string($entry['class']) || ! is_string($entry['method'])) {
                continue;
            }
            $keys[] = $entry['class'].'::'.$entry['method'];
        }

        return $keys;
    }
}
