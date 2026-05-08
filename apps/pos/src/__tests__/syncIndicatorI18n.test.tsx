/**
 * T1.3 Step 4.3 — i18n smoke test for the sync indicator's degraded
 * tooltip.
 *
 * The amber-dot tooltip uses `t('pos:sync.degradedTitle')`. Per T1.1
 * round-2 + T1.2 round-2 lessons (`recoveryScreenI18n.test.tsx` and
 * `paymentConfigReadyI18n.test.tsx`), every new t() key must have a
 * corresponding entry in BOTH `en/pos.json` and `fr/pos.json` with
 * translated values, or the cashier sees raw key strings rendered to
 * the UI. This direct `i18n.t()` resolution check would fail the
 * suite if either locale's entry is removed.
 */
import { describe, it, expect } from 'vitest';
import i18n from '@/lib/i18n';

describe('T1.3 Step 4.3 — sync indicator i18n keys', () => {
  it('pos:sync.degradedTitle resolves to translated text in both en and fr', async () => {
    const KEY = 'sync.degradedTitle';
    const NS = 'pos';

    const originalLng = i18n.language;
    try {
      for (const lng of ['en', 'fr']) {
        await i18n.changeLanguage(lng);
        const value = i18n.t(KEY, { ns: NS });
        // Resolved translations are non-empty and never equal to the
        // raw key (i18next's missing-key fallback). They never start
        // with "sync." — that prefix only appears in the key itself.
        expect(typeof value).toBe('string');
        expect(value).not.toBe('');
        expect(value).not.toBe(KEY);
        expect(value).not.toMatch(/^sync\./);
      }
    } finally {
      await i18n.changeLanguage(originalLng);
    }
  });
});
