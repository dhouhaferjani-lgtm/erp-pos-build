import { describe, expect, it } from 'vitest';
import { formatPercent } from './format';

describe('formatPercent', () => {
  it('formats percentage strings without float coercion', () => {
    expect(formatPercent('19.0000')).toBe('19%');
    expect(formatPercent('7.5000')).toBe('7.5%');
    expect(formatPercent('19.1234')).toBe('19.12%');
  });

  it('formats number inputs used by POS report DTOs', () => {
    expect(formatPercent(19)).toBe('19%');
    expect(formatPercent(19.1234)).toBe('19.12%');
  });

  it('rounds percentage strings at the requested display scale', () => {
    expect(formatPercent('19.125', { maximumFractionDigits: 2 })).toBe('19.13%');
    expect(formatPercent('99.999', { maximumFractionDigits: 2 })).toBe('100%');
    expect(formatPercent('-0.004', { maximumFractionDigits: 2 })).toBe('0%');
  });
});
