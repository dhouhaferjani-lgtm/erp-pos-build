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
  // Fixture-08 (Codex review B2): payment-instrument fields bound into the
  // canonical hash. Asserts the TS canonicalizer matches the post-B2 PHP
  // builder byte-for-byte when `instrument_type` / `instrument_serial` are
  // populated — the proof obligation for B1 (POS offline v3 cutover).
  '08-store-voucher-binding-eur.json',
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

  /**
   * Codex review B3 (2026-04-30): proves that mutating just the
   * `instrument_serial` on fixture-08's payments segment changes the
   * canonical bytes (and therefore the hash). This is the parity loop's
   * tamper-evidence proof — without it, B3's wiring could silently bind
   * the wrong serial and the existing fixture round-trip would still pass.
   */
  it('mutating instrument_serial changes the canonical bytes vs. fixture-08', async () => {
    const fixture08 = JSON.parse(
      fs.readFileSync(path.join(FIXTURE_DIR, '08-store-voucher-binding-eur.json'), 'utf-8'),
    ) as { input: V3CanonicalInput; expected_hash: string };

    // Sanity: fixture-08 hash unchanged.
    const baselineCanonical = await buildCanonicalPayload(fixture08.input);
    const baselineEnc = new TextEncoder().encode(baselineCanonical);
    const baselineBuf = await crypto.subtle.digest('SHA-256', baselineEnc);
    const baselineHex = Array.from(new Uint8Array(baselineBuf))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');
    expect(baselineHex).toBe(fixture08.expected_hash);

    // Now mutate the voucher row's serial. The hash must change.
    const mutated: V3CanonicalInput = {
      ...fixture08.input,
      payments: fixture08.input.payments.map((p) =>
        p.instrument_type === 'store_voucher'
          ? { ...p, instrument_serial: 'SVC-2099-9999' }
          : p,
      ),
    };
    const mutatedCanonical = await buildCanonicalPayload(mutated);
    const mutatedEnc = new TextEncoder().encode(mutatedCanonical);
    const mutatedBuf = await crypto.subtle.digest('SHA-256', mutatedEnc);
    const mutatedHex = Array.from(new Uint8Array(mutatedBuf))
      .map((b) => b.toString(16).padStart(2, '0'))
      .join('');

    expect(mutatedCanonical).not.toBe(baselineCanonical);
    expect(mutatedHex).not.toBe(baselineHex);
  });
});
