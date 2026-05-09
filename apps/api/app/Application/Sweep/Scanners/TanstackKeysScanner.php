<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Scanner emitting CallsiteRows for TanStack Query `queryKey` callsites that
 * lack an approved tenant scope (master plan Section 5 — fifth scanner; § 11
 * cluster web.tanstack-keys). Wraps the JS scanner at
 * apps/web/tools/audit-tanstack-keys.mjs which is also Architecture Gate C
 * — both consumers stay in sync because the JS scanner is the single
 * source of truth.
 *
 * Invocation: runs `node apps/web/tools/audit-tanstack-keys.mjs --json` and
 * parses the structured violation payload (see the JS scanner's CLI
 * entrypoint for the exact field shape). When Node isn't on PATH or the
 * scanner returns a non-zero exit, scan() returns an empty list — the
 * generate command continues with the PHP scanners, and the missing web
 * coverage surfaces in the next CI run via Gate C's stderr count.
 *
 * Stable-key composition matches the convention in
 * {@see PhpAstFindScanner::buildRow()}: symbol = file basename + enclosing
 * function/component name, statement_fingerprint = whitespace-normalized
 * queryKey expression text suffixed with the byte offset (so multiple
 * callsites in the same enclosing symbol stay distinct).
 */
final class TanstackKeysScanner implements Scanner
{
    public function __construct(
        private readonly string $repoRoot,
        private readonly string $nodeBinary = 'node',
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'ts_query_key';
    }

    /**
     * @return list<CallsiteRow>
     */
    public function scan(): array
    {
        $scriptPath = rtrim($this->repoRoot, '/').'/apps/web/tools/audit-tanstack-keys.mjs';
        if (! is_file($scriptPath)) {
            return [];
        }

        $payload = $this->runScanner($scriptPath);
        if ($payload === null) {
            return [];
        }

        return $this->parseViolationsPayload($payload);
    }

    /**
     * Parses a `--json` payload from {@see audit-tanstack-keys.mjs} into a
     * list of CallsiteRows. Exposed separately from {@see scan()} so unit
     * tests can pin the JS/PHP contract without forking a Node subprocess
     * — `scan()` is one line of process glue around this method.
     *
     * @return list<CallsiteRow>
     */
    public function parseViolationsPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! isset($decoded['violations']) || ! is_array($decoded['violations'])) {
            return [];
        }

        /** @var list<CallsiteRow> $rows */
        $rows = [];
        foreach ($decoded['violations'] as $violation) {
            if (! is_array($violation)) {
                continue;
            }
            $row = $this->tryBuildRow($violation);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function runScanner(string $scriptPath): ?string
    {
        $process = new Process(
            [$this->nodeBinary, $scriptPath, '--json'],
            $this->repoRoot,
            timeout: $this->timeoutSeconds,
        );

        try {
            $process->run();
        } catch (ProcessFailedException|RuntimeException) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $stdout = $process->getOutput();

        return $stdout === '' ? null : $stdout;
    }

    /**
     * @param  array<string, mixed>  $violation
     */
    private function tryBuildRow(array $violation): ?CallsiteRow
    {
        $relWebPath = is_string($violation['file'] ?? null) ? $violation['file'] : null;
        if ($relWebPath === null || $relWebPath === '') {
            return null;
        }
        // The JS scanner emits paths relative to apps/web/. Promote to a
        // repo-root-relative path so downstream consumers (verify-history,
        // architecture tests, the inventory `file` column) all use the same
        // shape across surfaces.
        $relPath = 'apps/web/'.ltrim((string) $relWebPath, '/');

        $factory = is_string($violation['factory'] ?? null) ? $violation['factory'] : 'unknown';
        $enclosingSymbol = is_string($violation['enclosing_symbol'] ?? null) ? $violation['enclosing_symbol'] : null;
        $resource = is_string($violation['resource'] ?? null) ? $violation['resource'] : null;
        $line = is_int($violation['line'] ?? null) ? $violation['line'] : null;
        $astKind = is_string($violation['ast_kind'] ?? null) ? $violation['ast_kind'] : 'unknown';
        $statementFingerprint = is_string($violation['statement_fingerprint'] ?? null)
            ? $violation['statement_fingerprint']
            : '';

        $symbol = $this->buildSymbol($relPath, $enclosingSymbol);
        $patternType = $this->buildPatternType($factory, $astKind);

        $stableKey = StableKey::fromScannerOutput([
            'surface' => 'web',
            'scanner' => $this->name(),
            'normalized_relative_path' => $relPath,
            'symbol_fqn' => $symbol,
            'ast_node_kind' => $astKind,
            'model_or_table' => $resource ?? '',
            'field_or_method' => $factory,
            'normalized_argument_name' => '',
            'statement_fingerprint' => $statementFingerprint,
        ]);

        return new CallsiteRow(
            stableKey: $stableKey,
            surface: 'web',
            clusterId: 'web.tanstack-keys',
            scanner: $this->name(),
            relativePath: $relPath,
            line: $line,
            symbol: $symbol,
            patternType: $patternType,
            resource: $resource,
            expectedScope: 'tenant_and_company',
            expectedFix: 'Wrap the queryKey in tenantScopedKey([...]) so the cache invalidates on tenant/company switch. Helper at apps/web/src/lib/tenantScopedKey.ts; alternatively include currentCompanyId / companyStore.currentCompanyId in the key per the audit-tanstack-keys.mjs approval rules.',
            severity: 'high',
            fiscalPath: false,
            crossModule: false,
        );
    }

    private function buildSymbol(string $relPath, ?string $enclosingSymbol): string
    {
        $base = basename($relPath);
        if ($enclosingSymbol !== null && $enclosingSymbol !== '') {
            return $base.'::'.$enclosingSymbol;
        }

        return $base;
    }

    private function buildPatternType(string $factory, string $astKind): string
    {
        // Lowercase the literal prefix so the [^a-z0-9_] sanitizer doesn't
        // shred the camelCase 'K' into 'query_ey_*'.
        $raw = sprintf('querykey_%s_%s', strtolower($factory), strtolower($astKind));
        $sanitized = preg_replace('/[^a-z0-9_]+/', '_', $raw);
        if (! is_string($sanitized)) {
            return 'querykey_unknown';
        }

        return trim($sanitized, '_') ?: 'querykey_unknown';
    }
}
