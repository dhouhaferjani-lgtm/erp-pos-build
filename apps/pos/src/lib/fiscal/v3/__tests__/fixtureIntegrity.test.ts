/**
 * Gate 1 (TS side) — V3 golden-hash fixture existence + content-hash check.
 *
 * Problem: if a fixture JSON is deleted or silently mutated, the Vitest tests
 * that load `expected_hash` from it would throw at JSON.parse or produce a
 * confusing parse error rather than a clear integrity failure.
 *
 * This test hardcodes the SHA-256 hash of every fixture file's *raw bytes*.
 * When a fixture is legitimately updated the test fails with the old hash,
 * the developer reads the new hash from the test output, updates the constant,
 * and the change is reviewed in the PR diff — making intentional fixture
 * mutations an explicit two-step process.
 *
 * Hash manifest generated 2026-04-30 from HEAD bbdbd610 of feat/refund-flow-fixes.
 *
 * @see project_fiscal_chain_ci_gates.md
 */

import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import { describe, it, expect } from 'vitest';

const FIXTURE_DIR = resolve(__dirname, '../__fixtures__/v3-golden-hashes');

/** filename → expected SHA-256 of raw file bytes (must match PHP FixtureIntegrityTest::EXPECTED_HASHES) */
const EXPECTED_HASHES: Record<string, string> = {
  '01-cash-only-eur.json': '8fc899db9106aec97d19e6f9a09078d5ecc5fa5ce9a70c39a05eaec83833a289',
  '02-mixed-tender-tnd.json': 'e3b0d308bb6f8951107d88d0706fba273c18bfc8dd855577051b3608c1df5ce1',
  '03-voucher-tender-eur.json': 'd82ecec7b6652e82b7275db1e7a86468fdf5f3e8fa46a42cf75bac3a1cfe67fd',
  '04-stacked-vouchers-eur.json': '730355c13434c54ec046fed1a1a09a9fb01630c8f6c0e9a61f0211c3e027dd13',
  '05-return-with-voucher-issuance-eur.json':
    '6765348c03f6ea8dcedd2ac437e70a181142d950d05982e062fe45e20f3afe0e',
  '06-exchange-pair-eur.json': 'ccac1a7f7a317c3d3932900ea50e90774931edf60281ec11f62461d062a34ebb',
  '07-tnd-residual.json': 'fc806b6458ee5084c7d93f2a95cdaeea0b8a2fd511032ec96a13ae96bfac9062',
  '08-store-voucher-binding-eur.json':
    '762c5c27733549fedb85862554d7f5682b8e86097a68875daaebb6d953d286da',
};

describe('V3 golden-hash fixture integrity (TS side)', () => {
  it('all expected fixture files exist in the TS fixture directory', () => {
    for (const filename of Object.keys(EXPECTED_HASHES)) {
      const path = resolve(FIXTURE_DIR, filename);
      expect(
        existsSync(path),
        `Fixture '${filename}' is missing from the TS fixture directory. ` +
          'Deleting or renaming this file silently breaks the parity tests. ' +
          'If intentional, also remove the entry from EXPECTED_HASHES in this file.',
      ).toBe(true);
    }
  });

  for (const [filename, expectedHash] of Object.entries(EXPECTED_HASHES)) {
    it(`${filename} — raw bytes match manifest hash`, () => {
      const path = resolve(FIXTURE_DIR, filename);
      const rawBytes = readFileSync(path);
      const actualHash = createHash('sha256').update(rawBytes).digest('hex');

      expect(
        actualHash,
        `V3 golden-hash fixture '${filename}' has been tampered with or updated ` +
          'without updating the hash manifest.\n' +
          `  Expected SHA-256: ${expectedHash}\n` +
          `  Actual SHA-256:   ${actualHash}\n\n` +
          'If this change is intentional, update EXPECTED_HASHES in this file ' +
          `to '${actualHash}' and ensure the PHP counterpart ` +
          'FixtureIntegrityTest::EXPECTED_HASHES is updated to match.',
      ).toBe(expectedHash);
    });
  }
});
