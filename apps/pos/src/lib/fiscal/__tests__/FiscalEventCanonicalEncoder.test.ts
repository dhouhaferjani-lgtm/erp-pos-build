import { describe, expect, it } from 'vitest';
import goldenVectors from '../../../../../api/tests/Fixtures/Fiscal/canonical-golden-vectors.json';
import { FiscalEventCanonicalEncoder } from '../FiscalEventCanonicalEncoder';

interface GoldenVector {
  name: string;
  payload_dto_input: unknown;
  expected_canonical_string: string;
  expected_sha256_hex: string;
}

describe('FiscalEventCanonicalEncoder', () => {
  const encoder = new FiscalEventCanonicalEncoder();

  it.each(goldenVectors as GoldenVector[])(
    'reproduces canonical string + hash for $name',
    (vector) => {
      const canonical = encoder.encode(vector.payload_dto_input);

      expect(canonical).toBe(vector.expected_canonical_string);
      expect(encoder.sha256Hex(canonical)).toBe(vector.expected_sha256_hex);
    },
  );

  it('sorts object keys, preserves array order, rejects floats, formats money as decimal strings', () => {
    const out = encoder.encode({
      b: 2,
      a: 1,
      payload: { lines: [{ z: 1 }, { a: 1 }], total: '0.000' },
    });

    expect(out.indexOf('"a"')).toBeLessThan(out.indexOf('"b"'));
    expect(out).toContain('"lines":[{"z":1},{"a":1}]');
    expect(() => encoder.encode({ total: 1.5 })).toThrow(/Non-integer/);
    expect(out).toContain('"total":"0.000"');
  });

  it('strips U+2028 / U+2029 and applies NFC normalization at the producer', () => {
    const out = encoder.encode({ note: 'Cafe\u0301 a b c' });

    expect(out).toContain('Café abc');
    expect(out).not.toContain(' ');
    expect(out).not.toContain(' ');
  });
});
