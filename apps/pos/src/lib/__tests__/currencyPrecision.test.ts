import { describe, expect, it, vi } from 'vitest';

// @/lib/decimal imports @/lib/currency, which imports useAuthStore at module
// load time. Keep this regression guard isolated from store/Tauri side effects.
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import { bcadd, bcformat, bcmul, bcsub } from '../decimal';
import { getCurrencyDecimals } from '../currency';

describe('TND currency precision (T1.6 smoke)', () => {
  it('uses 3 decimal places for Tunisian dinar', () => {
    expect(getCurrencyDecimals('TND')).toBe(3);
    expect(bcformat('1.5', getCurrencyDecimals('TND'))).toBe('1.500');
  });

  it('adds two TND values without precision loss', () => {
    const sum = bcadd('1.234', '5.678', getCurrencyDecimals('TND'));

    expect(sum).toBe('6.912');
  });

  it('subtracts TND values exactly without float drift', () => {
    const diff = bcsub('10.000', '0.001', getCurrencyDecimals('TND'));

    expect(diff).toBe('9.999');
  });

  it('multiplies quantity by unit price at 3-decimal scale', () => {
    const lineTotal = bcmul('3', '0.999', getCurrencyDecimals('TND'));

    expect(lineTotal).toBe('2.997');
  });
});
