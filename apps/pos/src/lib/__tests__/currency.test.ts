import { describe, it, expect } from 'vitest';
import { formatCurrency, getCurrencyDecimals } from '../currency';

describe('formatCurrency', () => {
  it('formats EUR amounts in French locale', () => {
    const result = formatCurrency(10.5, 'EUR');
    // Should contain the number and EUR symbol
    expect(result).toContain('10');
    expect(result).toContain('50');
  });

  it('formats USD amounts in US locale', () => {
    const result = formatCurrency(25.99, 'USD');
    expect(result).toContain('$');
    expect(result).toContain('25');
  });

  it('formats GBP amounts', () => {
    const result = formatCurrency(100, 'GBP');
    expect(result).toContain('100');
  });

  it('formats TND amounts', () => {
    const result = formatCurrency(50.5, 'TND');
    expect(result).toContain('50');
  });

  it('handles string amounts', () => {
    const result = formatCurrency('42.50', 'EUR');
    expect(result).toContain('42');
  });

  it('handles NaN by formatting as 0', () => {
    const result = formatCurrency('not-a-number', 'EUR');
    expect(result).toContain('0');
  });

  it('defaults to EUR when no currency specified', () => {
    const result = formatCurrency(10);
    // Should not throw and should produce valid output
    expect(result.length).toBeGreaterThan(0);
  });

  it('uses custom decimal places', () => {
    const result = formatCurrency(10.123, 'EUR', 3);
    expect(result).toContain('123');
  });

  it('handles zero amount', () => {
    const result = formatCurrency(0, 'EUR');
    expect(result).toContain('0');
  });

  it('handles negative amounts', () => {
    const result = formatCurrency(-15.50, 'EUR');
    expect(result).toContain('15');
  });

  it('falls back gracefully for unknown currency codes', () => {
    const result = formatCurrency(100, 'XYZ');
    // Should not throw - either Intl handles it or falls back
    expect(result.length).toBeGreaterThan(0);
  });

  it('handles large amounts', () => {
    const result = formatCurrency(1000000, 'EUR');
    expect(result).toContain('000');
  });
});

describe('getCurrencyDecimals — ISO 4217 display precision', () => {
  it('returns 2 for EUR (standard two-decimal currency)', () => {
    expect(getCurrencyDecimals('EUR')).toBe(2);
  });

  it('returns 2 for USD', () => {
    expect(getCurrencyDecimals('USD')).toBe(2);
  });

  it('returns 2 for GBP', () => {
    expect(getCurrencyDecimals('GBP')).toBe(2);
  });

  it('returns 3 for TND (Tunisian Dinar)', () => {
    expect(getCurrencyDecimals('TND')).toBe(3);
  });

  it('returns 3 for KWD (Kuwaiti Dinar)', () => {
    expect(getCurrencyDecimals('KWD')).toBe(3);
  });

  it('returns 3 for BHD (Bahraini Dinar)', () => {
    expect(getCurrencyDecimals('BHD')).toBe(3);
  });

  it('returns 0 for JPY (Japanese Yen)', () => {
    expect(getCurrencyDecimals('JPY')).toBe(0);
  });

  it('returns 0 for KRW (Korean Won)', () => {
    expect(getCurrencyDecimals('KRW')).toBe(0);
  });

  it('returns 2 as default fallback for unknown currency code', () => {
    expect(getCurrencyDecimals('XYZ')).toBe(2);
  });

  it('returns 2 as default fallback for empty string', () => {
    expect(getCurrencyDecimals('')).toBe(2);
  });
});
