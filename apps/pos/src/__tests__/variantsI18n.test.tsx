/**
 * Task FV6 — i18n smoke test for variants.offlineNoCache.
 *
 * VariantPickerModal uses `t('variants.offlineNoCache')` (namespace 'pos').
 * Per established convention (crossLocationStockI18n.test.tsx), we resolve
 * keys directly via the shared i18n instance in both locales and assert that
 * the resolved value is neither empty nor the raw fallback key string.
 */
import { describe, it, expect } from 'vitest';
import i18n from '@/lib/i18n';

describe('variants offline i18n', () => {
  for (const lng of ['en', 'fr']) {
    it(`${lng}: variants.offlineNoCache resolves`, async () => {
      const originalLng = i18n.language;
      try {
        await i18n.changeLanguage(lng);
        const v = i18n.t('variants.offlineNoCache', { ns: 'pos' });
        expect(typeof v).toBe('string');
        expect(v).not.toBe('');
        expect(v).not.toBe('variants.offlineNoCache');
        expect(v).not.toMatch(/^variants\./);
      } finally {
        await i18n.changeLanguage(originalLng);
      }
    });
  }
});
