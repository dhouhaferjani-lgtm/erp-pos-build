<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep\Scanners;

use App\Application\Sweep\Scanners\CallsiteRow;
use App\Application\Sweep\Scanners\TanstackKeysScanner;
use Tests\TestCase;

/**
 * Pins the JS-scanner → PHP-scanner contract for the web.tanstack-keys
 * cluster. The TanstackKeysScanner shells out to
 * apps/web/tools/audit-tanstack-keys.mjs --json; this suite exercises the
 * payload-parsing path with hand-shaped JSON so test failures localize
 * to whichever side of the contract drifted.
 *
 * Invariants this test fixes (regressions = bug elsewhere):
 *   - scanner emits CallsiteRow with surface=web, scanner=ts_query_key,
 *     cluster_id=web.tanstack-keys for every violation.
 *   - file paths are promoted to repo-relative (apps/web/...) so verify-
 *     history + the inventory's `file` column use the same shape across
 *     surfaces.
 *   - stable_key composition feeds {scanner, file, symbol, ast_kind,
 *     resource, factory, statement_fingerprint} through StableKey, so
 *     two violations sharing only enclosing_symbol/factory still get
 *     distinct stable_keys via the byte-offset suffix in
 *     statement_fingerprint.
 *   - pattern_type lowercases the literal "queryKey" prefix so the
 *     non-alphanumeric sanitizer doesn't shred the camelCase 'K'.
 *   - empty / malformed payloads degrade gracefully to [].
 */
