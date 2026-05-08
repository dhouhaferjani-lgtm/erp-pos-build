<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * Step 6 of api.broadcast-channels cluster.
 *
 * Asserts every Broadcast::channel(...) call in apps/api/routes/channels.php is
 * tenant-classified.
 *
 * Round-2 (Codex round-1 BLOCKER fix): the original test allowed an
 *
 * @cross-tenant-anchored PHPDoc with non-empty justification to classify a
 * tenant-named channel even when the closure body did NOT call an
 * allowlisted auth helper. Codex mutation b proved the bypass: replacing
 * the imports closure body with `return true;` while keeping the PHPDoc
 * still passed the test. The classification is now tightened so that
 *
 *   - tenant-named channels (channel name embeds `{tenantId}`) MUST invoke
 *     an allowlisted auth helper on the closure's first parameter (the
 *     authenticated `User`). The PHPDoc annotation is documentation; it
 *     does NOT bypass the helper-call requirement.
 *
 *   - non-tenant-named channels (no `{tenantId}` segment) MUST carry
 *     `@cross-tenant-by-design <non-empty justification>`. The
 *     `@cross-tenant-anchored` annotation alone is no longer sufficient
 *     to classify a non-tenant-named channel — it pairs with the helper-call
 *     requirement on tenant-named channels but is not a standalone classifier.
 *
 *   - dynamic channel names (Broadcast::channel($var, ...)) cannot be
 *     statically classified and fail with an explicit message.
 *
 *   - the deferrals fixture (`tests/Architecture/fixtures/broadcast-channel-deferrals.json`)
 *     can list known-exception channel-name strings; bare list entries are
 *     tolerated for cross-cluster precedent.
 *
 * Round-2 also tightens `closureCallsAllowedAuthHelper()` to require that
 * the helper-call's receiver be the closure's first parameter variable —
 * a stray `$randomThing->canAccessChannel(...)` (e.g. on a different
 * variable) no longer satisfies the invariant (Codex round-1
 * NICE-TO-HAVE #1).
 *
 * The {@see test_classification_logic_catches_known_bypass_shapes} self-test
 * pins each known bypass shape to its expected fail-mode via fixture files
 * under `tests/Architecture/BroadcastFixtures/`. If a future refactor
 * weakens any rule, the self-test catches the regression before the
 * production routes/channels.php silently loses coverage.
 *
 * Channels are read from the canonical `routes/channels.php` location only.
 * If a future Laravel upgrade splits broadcast channel registration across
 * multiple files (e.g. via a service provider), {@see self::CHANNEL_FILES}
 * must be updated.
 */
final class BroadcastChannelTenantContextTest extends TestCase
{
    /**
     * Files scanned for Broadcast::channel(...) calls in production.
     *
     * @var list<string>
     */
    private const CHANNEL_FILES = [
        'routes/channels.php',
    ];

    /**
     * Auth-helper method names recognized as tenant-anchored. The helpers
     * live on App\Modules\Identity\Domain\User and each performs the same
     * four-gate check: tenant_id match, isActive(), active
     * UserCompanyMembership in the target company, optional resource
     * segment. If a future helper is added, list it here AND ensure it
     * carries the same four-gate guarantee.
     *
     * @var list<string>
     */
    private const ALLOWED_AUTH_HELPERS = [
        'canAccessChannel',
        'canAccessCompanyChannel',
    ];

    /**
     * Floor for the production scan vacuous-pass guard. The cluster has 5
     * channel definitions today; a discovery regression that drops below
     * this is a test-infrastructure bug, not a green signal.
     */
    private const PRODUCTION_CHANNEL_FLOOR = 5;

    public function test_every_broadcast_channel_in_routes_channels_php_is_tenant_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $absPaths = [];
        foreach (self::CHANNEL_FILES as $relPath) {
            $absPaths[] = base_path($relPath);
        }

        $entries = $this->discoverBroadcastChannelCalls($absPaths);

        $this->assertGreaterThanOrEqual(
            self::PRODUCTION_CHANNEL_FLOOR,
            count($entries),
            'Broadcast-channel discovery returned fewer than '.self::PRODUCTION_CHANNEL_FLOOR
            .' calls in production. Expected at least the cluster entries:'
            ."\n  - tenant.{tenantId}.company.{companyId}.product.{productId}"
            ."\n  - tenant.{tenantId}.company.{companyId}.imports"
            ."\n  - tenant.{tenantId}.company.{companyId}.partners"
            ."\n  - tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}"
            ."\n  - tenant.{tenantId}.company.{companyId}.pos.kitchen"
            ."\n\nIf the channel-files glob is correct, confirm routes/channels.php exists."
            .' Got: '.count($entries).' call(s).',
        );

        $violations = $this->classifyEntries($entries, $deferrals);

        $this->assertEmpty(
            $violations['dynamic_names'],
            "Found Broadcast::channel(...) call(s) with a non-string-literal name expression:\n  - "
            .implode("\n  - ", $violations['dynamic_names'])
            ."\n\nDynamic channel names cannot be statically classified."
            .' Either: (1) extract the name to a string literal and inline it,'
            .' (2) annotate the call with `@cross-tenant-by-design <justification>` AND'
            .' add the channel-name pattern to the deferrals fixture, or'
            .' (3) list the call site in tests/Architecture/fixtures/broadcast-channel-deferrals.json.',
        );

        $this->assertEmpty(
            $violations['tenant_named_without_helper'],
            "Found tenant-named Broadcast::channel(...) call(s) whose closure does NOT invoke an allowlisted auth helper on the user parameter:\n  - "
            .implode("\n  - ", $violations['tenant_named_without_helper'])
            ."\n\nTenant-named channels (channel name embeds `{tenantId}`) MUST invoke an"
            .' allowlisted auth helper ('.implode(', ', self::ALLOWED_AUTH_HELPERS).')'
            ." on the closure's first parameter (the authenticated User)."
            .' A `@cross-tenant-anchored` PHPDoc is documentation only — it does NOT'
            .' bypass the helper-call requirement (Codex round-1 BLOCKER, mutation b).'
            ."\nSee docs/superpowers/audits/2026-05-08-api-broadcast-channels-triage.md.",
        );

        $this->assertEmpty(
            $violations['non_tenant_without_by_design'],
            "Found non-tenant-named Broadcast::channel(...) call(s) without a `@cross-tenant-by-design` annotation:\n  - "
            .implode("\n  - ", $violations['non_tenant_without_by_design'])
            ."\n\nA channel name that does NOT include `{tenantId}` must be classified by an"
            .' explicit `@cross-tenant-by-design <non-empty justification>` PHPDoc'
            .' OR be listed in the deferrals fixture. The'
            .' `@cross-tenant-anchored` annotation is reserved for tenant-named'
            .' channels (where it documents WHY the helper-call gate is sufficient)'
            .' and is NOT a standalone classifier for non-tenant-named channels.',
        );

        $this->assertEmpty(
            $violations['bare_annotations'],
            "Found Broadcast::channel(...) call(s) with bare `@cross-tenant-by-design` (no justification text):\n  - "
            .implode("\n  - ", $violations['bare_annotations'])
            ."\n\nThe annotation MUST include a non-empty justification on the same line.",
        );
    }

    /**
     * Self-test pinning each known bypass shape to its expected fail-mode.
     * Mirrors {@see WebhookControllerTenantContextTest::test_stub_inspector_catches_known_bypass_shapes}.
     *
     * Each fixture file under tests/Architecture/Fixtures/Broadcast/ exercises
     * a specific classification edge-case. If a future refactor weakens a
     * rule, the relevant fixture stops triggering its expected violation
     * and this test fails before the production scan silently loses coverage.
     */
    public function test_classification_logic_catches_known_bypass_shapes(): void
    {
        $fixtureRoot = __DIR__.'/BroadcastFixtures';

        // Positive control: clean fixture passes with zero violations.
        $cleanViolations = $this->classifyEntries(
            $this->discoverBroadcastChannelCalls([$fixtureRoot.'/sample-channel-routes-clean.php']),
            [],
        );
        $this->assertSame(
            [],
            array_filter($cleanViolations, fn (array $v) => count($v) > 0),
            'Clean fixture should produce zero violations. Got: '
            .json_encode($cleanViolations, JSON_PRETTY_PRINT),
        );

        // Bypass shape (Codex round-1 BLOCKER): tenant-named channel with
        // @cross-tenant-anchored PHPDoc but body is `return true;`. Must
        // fail under tenant_named_without_helper.
        $bypassViolations = $this->classifyEntries(
            $this->discoverBroadcastChannelCalls([$fixtureRoot.'/sample-channel-routes-anchored-bypass.php']),
            [],
        );
        $this->assertNotEmpty(
            $bypassViolations['tenant_named_without_helper'],
            'Anchored-bypass fixture (return true; body, anchored docblock) should fail tenant_named_without_helper. Got: '
            .json_encode($bypassViolations, JSON_PRETTY_PRINT),
        );

        // Bypass shape (Codex round-1 NICE-TO-HAVE #1): tenant-named channel
        // where the helper-method-name appears in the body but on a stray
        // variable, not the closure's first parameter. Must fail under
        // tenant_named_without_helper because the receiver-binding check
        // rejects the stray call.
        $strayViolations = $this->classifyEntries(
            $this->discoverBroadcastChannelCalls([$fixtureRoot.'/sample-channel-routes-stray-helper.php']),
            [],
        );
        $this->assertNotEmpty(
            $strayViolations['tenant_named_without_helper'],
            'Stray-helper fixture (canAccessChannel called on a non-user variable) should fail tenant_named_without_helper. Got: '
            .json_encode($strayViolations, JSON_PRETTY_PRINT),
        );

        // Bypass shape: non-tenant-named channel with bare @cross-tenant-by-design
        // (no justification). Must fail under bare_annotations AND
        // non_tenant_without_by_design (the bare annotation does not satisfy
        // the by-design requirement, so the non_tenant violation also fires).
        $bareViolations = $this->classifyEntries(
            $this->discoverBroadcastChannelCalls([$fixtureRoot.'/sample-channel-routes-bare-annotation.php']),
            [],
        );
        $this->assertNotEmpty(
            $bareViolations['bare_annotations'],
            'Bare-annotation fixture should fail bare_annotations. Got: '
            .json_encode($bareViolations, JSON_PRETTY_PRINT),
        );

        // Bypass shape: dynamic channel name. Must fail under dynamic_names.
        $dynamicViolations = $this->classifyEntries(
            $this->discoverBroadcastChannelCalls([$fixtureRoot.'/sample-channel-routes-dynamic-name.php']),
            [],
        );
        $this->assertNotEmpty(
            $dynamicViolations['dynamic_names'],
            'Dynamic-name fixture should fail dynamic_names. Got: '
            .json_encode($dynamicViolations, JSON_PRETTY_PRINT),
        );

        // Bypass shape: non-tenant-named channel with no annotation at all.
        // Must fail under non_tenant_without_by_design.
        $unannotatedViolations = $this->classifyEntries(
            $this->discoverBroadcastChannelCalls([$fixtureRoot.'/sample-channel-routes-non-tenant-unannotated.php']),
            [],
        );
        $this->assertNotEmpty(
            $unannotatedViolations['non_tenant_without_by_design'],
            'Non-tenant-unannotated fixture should fail non_tenant_without_by_design. Got: '
            .json_encode($unannotatedViolations, JSON_PRETTY_PRINT),
        );
    }

    /**
     * Classify a discovered-entries list into the four violation buckets.
     * Pure function — given the same entries + deferrals, produces the same
     * verdict. Used by both the production scan and the self-test.
     *
     * @param  list<array{name: ?string, closure: ?Closure, docblock: ?string, file: string, line: int}>  $entries
     * @param  list<string>  $deferrals
     * @return array{
     *     dynamic_names: list<string>,
     *     tenant_named_without_helper: list<string>,
     *     non_tenant_without_by_design: list<string>,
     *     bare_annotations: list<string>,
     * }
     */
    private function classifyEntries(array $entries, array $deferrals): array
    {
        $dynamicNames = [];
        $tenantNamedWithoutHelper = [];
        $nonTenantWithoutByDesign = [];
        $bareAnnotations = [];

        foreach ($entries as $entry) {
            ['name' => $name, 'closure' => $closure, 'docblock' => $docblock, 'file' => $file, 'line' => $line] = $entry;
            $location = "{$file}:{$line}";

            if ($name === null) {
                $dynamicNames[] = $location;

                continue;
            }

            if (in_array($name, $deferrals, true)) {
                continue;
            }

            $hasTenantSegment = str_contains($name, '{tenantId}');

            if ($hasTenantSegment) {
                // Tenant-named channels: the closure body MUST invoke an
                // allowlisted helper on the closure's first parameter.
                // Annotation is documentation-only; it does NOT bypass.
                if ($closure === null || ! $this->closureCallsAllowedAuthHelperOnFirstParam($closure)) {
                    $tenantNamedWithoutHelper[] = "{$location} ({$name})";
                }

                continue;
            }

            // Non-tenant-named channels: must carry @cross-tenant-by-design
            // with non-empty justification.
            if ($docblock === null) {
                $nonTenantWithoutByDesign[] = "{$location} ({$name})";

                continue;
            }

            if (preg_match(
                '/@cross-tenant-by-design\b[ \t]*([^\r\n]*)/m',
                $docblock,
                $matches,
            ) !== 1) {
                $nonTenantWithoutByDesign[] = "{$location} ({$name})";

                continue;
            }

            $justification = trim($matches[1]);
            if ($justification === '') {
                $bareAnnotations[] = "{$location} ({$name})";

                continue;
            }
        }

        return [
            'dynamic_names' => $dynamicNames,
            'tenant_named_without_helper' => $tenantNamedWithoutHelper,
            'non_tenant_without_by_design' => $nonTenantWithoutByDesign,
            'bare_annotations' => $bareAnnotations,
        ];
    }

    /**
     * Walk the given absolute file paths and return one entry per
     * Broadcast::channel(...) static call.
     *
     * @param  list<string>  $absPaths
     * @return list<array{name: ?string, closure: ?Closure, docblock: ?string, file: string, line: int}>
     */
    private function discoverBroadcastChannelCalls(array $absPaths): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;

        $entries = [];

        foreach ($absPaths as $absPath) {
            if (! is_file($absPath)) {
                continue;
            }

            $source = (string) file_get_contents($absPath);
            $ast = $parser->parse($source);
            if ($ast === null) {
                continue;
            }

            $relPath = $this->relativizePath($absPath);
            $this->walkStatementsForBroadcastCalls(array_values($ast), $relPath, $finder, $entries);
        }

        return $entries;
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
     * Recursively walk a statement list, pairing Broadcast::channel(...)
     * static calls with the docblock attached to their containing statement.
     *
     * @param  list<Stmt>  $stmts
     * @param  list<array{name: ?string, closure: ?Closure, docblock: ?string, file: string, line: int}>  $entries
     */
    private function walkStatementsForBroadcastCalls(
        array $stmts,
        string $relPath,
        NodeFinder $finder,
        array &$entries,
    ): void {
        foreach ($stmts as $stmt) {
            // The PHPDoc is attached to the outer statement; the static call
            // sits inside the Expression wrapper.
            $docblock = null;
            $comments = $stmt->getComments();
            foreach ($comments as $comment) {
                if ($comment instanceof Doc) {
                    $docblock = $comment->getText();
                    // Latest PHPDoc on the statement wins (typical PHP convention).
                }
            }

            // If the statement contains a Broadcast::channel call, record it.
            $calls = $finder->find(
                $stmt,
                fn (Node $n) => $n instanceof StaticCall && $this->isBroadcastChannelCall($n),
            );

            foreach ($calls as $call) {
                /** @var StaticCall $call */
                if (count($call->args) === 0) {
                    continue;
                }

                $firstArg = $call->args[0];
                if (! $firstArg instanceof Node\Arg) {
                    continue;
                }
                $nameArg = $firstArg->value;
                $name = $nameArg instanceof Node\Scalar\String_ ? $nameArg->value : null;

                $closure = null;
                if (count($call->args) >= 2) {
                    $secondArg = $call->args[1];
                    if ($secondArg instanceof Node\Arg) {
                        $closureArg = $secondArg->value;
                        if ($closureArg instanceof Closure) {
                            $closure = $closureArg;
                        }
                    }
                }

                $entries[] = [
                    'name' => $name,
                    'closure' => $closure,
                    'docblock' => $docblock,
                    'file' => $relPath,
                    'line' => $call->getStartLine(),
                ];
            }

            // Recurse into nested statement lists (function bodies, etc.).
            // Routes typically don't nest, but be safe.
            foreach ($stmt->getSubNodeNames() as $subName) {
                $sub = $stmt->{$subName};
                if (is_array($sub) && $this->looksLikeStatementList($sub)) {
                    /** @var list<Stmt> $subList */
                    $subList = array_values($sub);
                    $this->walkStatementsForBroadcastCalls($subList, $relPath, $finder, $entries);
                }
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $candidate
     */
    private function looksLikeStatementList(array $candidate): bool
    {
        foreach ($candidate as $value) {
            if ($value instanceof Stmt) {
                return true;
            }

            // First non-Stmt entry → not a statement list.
            return false;
        }

        return false;
    }

    private function isBroadcastChannelCall(StaticCall $call): bool
    {
        if (! $call->name instanceof Node\Identifier) {
            return false;
        }
        if ($call->name->name !== 'channel') {
            return false;
        }

        $class = $call->class;
        if (! $class instanceof Node\Name) {
            return false;
        }

        $last = $class->getLast();

        return $last === 'Broadcast';
    }

    /**
     * Return true iff the closure body contains a call to one of the
     * allowed auth-helpers AND the call's receiver is the closure's first
     * parameter variable (the authenticated `User`). A stray
     * `$randomThing->canAccessChannel(...)` is rejected — round-1
     * NICE-TO-HAVE #1.
     *
     * If the closure has zero parameters, the helper-call shape is
     * trivially unsatisfiable: the test fails such a closure.
     */
    private function closureCallsAllowedAuthHelperOnFirstParam(Closure $closure): bool
    {
        if (count($closure->params) === 0) {
            return false;
        }

        $firstParam = $closure->params[0];
        if (! $firstParam->var instanceof Expr\Variable) {
            return false;
        }
        $firstParamName = $firstParam->var->name;
        if (! is_string($firstParamName)) {
            return false;
        }

        $finder = new NodeFinder;

        $methodCalls = $finder->findInstanceOf($closure, Expr\MethodCall::class);

        foreach ($methodCalls as $call) {
            /** @var Expr\MethodCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            if (! in_array($call->name->name, self::ALLOWED_AUTH_HELPERS, true)) {
                continue;
            }
            // Receiver must be the first-param variable.
            if (! $call->var instanceof Expr\Variable) {
                continue;
            }
            if ($call->var->name !== $firstParamName) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Read the deferrals fixture FRESH on every test invocation so that a
     * parallel cluster mutating the file mid-run is observable after a
     * `git pull --ff-only`. The fixture may be a bare list of channel-name
     * strings OR a list of objects with `channel` + `reason` keys; bare
     * strings are tolerated for cross-cluster precedent.
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
            if (is_array($entry) && isset($entry['channel']) && is_string($entry['channel'])) {
                $names[] = $entry['channel'];
            }
        }

        return $names;
    }
}
