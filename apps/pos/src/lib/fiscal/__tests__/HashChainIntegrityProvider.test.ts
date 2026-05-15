import { describe, expect, it } from 'vitest';
import { HashChainIntegrityProvider } from '../HashChainIntegrityProvider';

describe('HashChainIntegrityProvider (device)', () => {
  const provider = new HashChainIntegrityProvider();

  it('version, deterministic hash, verify true/false', () => {
    expect(provider.version()).toBe('hash-chain-integrity-v1');

    const bytes = '{"business_date":"2026-05-14"}';
    const hash = provider.computeHash(bytes);

    expect(hash).toMatch(/^[0-9a-f]{64}$/);
    expect(provider.verify(bytes, hash)).toBe(true);
    expect(provider.verify(bytes, '0'.repeat(64))).toBe(false);
  });
});
