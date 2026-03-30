import { describe, it, expect, vi } from 'vitest';

// @/lib/currency imports useAuthStore at the module level; mock it to avoid
// Tauri / store side effects in a pure unit test environment.
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import { bcadd, bcsub, bcmul, bcdiv, bccomp } from '../decimal';

describe('decimal', () => {
  it('bcadd adds two decimals', () => {
    expect(bcadd('10.50', '5.25')).toBe('15.750');
  });

  it('bcsub subtracts two decimals', () => {
    expect(bcsub('10.50', '5.25')).toBe('5.250');
  });

  it('bcmul multiplies two decimals', () => {
    expect(bcmul('10.50', '2')).toBe('21.000');
  });

  it('bcdiv divides two decimals', () => {
    expect(bcdiv('10.50', '2')).toBe('5.250');
  });

  it('bcdiv throws on division by zero', () => {
    expect(() => bcdiv('10', '0')).toThrow('Division by zero');
  });

  it('bccomp compares two decimals', () => {
    expect(bccomp('10.50', '5.25')).toBe(1);
    expect(bccomp('5.25', '10.50')).toBe(-1);
    expect(bccomp('10.50', '10.50')).toBe(0);
  });

  it('handles empty strings gracefully', () => {
    expect(bcadd('', '5.25')).toBe('5.250');
    expect(bcadd('10.50', '')).toBe('10.500');
  });

  it('respects custom scale parameter', () => {
    expect(bcadd('10.5', '5.25', 2)).toBe('15.75');
    expect(bcsub('10.5', '5.25', 2)).toBe('5.25');
  });
});
