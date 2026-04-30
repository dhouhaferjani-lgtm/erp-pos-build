import { describe, it, expect } from 'vitest';
import { buildCanonicalPayload, type V3CanonicalInput } from '../canonicalPayload';
import * as fs from 'node:fs';
import * as path from 'node:path';

const FIXTURE_DIR = path.resolve(__dirname, '../__fixtures__/v3-golden-hashes');
const FIXTURES = [
  '01-cash-only-eur.json',
  '02-mixed-tender-tnd.json',
  '03-voucher-tender-eur.json',
  '04-stacked-vouchers-eur.json',
  '05-return-with-voucher-issuance-eur.json',
  '06-exchange-pair-eur.json',
  '07-tnd-residual.json',
];

describe('buildCanonicalPayload — TS↔PHP parity', () => {
  for (const fixtureName of FIXTURES) {
    it(`produces byte-identical canonical bytes + hash for ${fixtureName}`, async () => {
      const fixture = JSON.parse(
        fs.readFileSync(path.join(FIXTURE_DIR, fixtureName), 'utf-8'),
      );

      const canonical = await buildCanonicalPayload(fixture.input as V3CanonicalInput);
      expect(canonical).toBe(fixture.expected_canonical);

      const enc = new TextEncoder().encode(canonical);
      const buf = await crypto.subtle.digest('SHA-256', enc);
      const hex = Array.from(new Uint8Array(buf))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
      expect(hex).toBe(fixture.expected_hash);
    });
  }
});
