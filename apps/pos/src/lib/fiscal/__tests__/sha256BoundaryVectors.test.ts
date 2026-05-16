/**
 * SHA-256 padding-boundary vectors for the hand-rolled SHA-256 in
 * FiscalEventCanonicalEncoder.
 *
 * Closes the Task 5 deferred P2: prove the hand-rolled implementation is
 * correct at every block-padding edge, instead of vetting the algorithm
 * line-by-line. The risk class is SHA-256's two-block transition when the
 * payload + the 0x80 padding byte + 8 length bytes spans exactly one or two
 * 512-bit (64-byte) blocks. Bugs typically hide at:
 *   - 55-byte input: last byte before 0x80 still fits in one 64-byte block
 *   - 56-57:         forces a 2-block hash (0x80 + length spill across blocks)
 *   - 63-65:         another 1->2 block transition
 *   - 119-120:       2->3 block transition (variant A)
 *   - 127-128:       2->3 block transition (variant B)
 *
 * Truth source: Node's `node:crypto.createHash('sha256')` -- the OpenSSL-backed
 * stdlib implementation. Each boundary input is hashed by both the encoder
 * and Node, and the hex digests must match byte-for-byte.
 */
import { createHash } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';

const BOUNDARY_LENGTHS = [
  0,
  1,
  32,
  55,
  56,
  57,
  63,
  64,
  65,
  119,
  120,
  121,
  127,
  128,
  192,
];

function buildAscii(length: number): string {
  // Repeating ASCII printable cycle keeps input deterministic, multibyte-free
  // (so TextEncoder produces exactly `length` bytes), and varies per-position
  // so a byte-swap bug surfaces as a hash mismatch.
  const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  let out = '';
  for (let i = 0; i < length; i += 1) {
    out += charset[i % charset.length] ?? 'x';
  }
  return out;
}

function nodeSha256Hex(input: string): string {
  return createHash('sha256').update(Buffer.from(input, 'utf8')).digest('hex');
}

describe('FiscalEventCanonicalEncoder.sha256Hex -- padding-boundary vectors', () => {
  const encoder = new FiscalEventCanonicalEncoder();

  it.each(BOUNDARY_LENGTHS)(
    'matches node:crypto sha256 for %i-byte ASCII input',
    (length) => {
      const input = buildAscii(length);
      expect(input.length).toBe(length);

      const oursHex = encoder.sha256Hex(input);
      const referenceHex = nodeSha256Hex(input);

      expect(oursHex).toMatch(/^[0-9a-f]{64}$/);
      expect(oursHex).toBe(referenceHex);
    },
  );

  it('handles the known empty-input SHA-256 vector', () => {
    // FIPS 180-4 Appendix C / the canonical "" -> e3b0... vector.
    expect(encoder.sha256Hex('')).toBe(
      'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    );
  });

  it('handles the known "abc" SHA-256 vector', () => {
    // FIPS 180-4 Appendix B.1.
    expect(encoder.sha256Hex('abc')).toBe(
      'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
    );
  });

  it('handles a 1000-byte input (multi-block torture vector)', () => {
    const input = buildAscii(1000);
    expect(encoder.sha256Hex(input)).toBe(nodeSha256Hex(input));
  });

  it('handles multibyte UTF-8 inputs without an off-by-one in the byte length', () => {
    // Decomposed form: ASCII "Cafe" + U+0301 (COMBINING ACUTE ACCENT).
    const decomposed = 'Café';
    // Precomposed form: U+00E9 LATIN SMALL LETTER E WITH ACUTE.
    const precomposed = 'Café';

    expect(encoder.sha256Hex(decomposed)).toBe(nodeSha256Hex(decomposed));
    expect(encoder.sha256Hex(precomposed)).toBe(nodeSha256Hex(precomposed));
    // Sanity: the two forms hash to different digests, proving sha256Hex
    // is byte-exact (the encoder pipeline applies NFC upstream; the
    // sha256Hex step itself must NOT silently normalize).
    expect(encoder.sha256Hex(decomposed)).not.toBe(encoder.sha256Hex(precomposed));
  });
});
