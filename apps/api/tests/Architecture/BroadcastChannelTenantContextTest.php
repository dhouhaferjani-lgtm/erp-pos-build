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
 * tenant-classified. A channel is classified iff one of:
 *
 *   (a) The channel name string embeds a `{tenantId}` segment AND the closure
 *       body contains a call to a known tenant-anchored auth helper
 *       (User::canAccessChannel or User::canAccessCompanyChannel — listed in
 *       {@see self::ALLOWED_AUTH_HELPERS}). The helper itself enforces the
 *       four-gate check (tenant_id match, active status, active company
 *       membership, optional resource segment) — see User.php:215-263.
 *
 *   (b) The channel definition is preceded by a PHPDoc tag
 *       `@cross-tenant-anchored <text>` (single-line form) with non-empty
 *       justification, OR `@cross-tenant-by-design <text>` for genuinely
 *       cross-tenant channels. Bare annotations with no justification fail
 *       this test — every annotation must name the genuine reason.
 *
 *   (c) The channel name string is listed in
 *       `tests/Architecture/fixtures/broadcast-channel-deferrals.json` under
 *       the `channels` key. The fixture is read fresh on every test
 *       invocation (NOT cached at class-load) so a parallel cluster mutating
 *       the file mid-run is picked up after `git pull --ff-only`. Currently
 *       empty.
 *
 * Channels are closures, not classes, so this test uses PhpParser to walk the
 * routes/channels.php AST rather than reflection. The static-analysis approach
 * mirrors the WebhookControllerTenantContextTest /
 * QueueJobTenantContextTest / ConsoleCommandTenantContextTest pattern from
 * earlier clusters.
 *
 * Dynamic channel names (e.g. Broadcast::channel($variable, ...)) are flagged
 * as a violation because they cannot be statically classified. If a future
 * channel definition needs a dynamic name, it must be wrapped in a constant
 * or annotated explicitly via the deferrals fixture. (None exist today.)
 *
 * Channels are read from the canonical `routes/channels.php` location only.
 * If a future Laravel upgrade splits broadcast channel registration across
 * multiple files (e.g. via a service provider), {@see self::CHANNEL_FILES}
 * must be updated.
 */
final class BroadcastChannelTenantContextTest extends TestCase
{
    /**
     * Files scanned for Broadcast::channel(...) calls.
     *
     * @var list<string>
     */
    private const CHANNEL_FILES = [
        'routes/channels.php',
    ];

    /**
     * Auth-helper method names recognized as tenant-anchored. A closure body
     * containing a call to one of these (e.g. `$user->canAccessChannel(...)`)
     * is treated as classified-by-helper.
     *
     * The helpers themselves live on App\Modules\Identity\Domain\User and
     * each performs the same four-gate check: tenant_id match, isActive(),
     * active UserCompanyMembership in the target company, optional resource
     * segment. If a future helper is added, list it here AND ensure the
     * helper carries the same four-gate guarantee.
     *
     * @var list<string>
     */
    private const ALLOWED_AUTH_HELPERS = [
        'canAccessChannel',
        'canAccessCompanyChannel',
    ];

