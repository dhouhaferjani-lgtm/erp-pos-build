import { describe, it, expect } from 'vitest';
import { canonicalJson, canonicalJsonList } from '../canonicalJson';

describe('canonicalJson — RFC 8785 / JCS', () => {
  it('encodes empty object as {}', () => {
    expect(canonicalJson({})).toBe('{}');
  });

  it('encodes empty list as []', () => {
    expect(canonicalJsonList([])).toBe('[]');
  });

  it('sorts keys lexicographically', () => {
    expect(canonicalJson({ b: 2, a: 1 })).toBe('{"a":1,"b":2}');
  });

  it('serializes null as null token', () => {
    expect(canonicalJson({ x: null })).toBe('{"x":null}');
  });

  it('passes through decimal strings without quoting', () => {
    expect(canonicalJson({ amount: '12.345' })).toBe('{"amount":"12.345"}');
  });

  it('preserves array order', () => {
    expect(canonicalJson({ x: [1, 2, 3] })).toBe('{"x":[1,2,3]}');
  });

  it('escapes unicode per JCS (raw UTF-8 for U+0080+)', () => {
    expect(canonicalJson({ name: 'café' })).toBe('{"name":"café"}');
  });

  it('throws on non-integer numbers (decimals must be strings)', () => {
    expect(() => canonicalJson({ x: 1.5 })).toThrow();
  });

  it('handles booleans', () => {
    expect(canonicalJson({ ok: true, bad: false })).toBe('{"bad":false,"ok":true}');
  });
});
