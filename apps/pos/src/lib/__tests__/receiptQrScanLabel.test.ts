import { describe, it, expect, vi } from 'vitest';

/**
 * DEV-QA-093 — the refund-lookup QR at the foot of the ticket printed with no
 * caption, so the customer had no idea what it was for. The label ships as a
 * localized `ReceiptLabels` field; the Rust template reads
 * `labels.qr_scan_label` with its own fallback.
 *
 * i18next resolves against the REAL locale bundles (not a key echo) so the
 * assertions prove the caption comes from the shipped files.
 */
const localeState = vi.hoisted(() => ({ locale: 'fr' as 'fr' | 'en' }));

vi.mock('i18next', async () => {
  const bundles: Record<string, Record<string, unknown>> = {
    fr: (await import('@/locales/fr/pos.json')).default as Record<string, unknown>,
    en: (await import('@/locales/en/pos.json')).default as Record<string, unknown>,
  };
  const resolve = (key: string): string => {
    const path = key.startsWith('pos:') ? key.slice(4) : key;
    let node: unknown = bundles[localeState.locale];
    for (const segment of path.split('.')) {
      if (typeof node !== 'object' || node === null) return key;
      node = (node as Record<string, unknown>)[segment];
    }
    return typeof node === 'string' ? node : key;
  };
  return {
    default: {
      t: (key: string, opts?: Record<string, unknown>) => {
        const value = resolve(key);
        if (opts && typeof opts.name === 'string') {
          return value.replace('{{name}}', opts.name);
        }
        return value;
      },
    },
  };
});

// decimal.ts -> currency.ts reaches the auth store at module load time.
vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

import { buildReceiptLabels } from '../buildReceiptData';

describe('buildReceiptLabels — qr_scan_label (DEV-QA-093)', () => {
  it('carries the refund-lookup QR caption from the fr locale bundle', () => {
    localeState.locale = 'fr';

    expect(buildReceiptLabels().qr_scan_label).toBe('Scanner pour retour / échange');
  });

  it('carries the English caption from the en locale bundle', () => {
    localeState.locale = 'en';

    expect(buildReceiptLabels().qr_scan_label).toBe('Scan for return / exchange');
  });

  it('never leaves the caption as the raw translation key', () => {
    localeState.locale = 'fr';
    const labels = buildReceiptLabels();

    expect(labels.qr_scan_label).toBeDefined();
    expect(labels.qr_scan_label).not.toContain('receiptLabel.');
  });
});

/**
 * r2 device recette 2026-09-18 — the printed ticket carried NO QR: this
 * tenant has no active `receipt_qr` signing key, so the server issued
 * `qr_token = null` and DEV-QA-093 had already removed the fiscal-hash QR.
 * The hash QR is back as a captioned fallback, so the caption ships too.
 */
describe('buildReceiptLabels — qr_verify_label (r2)', () => {
  it('carries the fallback-QR caption from the fr locale bundle', () => {
    localeState.locale = 'fr';

    expect(buildReceiptLabels().qr_verify_label).toBe('Vérification du ticket');
  });

  it('carries the English caption from the en locale bundle', () => {
    localeState.locale = 'en';

    expect(buildReceiptLabels().qr_verify_label).toBe('Receipt verification');
  });

  it('never leaves the caption as the raw translation key', () => {
    localeState.locale = 'fr';

    expect(buildReceiptLabels().qr_verify_label).not.toContain('receiptLabel.');
  });
});