class TanstackKeysScannerTest extends TestCase
{
    public function test_parse_violations_payload_emits_callsite_row_per_violation(): void
    {
        $scanner = new TanstackKeysScanner('/repo');
        $payload = json_encode([
            'violations' => [
                [
                    'file' => 'src/features/users/hooks/useUsers.ts',
                    'line' => 42,
                    'column' => 5,
                    'reason' => 'useQuery({ queryKey: ... }) lacks an approved tenant scope',
                    'factory' => 'useQuery',
                    'enclosing_symbol' => 'useUsers',
                    'resource' => 'users',
                    'statement_fingerprint' => "['users']@1234",
                    'ast_kind' => 'array_literal',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $rows = $scanner->parseViolationsPayload($payload);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertInstanceOf(CallsiteRow::class, $row);
        $this->assertSame('web', $row->surface);
        $this->assertSame('ts_query_key', $row->scanner);
        $this->assertSame('web.tanstack-keys', $row->clusterId);
        $this->assertSame('apps/web/src/features/users/hooks/useUsers.ts', $row->relativePath);
        $this->assertSame(42, $row->line);
        $this->assertSame('useUsers.ts::useUsers', $row->symbol);
        $this->assertSame('users', $row->resource);
        $this->assertSame('tenant_and_company', $row->expectedScope);
        $this->assertSame('high', $row->severity);
        $this->assertFalse($row->fiscalPath);
        $this->assertFalse($row->crossModule);
        $this->assertSame('querykey_usequery_array_literal', $row->patternType);
        $this->assertStringStartsWith('sha256:', $row->stableKey);
    }

    public function test_two_violations_sharing_enclosing_symbol_get_distinct_stable_keys(): void
    {
        // Two `invalidateQueries({ queryKey: ['orders'] })` calls inside two
        // separate `onSuccess` callbacks of the same component — same
        // enclosing symbol + same fingerprint text, distinct byte offsets.
        // Critical for the cluster: ~118 such cases in the real codebase
        // would otherwise collide on stable_key and silently drop callsites.
        $scanner = new TanstackKeysScanner('/repo');
        $payload = json_encode([
            'violations' => [
                [
                    'file' => 'src/features/orders/Component.tsx',
                    'line' => 10,
                    'column' => 5,
                    'reason' => 'invalidateQueries({ queryKey: ... }) lacks an approved tenant scope',
                    'factory' => 'invalidateQueries',
                    'enclosing_symbol' => 'onSuccess',
                    'resource' => 'orders',
                    'statement_fingerprint' => "['orders']@100",
                    'ast_kind' => 'array_literal',
                ],
                [
                    'file' => 'src/features/orders/Component.tsx',
                    'line' => 20,
                    'column' => 5,
                    'reason' => 'invalidateQueries({ queryKey: ... }) lacks an approved tenant scope',
                    'factory' => 'invalidateQueries',
                    'enclosing_symbol' => 'onSuccess',
                    'resource' => 'orders',
                    'statement_fingerprint' => "['orders']@200",
                    'ast_kind' => 'array_literal',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $rows = $scanner->parseViolationsPayload($payload);

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->stableKey, $rows[1]->stableKey);
    }

    public function test_pattern_type_lowercases_querykey_prefix(): void
    {
        // Regression for an earlier bug where strtolower($factory) +
        // sprintf('queryKey_...') + sanitize('[^a-z0-9_]') shredded the
        // camelCase 'K' into 'query_ey_*'. Pattern types must be
        // grep-friendly — pin the canonical lowercase form.
        $scanner = new TanstackKeysScanner('/repo');
        $payload = json_encode([
            'violations' => [
                [
                    'file' => 'src/x.ts',
                    'line' => 1,
                    'column' => 1,
                    'factory' => 'invalidateQueries',
                    'enclosing_symbol' => null,
                    'resource' => null,
                    'statement_fingerprint' => 'k@0',
                    'ast_kind' => 'array_literal',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $rows = $scanner->parseViolationsPayload($payload);

        $this->assertCount(1, $rows);
        $this->assertSame('querykey_invalidatequeries_array_literal', $rows[0]->patternType);
    }

    public function test_handles_null_enclosing_symbol_with_filename_fallback(): void
    {
        $scanner = new TanstackKeysScanner('/repo');
        $payload = json_encode([
            'violations' => [
                [
                    'file' => 'src/feature/hook.ts',
                    'line' => 1,
                    'column' => 1,
                    'factory' => 'useQuery',
                    'enclosing_symbol' => null,
                    'resource' => null,
                    'statement_fingerprint' => 'a@0',
                    'ast_kind' => 'array_literal',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $rows = $scanner->parseViolationsPayload($payload);

        $this->assertSame('hook.ts', $rows[0]->symbol);
    }

    public function test_returns_empty_for_malformed_payload(): void
    {
        $scanner = new TanstackKeysScanner('/repo');

        $this->assertSame([], $scanner->parseViolationsPayload(''));
        $this->assertSame([], $scanner->parseViolationsPayload('not json'));
        $this->assertSame([], $scanner->parseViolationsPayload('{}'));
        $this->assertSame([], $scanner->parseViolationsPayload('{"violations":"not-array"}'));
    }

    public function test_skips_non_array_violation_entries(): void
    {
        $scanner = new TanstackKeysScanner('/repo');
        $payload = json_encode([
            'violations' => [
                'a-string-violation',
                42,
                null,
                [
                    'file' => 'src/x.ts',
                    'line' => 1,
                    'column' => 1,
                    'factory' => 'useQuery',
                    'enclosing_symbol' => 'foo',
                    'resource' => null,
                    'statement_fingerprint' => 'a@0',
                    'ast_kind' => 'array_literal',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $rows = $scanner->parseViolationsPayload($payload);

        $this->assertCount(1, $rows);
    }

    public function test_skips_violations_without_file_field(): void
    {
        $scanner = new TanstackKeysScanner('/repo');
        $payload = json_encode([
            'violations' => [
                ['file' => '', 'factory' => 'useQuery', 'enclosing_symbol' => 'a', 'ast_kind' => 'array_literal'],
                ['factory' => 'useQuery', 'enclosing_symbol' => 'a', 'ast_kind' => 'array_literal'],
                [
                    'file' => 'src/y.ts', 'line' => 1, 'column' => 1,
                    'factory' => 'useQuery', 'enclosing_symbol' => 'b',
                    'resource' => null, 'statement_fingerprint' => 'a@0',
                    'ast_kind' => 'array_literal',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $rows = $scanner->parseViolationsPayload($payload);

        $this->assertCount(1, $rows);
        $this->assertSame('apps/web/src/y.ts', $rows[0]->relativePath);
    }

    public function test_scan_returns_empty_when_script_missing(): void
    {
        $scanner = new TanstackKeysScanner('/nonexistent/path');

        $this->assertSame([], $scanner->scan());
    }
}
