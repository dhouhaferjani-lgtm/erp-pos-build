<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\Architecture\WebhookFixtures\StubCleanFixture;
use Tests\Architecture\WebhookFixtures\StubWithHelperMethodWriteFixture;
use Tests\Architecture\WebhookFixtures\StubWithModelQueryInsertFixture;
use Tests\Architecture\WebhookFixtures\StubWithServiceDependencyFixture;
use Tests\Architecture\WebhookFixtures\StubWithUpdateOrCreateFixture;
use Tests\TestCase;

/**
 * Step 6 of api.webhooks-incoming cluster (master plan §8 invariant — webhook
 * tenant binding via signature/payload, runtime-trust assumption boundary).
 *
 * Hardened in round 2 against Codex BLOCKER #1: the original guard scanned
 * only `__invoke()`/`handle()` against a narrow regex set, leaving common
 * write shapes (Eloquent updateOrCreate, Model::query()->insert(...),
 * private same-class helpers, constructor-injected services) as realistic
 * bypass routes. The hardened guard:
 *
 *   1. Walks ALL methods declared on the controller class (not just
 *      __invoke/handle), so a private helper performing the write is
 *      flagged the same as a write in __invoke().
 *
 *   2. Inspects the constructor's parameter types. A STUB-classified
 *      controller may only accept Illuminate\Http\Request or
 *      Psr\Log\LoggerInterface as a dependency. Any service or model
 *      type slipped through __construct fails the guard.
 *
 *   3. Expanded forbidden-pattern regex set covering: Eloquent reads
 *      AND writes (find, findOrFail, query, where, create, firstOrCreate,
 *      updateOrCreate, insert, upsert, save, delete, forceDelete, update,
 *      increment, decrement, push), facade calls (DB, Bus, Event, Queue,
 *      Notification, Cache mutations, Http), dispatch shapes (::dispatch,
 *      ::dispatchSync, ::dispatchNow), and notification routing (->notify).
 *
 *   4. Stub trigger is the explicit `STUB:` colon-prefixed marker (not
 *      bare "STUB" word), addressing Codex round-1 NICE-TO-HAVE #2 —
 *      a typo or rephrase ("Stub-shape, …", "Sub-stub, …") cannot
 *      silently downgrade a stub to a non-inspected cat-(b) entry.
 *
 *   5. Negative test (`test_stub_inspector_catches_known_bypass_shapes`)
 *      pins each of the round-1 bypass shapes to its expected violation
 *      messages. If a future refactor weakens the regex set, the negative
 *      test fails before the production controllers do.
 *
 * Asserts every concrete webhook controller class (file glob
 * `app/Modules/<vertical>/Presentation/Controllers/*Webhook*Controller*.php`)
 * carries a class-level `@cross-tenant-by-design <text>` annotation with
 * non-empty justification (single-line form, mirroring the
 * QueueJobTenantContextTest convention). When the justification begins
 * with the explicit `STUB:` marker, the hardened inspector additionally
 * walks every method body + the constructor parameter list and rejects
 * any forbidden call shape or non-allowlisted dependency type.
 *
 * Class-name-based discovery is used in lieu of full route-table parsing
 * because the 3 known webhook controllers all follow the
 * `*Webhook*Controller*` naming convention. A future webhook handler that
 * doesn't follow it would also be hard to classify by code review; the
 * convention is the load-bearing signal.
 *
 * The deferrals fixture
 * (`tests/Architecture/fixtures/webhook-controller-deferrals.json`) lists
 * classes that have been deferred (e.g. for refactor windows) and skipped
 * here. Each entry must include `class` + `reason` keys; bare class strings
 * are tolerated for cross-cluster precedent. Currently empty.
 */
