// @ts-check
import { spawnSync } from 'node:child_process';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, it, expect } from 'vitest';

import { scanCode } from '../audit-pos-local-cache.mjs';

/**
 * Unit + CLI coverage for the Phase 2B.2 POS local-cache scanner
 * ({@link ../audit-pos-local-cache.mjs}). Master plan Section 5.4 — the
 * scanner gates the Tauri-side SQLite cache against guarded-resource tables
 * lacking tenant_id/company_id columns and against sync envelope handlers
 * that skip the tenant-identity check before touching the payload.
 *
 * Tests 1–5 exercise the in-process scanCode(code, filename) API.
 * Tests 6–8 exercise the CLI: default-mode gate semantics (exit 1 on gap)
 * vs. --emit-inventory-rows emit-and-exit (exit 0, JSON lines on stdout).
 */

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const TOOLS_ROOT = path.resolve(__dirname, '..');
const SCRIPT = path.join(TOOLS_ROOT, 'audit-pos-local-cache.mjs');
const FIXTURES = path.join(TOOLS_ROOT, '__fixtures__', 'audit-pos-local-cache');

/**
 * @param {string[]} args
 * @returns {{ stdout: string, stderr: string, status: number | null }}
 */
function runCli(args) {
  const result = spawnSync(process.execPath, [SCRIPT, ...args], {
    encoding: 'utf8',
    cwd: TOOLS_ROOT,
  });
  return {
    stdout: result.stdout ?? '',
    stderr: result.stderr ?? '',
    status: result.status,
  };
}

/**
 * @param {string} relPath
 * @returns {Promise<string>}
 */
async function readFixture(relPath) {
  return fs.readFile(path.join(FIXTURES, relPath), 'utf8');
}

describe('Phase 2B.2 — audit-pos-local-cache scanner', () => {
  describe('in-process: scanCode()', () => {
    it('flags positive fixture: CREATE TABLE on guarded resource without tenant_id', async () => {
      const code = await readFixture('positive/create-table-without-tenant.ts');
      const violations = scanCode(code, 'positive/create-table-without-tenant.ts');
      expect(violations).toHaveLength(1);
      expect(violations[0].pattern_type).toBe('create_table_without_tenant');
      expect(violations[0].resource).toBe('payment_methods');
    });

    it('does not flag negative fixture: CREATE TABLE with tenant_id and company_id columns', async () => {
      const code = await readFixture('negative/create-table-with-tenant-id.ts');
      const violations = scanCode(code, 'negative/create-table-with-tenant-id.ts');
      expect(violations).toEqual([]);
    });

    it('does not flag negative fixture: CREATE TABLE on non-guarded resource (app_settings)', async () => {
      const code = await readFixture('negative/create-table-non-guarded-resource.ts');
      const violations = scanCode(code, 'negative/create-table-non-guarded-resource.ts');
      expect(violations).toEqual([]);
    });

    it('flags edge fixture: sync envelope handler that skips tenant validation', async () => {
      const code = await readFixture('edge/sync-envelope-without-tenant-check.ts');
      const violations = scanCode(code, 'edge/sync-envelope-without-tenant-check.ts');
      expect(violations).toHaveLength(1);
      expect(violations[0].pattern_type).toBe('sync_envelope_without_tenant_check');
      expect(violations[0].symbol).toContain('handleSyncEnvelope');
    });

    it('does not flag edge fixture: sync envelope handler that validates tenant identity first', async () => {
      const code = await readFixture('edge/sync-envelope-with-tenant-check.ts');
      const violations = scanCode(code, 'edge/sync-envelope-with-tenant-check.ts');
      expect(violations).toEqual([]);
    });
  });

  describe('CLI: default mode (gate)', () => {
    it('exits 1 when gaps detected, exits 0 when clean', () => {
      const positiveFixture = path.join(FIXTURES, 'positive/create-table-without-tenant.ts');
      const negativeFixture = path.join(FIXTURES, 'negative/create-table-with-tenant-id.ts');

      const fail = runCli([positiveFixture]);
      expect(fail.status).toBe(1);
      expect(fail.stderr).toMatch(/payment_methods/);
      expect(fail.stderr).toMatch(/create_table_without_tenant/);

      const pass = runCli([negativeFixture]);
      expect(pass.status).toBe(0);
    });
  });

  describe('CLI: --emit-inventory-rows mode', () => {
    it('produces JSON lines matching the inventory schema callsite shape', () => {
      const positiveFixture = path.join(FIXTURES, 'positive/create-table-without-tenant.ts');
      const result = runCli(['--emit-inventory-rows', positiveFixture]);
      expect(result.stdout.trim().length).toBeGreaterThan(0);
      const lines = result.stdout.trim().split('\n');
      expect(lines).toHaveLength(1);
      const row = JSON.parse(lines[0]);
      // Schema-required keys (per InventoryYamlSchema.json $defs.callsite plus
      // the scanner-emitted optional fields the YAML generator consumes).
      expect(row.surface).toBe('tauri');
      expect(row.scanner).toBe('pos_sqlite_cache');
      expect(row.cluster_id).toBe('tauri.sqlite-cache');
      expect(row.pattern_type).toBe('create_table_without_tenant');
      expect(row.resource).toBe('payment_methods');
      expect(row.expected_scope).toBe('tenant_and_company');
      expect(row.severity).toBe('high');
      expect(row.fiscal_path).toBe(false);
      expect(row.cross_module).toBe(false);
      expect(row.stale_state).toBe('active');
      expect(row.file).toMatch(/create-table-without-tenant\.ts$/);
      expect(typeof row.line).toBe('number');
      expect(row.symbol.length).toBeGreaterThan(0);
      // stable_key follows the canonical pattern: sha256:<64hex> OR
      // manual:<cluster>:<slug>. Both are accepted per the brief's fallback
      // clause if the PHP algorithm cannot be mirrored exactly.
      expect(row.stable_key).toMatch(/^(sha256:[0-9a-f]{64}|manual:[a-z0-9.-]+:[a-z0-9_-]+)$/);
    });

    it('exits 0 even when gaps are detected (emit-and-exit semantics)', () => {
      const positiveFixture = path.join(FIXTURES, 'positive/create-table-without-tenant.ts');
      const result = runCli(['--emit-inventory-rows', positiveFixture]);
      expect(result.status).toBe(0);
    });
  });
});
