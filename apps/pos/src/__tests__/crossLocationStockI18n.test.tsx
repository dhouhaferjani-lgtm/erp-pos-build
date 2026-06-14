/**
 * Task F9 — i18n smoke test for the cross-location stock section.
 *
 * CrossLocationStockSection uses `t('crossLocationStock.*')` (namespace 'pos').
 * ProductCard uses `t('products.viewDetails')` (namespace 'pos').
 * Per established convention (syncIndicatorI18n.test.tsx), we resolve keys
 * directly via the shared i18n instance in both locales and assert that the
 * resolved value is neither empty nor the raw fallback key string.
 */
import { describe, it, expect } from 'vitest';
import i18n from '@/lib/i18n';

const CROSS_LOC_KEYS = [
  'title',
  'variant',
  'location',
  'onHand',
  'incoming',
  'total',
  'asOf',
  'refresh',
  'loading',
  'offlineNoCache',
];

describe('F9 — crossLocationStock i18n keys', () => {
  for (const lng of ['en', 'fr']) {
    describe(`locale: ${lng}`, () => {
      for (const k of CROSS_LOC_KEYS) {
        it(`crossLocationStock.${k} resolves`, async () => {
          const originalLng = i18n.language;
          try {
            await i18n.changeLanguage(lng);
            const value = i18n.t(`crossLocationStock.${k}`, { ns: 'pos' });
            expect(typeof value).toBe('string');
            expect(value).not.toBe('');
            expect(value).not.toBe(`crossLocationStock.${k}`);
            expect(value).not.toMatch(/^crossLocationStock\./);
          } finally {
            await i18n.changeLanguage(originalLng);
          }
        });
      }

      it('products.viewDetails resolves', async () => {
        const originalLng = i18n.language;
        try {
          await i18n.changeLanguage(lng);
          const value = i18n.t('products.viewDetails', { ns: 'pos' });
          expect(typeof value).toBe('string');
          expect(value).not.toBe('');
          expect(value).not.toBe('products.viewDetails');
          expect(value).not.toMatch(/^products\./);
        } finally {
          await i18n.changeLanguage(originalLng);
        }
      });
    });
  }
});