    public function test_every_broadcast_channel_is_tenant_classified(): void
    {
        $deferrals = $this->loadDeferralsFresh();
        $channels = $this->discoverBroadcastChannelCalls();

        // Guard against vacuous-pass: if discovery finds zero channels, fail
        // loudly. The 5 known cluster entries are the floor; any drop to 0
        // is a test-infrastructure bug, not a green signal.
        $this->assertGreaterThanOrEqual(
            5,
            count($channels),
            'Broadcast-channel discovery returned fewer than 5 calls. Expected at least the 5 cluster entries:'
            ."\n  - tenant.{tenantId}.company.{companyId}.product.{productId}"
            ."\n  - tenant.{tenantId}.company.{companyId}.imports"
            ."\n  - tenant.{tenantId}.company.{companyId}.partners"
            ."\n  - tenant.{tenantId}.company.{companyId}.pos.terminal.{terminalId}"
            ."\n  - tenant.{tenantId}.company.{companyId}.pos.kitchen"
            ."\n\nIf the channel-files glob is correct, confirm routes/channels.php exists."
            .' Got: '.count($channels).' call(s).',
        );

        $unclassified = [];
        $bareAnnotations = [];
        $dynamicNames = [];

        foreach ($channels as $entry) {
            ['name' => $name, 'closure' => $closure, 'docblock' => $docblock, 'file' => $file, 'line' => $line] = $entry;

            // Dynamic channel names cannot be statically classified.
            if ($name === null) {
                $dynamicNames[] = "{$file}:{$line}";

                continue;
            }

            if (in_array($name, $deferrals, true)) {
                continue;
            }

            // (a) Channel name embeds {tenantId} AND closure body contains an
            // allowed auth-helper call.
            $hasTenantSegment = str_contains($name, '{tenantId}');
            $callsAllowedHelper = $closure !== null && $this->closureCallsAllowedAuthHelper($closure);

            if ($hasTenantSegment && $callsAllowedHelper) {
                continue;
            }

            // (b) PHPDoc with @cross-tenant-anchored or @cross-tenant-by-design.
            if ($docblock !== null) {
                $matchedAnchored = preg_match(
                    '/@cross-tenant-(?:anchored|by-design)\b[ \t]*([^\r\n]*)/m',
                    $docblock,
                    $matches,
                );

                if ($matchedAnchored === 1) {
                    $justification = trim($matches[1]);
                    if ($justification === '') {
                        $bareAnnotations[] = "{$file}:{$line} ({$name})";

                        continue;
                    }

                    continue;
                }
            }

            $unclassified[] = "{$file}:{$line} ({$name})";
        }

        $this->assertEmpty(
            $dynamicNames,
            "Found Broadcast::channel(...) call(s) with a non-string-literal name expression:\n  - "
            .implode("\n  - ", $dynamicNames)
            ."\n\nDynamic channel names cannot be statically classified."
            .' Either: (1) extract the name to a string literal and inline it,'
            .' (2) add a `@cross-tenant-anchored <justification>` PHPDoc above'
            .' the call documenting why the dynamic name is safe, or'
            .' (3) list the call site in tests/Architecture/fixtures/broadcast-channel-deferrals.json.',
        );

        $this->assertEmpty(
            $unclassified,
            "Found Broadcast::channel(...) call(s) with no tenant-context classification:\n  - "
            .implode("\n  - ", $unclassified)
            ."\n\nEach Broadcast::channel(...) call MUST EITHER:"
            ."\n  (a) Use a channel name pattern that embeds a `{tenantId}` segment AND"
            .' invoke a known tenant-anchored auth helper in the closure body'
            .' (allowlist: '.implode(', ', self::ALLOWED_AUTH_HELPERS).'),'
            ."\n  (b) Carry a preceding PHPDoc with `@cross-tenant-anchored <non-empty justification>`"
            .' (or `@cross-tenant-by-design` for genuinely cross-tenant channels),'
            ."\n  (c) Be listed in tests/Architecture/fixtures/broadcast-channel-deferrals.json."
            ."\n\nSee docs/superpowers/audits/2026-05-08-api-broadcast-channels-triage.md.",
        );

        $this->assertEmpty(
            $bareAnnotations,
            "Found Broadcast::channel(...) call(s) with bare `@cross-tenant-anchored` (no justification text):\n  - "
            .implode("\n  - ", $bareAnnotations)
            ."\n\nThe annotation MUST include a non-empty justification on the same line."
            ."\nExample: `@cross-tenant-anchored Channel name embeds tenantId + companyId; auth callback delegates to User::canAccessCompanyChannel.`",
        );
    }

    /**
     * Walk every CHANNEL_FILES file and return one entry per
     * Broadcast::channel(...) static call. Each entry is:
     *
     *   - name:     the channel name as a string-literal, or null if dynamic
     *   - closure:  the Closure node passed as the second argument, or null
     *               if not a closure (also treated as dynamic)
     *   - docblock: the preceding PHPDoc text, or null if absent
     *   - file:     the file path (relative to base_path())
     *   - line:     the start line of the Broadcast::channel(...) call
     *
     * @return list<array{name: ?string, closure: ?Closure, docblock: ?string, file: string, line: int}>
     */
    private function discoverBroadcastChannelCalls(): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $finder = new NodeFinder;

        $entries = [];

        foreach (self::CHANNEL_FILES as $relPath) {
            $absPath = base_path($relPath);
            if (! is_file($absPath)) {
                continue;
            }

            $source = (string) file_get_contents($absPath);
            $ast = $parser->parse($source);
            if ($ast === null) {
                continue;
            }

            // Walk top-level statements so we can pair each Broadcast::channel
            // call with the *immediately preceding* docblock on the same
            // expression statement (PHPDoc binds to the next statement in
            // PhpParser's representation).
            $this->walkStatementsForBroadcastCalls($ast, $relPath, $finder, $entries);
        }

        return $entries;
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

                $nameArg = $call->args[0]->value;
                $name = $nameArg instanceof Node\Scalar\String_ ? $nameArg->value : null;

                $closure = null;
                if (count($call->args) >= 2) {
                    $closureArg = $call->args[1]->value;
                    if ($closureArg instanceof Closure) {
                        $closure = $closureArg;
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
                    /** @var list<Stmt> $sub */
                    $this->walkStatementsForBroadcastCalls($sub, $relPath, $finder, $entries);
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
     * allowed auth-helpers via $user->method(...) or
     * $someVar->method(...) where method is in the allowlist.
     */
    private function closureCallsAllowedAuthHelper(Closure $closure): bool
    {
        $finder = new NodeFinder;

        $methodCalls = $finder->findInstanceOf($closure, Expr\MethodCall::class);

        foreach ($methodCalls as $call) {
            /** @var Expr\MethodCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            if (in_array($call->name->name, self::ALLOWED_AUTH_HELPERS, true)) {
                return true;
            }
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