final class WebhookControllerTenantContextTest extends TestCase
{
    /**
     * Forbidden call patterns inside a STUB-classified controller body.
     *
     * Each entry is a regex matched against the (comment-stripped) method
     * body's PHP source. Anchored with `\b` where appropriate so the
     * regex doesn't false-positive on substrings.
     *
     * Round 2 expansions over the original list (round-1 Codex BLOCKER):
     *   - Eloquent query-builder writes: ::query(, ::insert(, ::upsert(,
     *     ::firstOrCreate(, ::updateOrCreate(, ::forceDelete(,
     *     ::increment(, ::decrement(
     *   - Eloquent reads (a stub doesn't read DB either): ::all(, ::get(,
     *     ::first(, ::findMany(, ::pluck(
     *   - Facade additions: Http::, Cache::put/set/forget/remember,
     *     Storage:: (file write), Mail::, Schema::
     *   - Dispatch variants: ::dispatchSync(, ::dispatchNow(, ::dispatchAfterResponse(
     *   - Instance write methods (catch helpers building rows then saving):
     *     ->insert(, ->upsert(, ->forceDelete(, ->increment(, ->decrement(,
     *     ->firstOrCreate(, ->updateOrCreate(, ->push(, ->touch(,
     *     ->associate(, ->dissociate(, ->attach(, ->detach(, ->sync(
     *
     * @var list<string>
     */
    private const STUB_FORBIDDEN_PATTERNS = [
        // Facades (raw query, dispatch, event, queue, notification, http,
        // cache mutations, mail, storage, schema)
        '/\bDB::/',
        '/\bBus::/',
        '/\bEvent::/',
        '/\bQueue::/',
        '/\bNotification::/',
        '/\bHttp::/',
        '/\bMail::/',
        '/\bSchema::/',
        '/\bStorage::(?:put|append|prepend|delete|move|copy|makeDirectory|deleteDirectory)\(/',
        '/\bCache::(?:put|set|forget|remember|rememberForever|increment|decrement|add|pull|lock)\(/',

        // Class-static dispatch / write / read shapes (any class)
        '/::dispatch(?:Sync|Now|AfterResponse|If|Unless)?\(/',
        '/::create\(/',
        '/::firstOrCreate\(/',
        '/::updateOrCreate\(/',
        '/::insert(?:OrIgnore|GetId|Using)?\(/',
        '/::upsert\(/',
        '/::find(?:OrFail|Many|OrNew)?\(/',
        '/::all\(/',
        '/::get\(/',
        '/::first\(/',
        '/::pluck\(/',
        '/::query\(/',
        '/::where\(/',
        '/::whereIn\(/',
        '/::forceDelete\(/',
        '/::increment\(/',
        '/::decrement\(/',
        '/::push\(/',

        // Instance-method write shapes
        '/->save\(/',
        '/->delete\(/',
        '/->forceDelete\(/',
        '/->update\(/',
        '/->insert(?:OrIgnore|GetId|Using)?\(/',
        '/->upsert\(/',
        '/->firstOrCreate\(/',
        '/->updateOrCreate\(/',
        '/->increment\(/',
        '/->decrement\(/',
        '/->touch\(/',
        '/->push\(/',
        '/->notify\(/',
        '/->associate\(/',
        '/->dissociate\(/',
        '/->attach\(/',
        '/->detach\(/',
        '/->sync\(/',
    ];

    /**
     * Constructor parameter types allowed for STUB-classified controllers.
     *
     * A stub may legitimately type-hint a Request (for body access) or a
     * PSR-3 logger (for Log injection). Any other dependency — a service
     * class, a repository, a model — implies the stub can perform side-
     * effects through the dependency, which defeats the stub guarantee.
     *
     * Built-in primitives (string, int, etc.) and array are also allowed;
     * they don't carry side-effect potential.
     *
     * @var list<class-string>
     */
    private const STUB_ALLOWED_CTOR_TYPES = [
        Request::class,
        LoggerInterface::class,
    ];

    public function test_every_webhook_controller_class_is_cross_tenant_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $classes = $this->discoverWebhookControllerClasses();

        // Guard against vacuous-pass: if discovery finds nothing, fail
        // loudly. The 3 known cluster entries (Stripe, Enrichment,
        // PurchaseHub) are the floor; any discovery regression that drops
        // to 0 is a test-infrastructure bug, not a green signal.
        $this->assertNotEmpty(
            $classes,
            'Webhook-controller discovery returned 0 classes. Expected at least the 3 cluster entries:'
            ."\n  - App\\Modules\\Billing\\Presentation\\Controllers\\StripeWebhookController"
            ."\n  - App\\Modules\\PlatformIntegration\\Presentation\\Controllers\\EnrichmentWebhookController"
            ."\n  - App\\Modules\\PurchaseHub\\Presentation\\Controllers\\PurchaseHubWebhookController"
            ."\n\nIf the discovery glob (`*/Presentation/Controllers/*Webhook*Controller*.php`) is correct,"
            .' confirm the controllers exist and the file/class name pattern is unchanged.',
        );

