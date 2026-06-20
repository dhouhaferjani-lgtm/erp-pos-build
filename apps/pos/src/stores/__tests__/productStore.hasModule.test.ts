import { describe, it, expect } from 'vitest';
import { hasModule } from '@/stores/productStore';
import type { CompanyConfig } from '@/types/companyConfig';

describe('hasModule', () => {
  it('returns true/false for a normal sequential array payload', () => {
    const config = { all_enabled_modules: ['POS', 'Menu'] } as CompanyConfig;
    expect(hasModule(config, 'Menu')).toBe(true);
    expect(hasModule(config, 'Loyalty')).toBe(false);
  });

  it('returns false for a null config', () => {
    expect(hasModule(null, 'Menu')).toBe(false);
  });

  it('returns false for an empty module list', () => {
    const config = { all_enabled_modules: [] } as CompanyConfig;
    expect(hasModule(config, 'Menu')).toBe(false);
  });

  it('normalizes an object-shaped payload (PHP gap-key serialization) instead of throwing', () => {
    // A PHP assoc-array with non-sequential keys serializes as a JSON object,
    // not an array. hasModule must coerce it so module gating never throws and
    // white-screens the POS.
    const objectShapedConfig = {
      all_enabled_modules: { 0: 'Identity', 8: 'BatchExpiry', 11: 'Loyalty' } as unknown as string[],
    } as CompanyConfig;
    expect(hasModule(objectShapedConfig, 'BatchExpiry')).toBe(true);
    expect(hasModule(objectShapedConfig, 'Loyalty')).toBe(true);
    expect(hasModule(objectShapedConfig, 'Nonexistent')).toBe(false);
  });
});
