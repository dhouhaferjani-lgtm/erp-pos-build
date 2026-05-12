/**
 * T1.2 Codex round-2 follow-up — i18n smoke test for the
 * paymentConfigReady gate.
 *
 * Step 2.3 introduced t('pos:payment.configNotLoaded') in two places
 * (PaymentSummary's cash button title, TransactionCart's refund
 * net-footer cash button title). Without a direct i18n.t() resolution
 * check the cashier could see a raw key string ("pos:payment.configNotLoaded")
 * if the locale entry were ever removed in either en or fr — exactly
 * the bug class T1.1 round-2 surfaced. This test mirrors T1.1 round-3's
 * direct i18n.t() resolution pattern at
 * `apps/pos/src/__tests__/recoveryScreenI18n.test.tsx`.
 */
import { describe, it, expect } from 'vitest';
import i18n from '@/lib/i18n';

describe('T1.2 Step 2.3 — paymentConfigReady gate i18n keys', () => {
  it('pos:payment.configNotLoaded resolves to translated text in both en and fr', async () => {
    const KEY = 'payment.configNotLoaded';
    const NS = 'pos';

    const originalLng = i18n.language;
    try {
      for (const lng of ['en', 'fr']) {
        await i18n.changeLanguage(lng);
        const value = i18n.t(KEY, { ns: NS });
        // Resolved translations are non-empty and never equal to the
        // raw key (i18next's missing-key fallback). They never start
        // with "payment." — that prefix only appears in the key itself.
        expect(typeof value).toBe('string');
        expect(value).not.toBe('');
        expect(value).not.toBe(KEY);
        expect(value).not.toMatch(/^payment\./);
      }
    } finally {
      await i18n.changeLanguage(originalLng);
    }
  });
});
