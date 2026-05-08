<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Step 6 of api.broadcast-channels cluster — paired with
 * BroadcastChannelTenantContextTest (which scans the channel-definition side).
 *
 * Asserts every concrete broadcast event class living under
 * `app/Modules/<vertical>/Infrastructure/Broadcasting/` is tenant-classified:
 *
 *   (a) Class-level PHPDoc tag `@cross-tenant-anchored <text>` with non-empty
 *       justification (the standard shape — broadcastOn() constructs the
 *       channel name from event-property tenantId / companyId), OR
 *       `@cross-tenant-by-design <text>` for genuinely cross-tenant
 *       broadcasts (none exist today, but the grammar is supported).
 *
 *   (b) `broadcastOn()` body must NOT read tenant/company identity from
 *       request-scoped facades. Forbidden patterns inside the method body:
 *       `auth(`, `Auth::`, `request(`, `Request::`, `app(`, `App::`,
 *       `CompanyContext`, `session(`, `Session::`. Any of these in
 *       broadcastOn() is a bug — channels constructed at broadcast time
 *       must source tenant/company from event-construction-time properties
 *       (typically $this->tenantId / $this->companyId or
 *       $this->event->tenantId / $this->domainEvent->tenantId), so an
 *       async-promotion (ShouldBroadcastNow → ShouldBroadcast in a queue
 *       worker context where auth() is null) does not silently regress.
 *
 *       The current 8 classes in scope are all ShouldBroadcastNow
 *       (synchronous), so the queue-context-loss risk is not active today.
 *       This guard locks the property-sourced shape so future
 *       async-promotion stays safe.
 *
 * Discovery: any concrete (non-abstract) class implementing
 * Illuminate\Contracts\Broadcasting\ShouldBroadcast OR ShouldBroadcastNow,
 * declared under any `app/Modules/<vertical>/Infrastructure/Broadcasting/`
 * directory.
 *
 * Deferrals fixture: `tests/Architecture/fixtures/broadcast-channel-deferrals.json`
 * (shared with BroadcastChannelTenantContextTest). Entries may be objects
 * with `event` + `reason` keys for event-class deferrals; bare class strings
 * are also tolerated. Currently empty.
 */
final class BroadcastEventTenantContextTest extends TestCase
{
    /**
     * Forbidden patterns inside broadcastOn() that indicate request-scoped
     * sourcing of tenant/company identity. Each entry is a regex matched
     * against the (comment-stripped) method body's PHP source.
     *
     * @var list<string>
     */
    private const FORBIDDEN_BROADCASTON_PATTERNS = [
        // Request-scoped facades
        '/\bauth\(/',
        '/\bAuth::/',
        '/\brequest\(/',
        '/\bRequest::/',
        '/\bapp\(/',
        '/\bsession\(/',
        '/\bSession::/',

        // CompanyContext is request-scoped (resolved by middleware) and
        // empty in queue-worker / synchronous-fan-out contexts
        '/\bCompanyContext\b/',
    ];

    public function test_every_broadcast_event_class_is_tenant_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $classes = $this->discoverBroadcastEventClasses();

        // Vacuous-pass guard: floor of 5 (the orchestrator's enumerated
        // list) — discovery returning 0 indicates a test-infrastructure
        // bug, not a green signal. The full set today is 8.
        $this->assertGreaterThanOrEqual(
            5,
            count($classes),
            'Broadcast-event-class discovery returned fewer than 5 classes. Expected at least:'
            ."\n  - App\\Modules\\Product\\Infrastructure\\Broadcasting\\ProductCostPriceUpdatedBroadcast"
            ."\n  - App\\Modules\\Partner\\Infrastructure\\Broadcasting\\PartnerBalanceUpdatedBroadcast"
            ."\n  - App\\Modules\\POS\\Infrastructure\\Broadcasting\\OrderSentToKitchenBroadcast"
            ."\n  - App\\Modules\\POS\\Infrastructure\\Broadcasting\\OrderReadyBroadcast"
            ."\n  - App\\Modules\\POS\\Infrastructure\\Broadcasting\\TerminalActivatedBroadcast"
            ."\n\nIf the discovery glob (`*/Infrastructure/Broadcasting/*.php`) is correct,"
            .' confirm at least one matching class exists. Got: '.count($classes).' class(es).',
        );

        $unclassified = [];
        $bareAnnotations = [];
        $bodyViolations = [];
        $missingBroadcastOn = [];

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

            if (! preg_match(
                '/@cross-tenant-(?:anchored|by-design)\b[ \t]*([^\r\n]*)/m',
                $docBlock,
                $matches,
            )) {
                $unclassified[] = $class;

                continue;
            }

            $justification = trim($matches[1]);
            if ($justification === '') {
                $bareAnnotations[] = $class;

                continue;
            }

            // Inspect broadcastOn() body for forbidden request-scoped sourcing.
            if (! $reflection->hasMethod('broadcastOn')) {
                $missingBroadcastOn[] = $class;

                continue;
            }