        $unclassified = [];
        $bareAnnotations = [];
        $stubViolations = [];

        foreach ($classes as $class) {
            if (in_array($class, $deferrals, true)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $docBlock = $reflection->getDocComment();

            if ($docBlock === false) {
                $unclassified[] = $class;

                continue;
            }

            if (! preg_match('/@cross-tenant-by-design\b[ \t]*([^\r\n]*)/m', $docBlock, $matches)) {
                $unclassified[] = $class;

                continue;
            }

            $justification = trim($matches[1]);
            if ($justification === '') {
                $bareAnnotations[] = $class;

                continue;
            }

            if (preg_match('/^STUB:\s/', $justification) === 1) {
                $violations = $this->inspectStub($reflection);
                if (! empty($violations)) {
                    $stubViolations[$class] = $violations;
                }
            }
        }

        $this->assertEmpty(
            $unclassified,
            "Found webhook controller class(es) with no @cross-tenant-by-design annotation:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nEvery webhook controller MUST carry a class-level PHPDoc tag"
            .' `@cross-tenant-by-design <non-empty justification>`'
            .' documenting the tenant-resolution shape (master plan §8 step 2:'
            .' per-tenant secret / globally-unique resource id / per-tenant URL),'
            .' OR — if the controller is a verifiable stub — beginning the'
            .' justification with the explicit `STUB:` marker so the architecture'
            .' test enforces stub-shape body + constructor inspection.'
            ."\nSee docs/superpowers/audits/2026-05-07-api-webhooks-incoming-triage.md.",
        );

        $this->assertEmpty(
            $bareAnnotations,
            "Found webhook controller class(es) with bare `@cross-tenant-by-design` (no justification text):\n  - "
            .implode("\n  - ", $bareAnnotations)
            ."\n\nThe annotation MUST include a non-empty justification on the same line."
            ."\nExamples:"
            ."\n  - `@cross-tenant-by-design Stripe inline signature verification + globally-unique payment-intent ID resolution.`"
            ."\n  - `@cross-tenant-by-design STUB: zero DB access; signature middleware + tenant resolution required before any side-effect.`",
        );

        if (! empty($stubViolations)) {
            $messages = [];
            foreach ($stubViolations as $class => $violations) {
                $messages[] = "{$class}:\n      ".implode("\n      ", $violations);
            }
            $this->fail(
                "Found STUB-classified webhook controller(s) violating the stub-shape contract:\n\n  - "
                .implode("\n\n  - ", $messages)
                ."\n\nA controller annotated `@cross-tenant-by-design STUB: …` MUST"
                .' perform ZERO DB access, ZERO Bus/Event/Queue/Notification/Http'
                .' dispatch, ZERO Eloquent / query-builder calls, and accept ONLY'
                .' Illuminate\\Http\\Request or Psr\\Log\\LoggerInterface as'
                .' constructor dependencies. The route-level signature-verification'
                .' middleware is intentionally absent for stub controllers; the'
                .' body+constructor-shape guard is the load-bearing defense.'
                .' BEFORE adding any side-effect, register a signature middleware'
                .' on the route + define a tenant-resolution path + drop the'
                .' `STUB:` marker from the annotation.'
                ."\nSee the controller's class-level docblock (e.g."
                .' PurchaseHubWebhookController) for the conversion checklist.',
            );
        }
    }

