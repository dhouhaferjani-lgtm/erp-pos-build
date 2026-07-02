import { describe, expect, it } from 'vitest';
import { getAccessLevel, hasManagerAccess, isManagerRole } from './roles';

describe('POS access hierarchy', () => {
  it('treats owner as higher access than manager', () => {
    expect(getAccessLevel(['cashier'])).toBe(0);
    expect(getAccessLevel(['manager'])).toBe(1);
    expect(getAccessLevel(['owner'])).toBe(2);
  });

  it('keeps owner manager-compatible for existing gates', () => {
    expect(isManagerRole(['owner'])).toBe(true);
  });

  it('allows an authenticated owner user to access manager surfaces with a cashier operator PIN', () => {
    expect(hasManagerAccess(['cashier'], ['owner'])).toBe(true);
  });

  it('denies manager surfaces to a cashier operator and cashier user', () => {
    expect(hasManagerAccess(['cashier'], ['cashier'])).toBe(false);
  });
});