            $method = $reflection->getMethod('broadcastOn');
            // Only inspect the method if it's declared on this class, not
            // inherited from a parent (we don't redundantly re-check trait
            // / parent implementations across multiple subclasses).
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }

            $violations = $this->inspectBroadcastOn($method);
            if (! empty($violations)) {
                $bodyViolations[$class] = $violations;
            }
        }

        $this->assertEmpty(
            $unclassified,
            "Found broadcast event class(es) with no `@cross-tenant-anchored` annotation:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nEvery concrete ShouldBroadcast / ShouldBroadcastNow class under"
            .' app/Modules/*/Infrastructure/Broadcasting/ MUST carry a class-level PHPDoc'
            .' tag `@cross-tenant-anchored <non-empty justification>` documenting the'
            .' property-sourcing path (typically: `broadcastOn() constructs the channel'
            .' from constructor-property tenantId + companyId / event-property tenantId +'
            .' companyId`). For genuinely cross-tenant broadcasts, use'
            .' `@cross-tenant-by-design <justification>` instead.'
            ."\nSee docs/superpowers/audits/2026-05-08-api-broadcast-channels-triage.md.",
        );

        $this->assertEmpty(
            $bareAnnotations,
            "Found broadcast event class(es) with bare `@cross-tenant-anchored` (no justification text):\n  - "
            .implode("\n  - ", $bareAnnotations)
            ."\n\nThe annotation MUST include a non-empty justification on the same line.",
        );

        $this->assertEmpty(
            $missingBroadcastOn,
            "Found broadcast event class(es) without a broadcastOn() method:\n  - "
            .implode("\n  - ", $missingBroadcastOn)
            ."\n\nEvery ShouldBroadcast / ShouldBroadcastNow class must declare broadcastOn()."
            .' If the class is abstract, list it in the discovery skip-list; otherwise add the method.',
        );

        if (! empty($bodyViolations)) {
            $messages = [];
            foreach ($bodyViolations as $class => $violations) {
                $messages[] = "{$class}:\n      ".implode("\n      ", $violations);
            }
            $this->fail(
                "Found broadcast event class(es) with request-scoped sourcing in broadcastOn():\n\n  - "
                .implode("\n\n  - ", $messages)
                ."\n\nbroadcastOn() MUST construct channel names from event-construction-time"
                .' properties (e.g. constructor-provided readonly $tenantId / $companyId, or'
                .' $this->event->tenantId / $this->domainEvent->companyId). Reading from auth(),'
                .' request(), Auth::, Request::, app(), CompanyContext, session(), or Session::'
                .' is forbidden — these are request-scoped and empty in queue-worker contexts.'
                .' If the broadcast is intentionally cross-tenant or system-wide, switch the'
                .' annotation to `@cross-tenant-by-design <justification>`.',
            );
        }
    }

    /**
     * Discover every concrete (non-abstract) class implementing
     * Illuminate\Contracts\Broadcasting\ShouldBroadcast or ShouldBroadcastNow
     * that lives under any
     * app/Modules/<vertical>/Infrastructure/Broadcasting/ directory.
     *
     * @return list<class-string>
     */
    private function discoverBroadcastEventClasses(): array
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

            if (preg_match('@/Infrastructure/Broadcasting/@', $path) !== 1) {
                continue;
            }

            $class = $this->resolveClassFromFile($path);
            if ($class === null) {
                continue;
            }

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }
            if (
                ! $reflection->implementsInterface(ShouldBroadcast::class)
                && ! $reflection->implementsInterface(ShouldBroadcastNow::class)
            ) {
                continue;
            }

            /** @var class-string $class */
            $seen[$class] = true;
        }

        return array_keys($seen);
    }

    /**
     * Inspect a broadcastOn() method for forbidden request-scoped sourcing.
     *
     * Comments are stripped before pattern matching so the docblock can
     * legitimately reference the patterns without false-triggering.
     *
     * @return list<string>
     */
    private function inspectBroadcastOn(ReflectionMethod $method): array
    {
        $body = $this->extractMethodBody($method);
        if ($body === null) {
            return [];
        }

        $stripped = $this->stripPhpComments($body);

        $violations = [];
        foreach (self::FORBIDDEN_BROADCASTON_PATTERNS as $pattern) {
            if (preg_match($pattern, $stripped) === 1) {
                $violations[] = "broadcastOn(): forbidden pattern matches {$pattern}";
            }
        }

        return $violations;
    }

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
     * Read the deferrals fixture FRESH on every test invocation. Shared
     * with BroadcastChannelTenantContextTest. Entries may be:
     *   - bare strings (treated as class FQCN OR channel name)
     *   - objects with `class` + `reason` keys (event-class deferrals)
     *   - objects with `channel` + `reason` keys (channel-name deferrals,
     *     consumed by the sister test)
     *
     * @return list<string>
     */
    private function loadDeferralsFresh(): array
    {
        $path = base_path('tests/Architecture/fixtures/broadcast-channel-deferrals.json');
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

        $names = [];
        foreach ($data as $entry) {
            if (is_string($entry)) {
                $names[] = $entry;

                continue;
            }
            if (is_array($entry) && isset($entry['class']) && is_string($entry['class'])) {
                $names[] = $entry['class'];
            }
        }

        return $names;
    }
}