    /**
     * Pin the round-1 BLOCKER bypass shapes to deterministic fail-modes
     * via fixture classes in tests/Architecture/Fixtures/Webhook/. If a
     * future refactor weakens any pattern, these fixtures stop catching
     * their bypass and this test fails before the production controllers
     * silently lose coverage.
     */
    public function test_stub_inspector_catches_known_bypass_shapes(): void
    {
        // Positive control: the clean stub fixture must produce ZERO
        // violations. Catches false-positive regressions in the inspector
        // (e.g. an over-eager regex that flags response()->json()).
        $cleanViolations = $this->inspectStub(new ReflectionClass(StubCleanFixture::class));
        $this->assertEmpty(
            $cleanViolations,
            'Stub inspector false-positive on the clean fixture; it should pass without violations.'
            ."\nViolations: ".implode(' | ', $cleanViolations),
        );

        // Bypass shape #1: Model::query()->insert(...) — round-1 list missed
        // ::query and ::insert. Round-2 list adds both.
        $insertViolations = $this->inspectStub(new ReflectionClass(StubWithModelQueryInsertFixture::class));
        $this->assertNotEmpty(
            $insertViolations,
            'Stub inspector failed to catch Model::query()->insert(...) bypass.',
        );
        $this->assertViolationsMatchAny($insertViolations, ['/::query/', '/::insert/'], 'query/insert bypass');

        // Bypass shape #2: ::updateOrCreate / ::firstOrCreate / ::insert /
        // ::upsert variants.
        $uocViolations = $this->inspectStub(new ReflectionClass(StubWithUpdateOrCreateFixture::class));
        $this->assertNotEmpty(
            $uocViolations,
            'Stub inspector failed to catch updateOrCreate/firstOrCreate/insert/upsert bypass.',
        );
        $this->assertViolationsMatchAny(
            $uocViolations,
            ['/::updateOrCreate/', '/::firstOrCreate/', '/::insert/', '/::upsert/'],
            'updateOrCreate family',
        );

        // Bypass shape #3: same-class private helper performs the write.
        // Round-1 inspector only walked __invoke/handle; round-2 walks all
        // declared methods and catches the helper.
        $helperViolations = $this->inspectStub(new ReflectionClass(StubWithHelperMethodWriteFixture::class));
        $this->assertNotEmpty(
            $helperViolations,
            'Stub inspector failed to catch same-class private-helper bypass (e.g. $this->persist()'
            .' delegating to a write).',
        );
        // The violation should mention the private method's name, proving
        // the walker reached it.
        $matched = false;
        foreach ($helperViolations as $v) {
            if (str_contains($v, 'persist')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue(
            $matched,
            'Helper-method violation should reference the private method name `persist`, proving the all-methods walk reached it. Got: '
            .implode(' | ', $helperViolations),
        );

        // Bypass shape #4: constructor-injected service dependency. Round-1
        // didn't inspect __construct; round-2 rejects any non-allowlisted
        // parameter type.
        $svcViolations = $this->inspectStub(new ReflectionClass(StubWithServiceDependencyFixture::class));
        $this->assertNotEmpty(
            $svcViolations,
            'Stub inspector failed to catch constructor-service-dependency bypass'
            .' (e.g. private readonly OrderReceiptService $svc).',
        );
        $this->assertViolationsMatchAny(
            $svcViolations,
            ['/constructor:.*forbidden dependency type/'],
            'constructor service dependency',
        );
    }

    /**
     * Discover every concrete (non-abstract) controller class whose file name
     * matches `*Webhook*Controller*.php` under any
     * `app/Modules/<vertical>/Presentation/Controllers/` directory. Test
     * fixtures under `tests/Architecture/Fixtures/Webhook/` are excluded
     * by the `Modules/` root anchor.
     *
     * @return list<class-string>
     */
    private function discoverWebhookControllerClasses(): array
    {
        $basePath = base_path();
        $modulesRoot = $basePath.'/app/Modules';

        if (! is_dir($modulesRoot)) {
            return [];
        }

        /** @var array<class-string, true> $seen */
        $seen = [];

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

            if (preg_match('@/[^/]*Webhook[^/]*Controller[^/]*\.php$@', $path) !== 1) {
                continue;
            }

            $class = $this->resolveClassFromFile($path);
            if ($class === null) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            /** @var class-string $class */
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $seen[$class] = true;
        }

        return array_keys($seen);
    }

    /**
     * Inspect a STUB-classified controller for any side-effect surface.
     * Returns a list of `<location>: <reason>` strings; empty list means
     * the class is verifiably stub-shaped.
     *
     * Inspection has two parts:
     *   1. Constructor — every parameter must have an allowlisted type.
     *   2. Body — every method declared on the class is scanned for the
     *      forbidden patterns. Inherited methods are skipped (the parent
     *      Controller class is not the stub under review).
     *
     * Comments are stripped via token_get_all before pattern matching, so
     * the docblock can document forbidden patterns without false-positive
     * triggering.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function inspectStub(ReflectionClass $reflection): array
    {
        $violations = [];

        // 1. Constructor parameter-type inspection.
        $ctor = $reflection->getConstructor();
        if ($ctor !== null && $ctor->getDeclaringClass()->getName() === $reflection->getName()) {
            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();
                if ($type === null) {
                    continue;
                }
                $typeName = $this->normalizeTypeName($type);
                if ($typeName === null) {
                    continue;
                }

                if (in_array($typeName, self::STUB_ALLOWED_CTOR_TYPES, true)) {
                    continue;
                }

                // Built-in primitives are allowed (string, int, bool, array,
                // float, mixed, null, void — though void in a parameter is
                // illegal anyway).
                if ($this->isPrimitiveType($typeName)) {
                    continue;
                }

                $paramName = $param->getName();
                $violations[] = "constructor: forbidden dependency type {$typeName} on parameter \${$paramName}";
            }
        }

        // 2. All-methods body inspection.
        foreach ($reflection->getMethods() as $method) {
            if ($method->isAbstract()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $body = $this->extractMethodBody($method);
            if ($body === null) {
                continue;
            }

            $stripped = $this->stripPhpComments($body);

            foreach (self::STUB_FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, $stripped) === 1) {
                    $violations[] = "{$method->getName()}: forbidden pattern matches {$pattern}";
                }
            }
        }

        return $violations;
    }

    /**
     * Reduce a ReflectionType to a simple FQCN or primitive name. Handles
     * named types, nullable wrappers, and union/intersection types by
     * picking the first named component (we only care that *some*
     * non-allowlisted type slips through).
     */
    private function normalizeTypeName(\ReflectionType $type): ?string
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            foreach ($type->getTypes() as $sub) {
                $name = $this->normalizeTypeName($sub);
                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    private function isPrimitiveType(string $name): bool
    {
        return in_array(
            strtolower($name),
            ['string', 'int', 'bool', 'array', 'float', 'mixed', 'null', 'void', 'iterable', 'callable', 'object', 'never', 'self', 'static', 'parent'],
            true,
        );
    }

    /**
     * Extract the source code of a method's body (between `{` and the
     * matching `}`). Uses ReflectionMethod's start/end line numbers and
     * reads the file. Returns null if the file cannot be read or lines
     * cannot be sliced.
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
     * Strip PHP comments from source code so forbidden-pattern detection
     * doesn't trigger on documentation strings inside the method body.
     * Handles all comment kinds via token_get_all (T_COMMENT covers `//`,
     * `#`, and slash-star block comments; T_DOC_COMMENT covers slash-
     * star-star docblocks).
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
     * Parse the namespace + class name from a PHP source file. Returns the
     * fully-qualified class name or null if the file doesn't declare a
     * namespaced class.
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
     * Read the deferrals fixture FRESH on every test invocation so that a
     * parallel cluster mutating the file mid-run is observable after a
     * `git pull --ff-only`.
     *
     * @return list<string>
     */
    private function loadDeferralsFresh(): array
    {
        $path = base_path('tests/Architecture/fixtures/webhook-controller-deferrals.json');
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

        $classes = [];
        foreach ($data as $entry) {
            if (is_string($entry)) {
                $classes[] = $entry;

                continue;
            }
            if (is_array($entry) && isset($entry['class']) && is_string($entry['class'])) {
                $classes[] = $entry['class'];
            }
        }

        return $classes;
    }

    /**
     * Assert that at least one violation in $violations matches at least
     * one of $patterns. The label is for the failure message.
     *
     * @param  list<string>  $violations
     * @param  list<string>  $patterns
     */
    private function assertViolationsMatchAny(array $violations, array $patterns, string $label): void
    {
        foreach ($violations as $violation) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $violation) === 1) {
                    return;
                }
            }
        }

        $this->fail(
            "Expected at least one violation matching {$label} (any of: ".implode(', ', $patterns).')'
            ."\nGot violations: ".implode(' | ', $violations),
        );
    }
}
