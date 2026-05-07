<?php

declare(strict_types=1);

namespace Tests\Architecture;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Step 6 of api.webhooks-incoming cluster (master plan §8 invariant — webhook
 * tenant binding via signature/payload, runtime-trust assumption boundary).
 *
 * Asserts every concrete webhook controller class (file glob
 * `app/Modules/<vertical>/Presentation/Controllers/*Webhook*Controller*.php`
 * plus the platform-level `app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php`)
 * carries a class-level `@cross-tenant-by-design <text>` annotation with
 * non-empty justification (single-line form, mirroring the
 * QueueJobTenantContextTest convention).
 *
 * Class-name-based discovery is used in lieu of full route-table parsing
 * because:
 *
 *   - The 3 known webhook controllers all follow the `*Webhook*Controller*`
 *     naming convention (see triage doc 2026-05-07-api-webhooks-incoming-
 *     triage.md). The convention is itself a load-bearing signal: a future
 *     webhook handler that doesn't follow it would also be hard to
 *     classify by code review.
 *
 *   - Route-table parsing across routes/api.php + Modules/*\/Presentation/
 *     routes.php would require resolving Closure-based route groups,
 *     prefix() chains, and middleware()->group() blocks against PHP source.
 *     This is a regex-fragile path that produces more false positives than
 *     the file-glob approach for our small surface (3 controllers).
 *
 *   - The architecture test runs on every CI invocation; cheap is good.
 *
 * Stub-shape body inspection (cluster invariant for cat-(b)/stub
 * classification per orchestrator refinement #4): when the annotation
 * justification begins with the word "STUB" (case-insensitive,
 * word-boundary), the test additionally inspects the controller's
 * `__invoke()` and `handle()` method bodies for forbidden call patterns:
 *
 *   - `DB::`           — raw query facade
 *   - `Bus::`          — job dispatch facade
 *   - `Event::`        — event dispatch facade
 *   - `Queue::`        — queue facade
 *   - `Notification::` — notification facade
 *   - `::dispatch(`    — any class's dispatch() static method
 *   - `::create(`      — Eloquent create / factory create
 *   - `::find` (followed by ( or OrFail)
 *                      — Eloquent find / findOrFail
 *   - `->save(`        — Eloquent save
 *   - `->delete(`      — Eloquent / soft-delete
 *   - `->update(`      — Eloquent mass-update OR row-update
 *   - `->notify(`      — Notification routing
 *
 * Any forbidden pattern in a STUB-classified controller's method body
 * fails this test. The intent: a webhook controller that lacks a
 * signature-verification middleware in its route's middleware stack and
 * lacks an explicit tenant-resolution path can ONLY be safely classified
 * cat-(b)/stub if it performs ZERO side-effects. The test is the runtime-
 * verifiable enforcement of that contract.
 *
 * Non-STUB cat-(b) controllers (the StripeWebhookController inline-
 * signature pattern, the EnrichmentWebhookController middleware-signature
 * + downstream-job pattern) are exempt from body inspection — their
 * annotations document the resolution path explicitly, and changing the
 * controller body within those patterns is fine.
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
     * Each entry is a regex matched against the method body's PHP source.
     * Anchored with `\b` where appropriate so we don't false-positive on
     * substrings (e.g. `notDispatch` inside a comment would not match
     * `\bdispatch\(`).
     *
     * @var list<string>
     */
    private const STUB_FORBIDDEN_PATTERNS = [
        '/\bDB::/',
        '/\bBus::/',
        '/\bEvent::/',
        '/\bQueue::/',
        '/\bNotification::/',
        '/::dispatch\(/',
        '/::create\(/',
        '/::find(?:OrFail)?\(/',
        '/->save\(/',
        '/->delete\(/',
        '/->update\(/',
        '/->notify\(/',
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

            if (preg_match('/^STUB\b/i', $justification) === 1) {
                $violations = $this->inspectStubBody($reflection);
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
            .' justification with "STUB" so the architecture test enforces'
            .' stub-shape body inspection.'
            ."\nSee docs/superpowers/audits/2026-05-07-api-webhooks-incoming-triage.md.",
        );

        $this->assertEmpty(
            $bareAnnotations,
            "Found webhook controller class(es) with bare `@cross-tenant-by-design` (no justification text):\n  - "
            .implode("\n  - ", $bareAnnotations)
            ."\n\nThe annotation MUST include a non-empty justification on the same line."
            ."\nExamples:"
            ."\n  - `@cross-tenant-by-design Stripe inline signature verification + globally-unique payment-intent ID resolution.`"
            ."\n  - `@cross-tenant-by-design STUB controller — zero DB access; signature middleware + tenant resolution required before any side-effect.`",
        );

        if (! empty($stubViolations)) {
            $messages = [];
            foreach ($stubViolations as $class => $violations) {
                $messages[] = "{$class}:\n      ".implode("\n      ", $violations);
            }
            $this->fail(
                "Found STUB-classified webhook controller(s) whose method body contains forbidden call patterns:\n\n  - "
                .implode("\n\n  - ", $messages)
                ."\n\nA controller annotated `@cross-tenant-by-design STUB …` MUST"
                .' perform ZERO DB access, ZERO Bus/Event/Queue/Notification dispatch,'
                .' and ZERO Eloquent mutations in its `__invoke()` or `handle()` body.'
                .' The route-level signature-verification middleware is intentionally'
                .' absent for stub controllers; the body-shape guard is the load-bearing'
                .' defense. BEFORE adding any side-effect, register a signature middleware'
                .' on the route + define a tenant-resolution path + update the annotation.'
                ."\nSee the controller's class-level docblock (e.g."
                .' PurchaseHubWebhookController) for the conversion checklist.',
            );
        }
    }

    /**
     * Discover every concrete (non-abstract) controller class whose file name
     * matches `*Webhook*Controller*.php` under any
     * `app/Modules/<vertical>/Presentation/Controllers/` directory.
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
     * Inspect the controller's `__invoke()` and/or `handle()` method bodies
     * for any forbidden call pattern. Returns a list of "method-name: pattern"
     * strings for every violation found. Empty list means the body is
     * stub-shaped.
     *
     * @param  ReflectionClass<object>  $reflection
     * @return list<string>
     */
    private function inspectStubBody(ReflectionClass $reflection): array
    {
        $violations = [];
        $candidateMethods = ['__invoke', 'handle'];

        foreach ($candidateMethods as $methodName) {
            if (! $reflection->hasMethod($methodName)) {
                continue;
            }

            $method = $reflection->getMethod($methodName);
            if ($method->isAbstract()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                // Inherited from parent — not the stub we're inspecting.
                continue;
            }

            $body = $this->extractMethodBody($method);
            if ($body === null) {
                continue;
            }

            // Strip single-line comments + multi-line comments + docblock-style
            // comments to avoid false-positives where the documentation
            // mentions a forbidden pattern (e.g. the class-level docblock
            // text "DB:: facade" wouldn't appear here because it's outside
            // the method, but a method-level inline `// DB:: not allowed`
            // would). We strip aggressively.
            $stripped = $this->stripPhpComments($body);

            foreach (self::STUB_FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, $stripped) === 1) {
                    $violations[] = "{$methodName}: forbidden pattern matches /{$pattern}/";
                }
            }
        }

        return $violations;
    }

    /**
     * Extract the source code of a method's body (between { and the matching
     * }). Uses ReflectionMethod's start/end line numbers and reads the file.
     * Returns null if the file cannot be read or lines cannot be sliced.
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

        // The token stream prepended `<?php ` — strip it.
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
}
