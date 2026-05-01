/**
 * Gate 2 — Cross-language fixture sync guard.
 *
 * Problem: the PHP fixture directory
 *   apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/
 * and the TS fixture directory
 *   apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/
 * are byte-identical today. Nothing in CI would fail if they drifted tomorrow.
 *
 * This test:
 *   1. Asserts both directories contain exactly the same set of filenames.
 *   2. For each filename, reads both files and asserts byte equality.
 *
 * Pattern adapted from apps/pos/src/lib/payment/__tests__/paymentMethodKind.parity.test.ts.
 *
 * @see project_fiscal_chain_ci_gates.md
 */

import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, it, expect } from 'vitest';

// Path anchored at this test file's location:
//   apps/pos/src/lib/fiscal/v3/__tests__/ → 7 "../" steps to repo root
const REPO_ROOT = resolve(__dirname, '../../../../../../../');

const PHP_FIXTURE_DIR = resolve(
  REPO_ROOT,
  'apps/api/tests/Fixtures/Fiscal/v3-golden-hashes',
);
const TS_FIXTURE_DIR = resolve(__dirname, '../__fixtures__/v3-golden-hashes');

function listJsonFiles(dir: string): string[] {
  return readdirSync(dir)
    .filter((f) => f.endsWith('.json'))
    .sort();
}

describe('V3 golden-hash fixture cross-language sync (PHP ↔ TS)', () => {
  it('PHP fixture directory is reachable (path sanity check)', () => {
    expect(
      existsSync(PHP_FIXTURE_DIR),
      `PHP fixture directory not found at ${PHP_FIXTURE_DIR}. ` +
        'Verify the relative path from this test file to the repo root is correct.',
    ).toBe(true);
  });

  it('both fixture directories contain the same set of filenames', () => {
    const phpFiles = listJsonFiles(PHP_FIXTURE_DIR);
    const tsFiles = listJsonFiles(TS_FIXTURE_DIR);

    const onlyInPhp = phpFiles.filter((f) => !tsFiles.includes(f));
    const onlyInTs = tsFiles.filter((f) => !phpFiles.includes(f));

    expect(
      onlyInPhp,
      `Fixture file(s) present in PHP directory but missing from TS directory: ${onlyInPhp.join(', ')}. ` +
        'Copy the missing fixture(s) to apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/ ' +
        'and add hash entries to EXPECTED_HASHES in fixtureIntegrity.test.ts.',
    ).toEqual([]);

    expect(
      onlyInTs,
      `Fixture file(s) present in TS directory but missing from PHP directory: ${onlyInTs.join(', ')}. ` +
        'Copy the missing fixture(s) to apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/ ' +
        'and add hash entries to FixtureIntegrityTest::EXPECTED_HASHES.',
    ).toEqual([]);
  });

  for (const filename of listJsonFiles(TS_FIXTURE_DIR)) {
    it(`${filename} — PHP and TS copies are byte-identical`, () => {
      const phpBytes = readFileSync(resolve(PHP_FIXTURE_DIR, filename));
      const tsBytes = readFileSync(resolve(TS_FIXTURE_DIR, filename));

      expect(
        tsBytes.equals(phpBytes),
        `Fixture '${filename}' has drifted between the PHP and TS directories.\n` +
          'The two copies must be byte-identical. To fix:\n' +
          '  • If the PHP copy is authoritative, run:\n' +
          `      cp apps/api/tests/Fixtures/Fiscal/v3-golden-hashes/${filename} \\\n` +
          `         apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes/${filename}\n` +
          '  • Then update EXPECTED_HASHES in fixtureIntegrity.test.ts (both PHP and TS sides).',
      ).toBe(true);
    });
  }
});
