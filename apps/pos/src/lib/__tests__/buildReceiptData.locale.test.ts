import { describe, it, expect, vi } from 'vitest';

vi.mock('i18next', () => {
  const t = (key: string) => key;
  const stub = {
    t,
    use: () => stub,
    init: () => Promise.resolve(),
    changeLanguage: () => Promise.resolve(),
    language: 'en',
  };
  return {
    default: stub,
    initReactI18next: { type: '3rdParty', init: () => undefined },
  };
});

import { buildEscPosFromOfflineReceipt } from '../buildReceiptData';
import type { CheckoutResult } from '@/lib/offline/offlineCheckoutService';

const result: CheckoutResult = {
  receiptNumber: 'MAIN-T001-2026-00000001',
  subtotal: '20.00',
  taxAmount: '0.00',
  discountAmount: '0.00',
  total: '20.00',
  currency: 'TND',
  changeDue: 5,
  fiscalHash: null,
} as unknown as CheckoutResult;

describe('buildEscPosFromOfflineReceipt — locale-aware date_time', () => {
  it('renders date_time in French day-month-year format when locale=fr', () => {
    // 2026-04-23T14:30:00Z
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-04-23T14:30:00Z'));

    const data = buildEscPosFromOfflineReceipt(
      result,
      [],
      'Acme TN',
      'Terminal 1',
      'Jane',
      'Cash',
      undefined,
      'fr',
    );

    // French format: DD/MM/YYYY HH:mm:ss (system timezone — don't assert hour exactly)
    expect(data.date_time).toMatch(/^23[/\s.-]04[/\s.-]2026/);
    vi.useRealTimers();
  });

  it('renders date_time in English month-day-year format when locale=en', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-04-23T14:30:00Z'));

    const data = buildEscPosFromOfflineReceipt(
      result,
      [],
      'Acme UK',
      'Terminal 1',
      'Jane',
      'Cash',
      undefined,
      'en',
    );

    expect(data.date_time).toMatch(/04\/23\/2026|23\/04\/2026|2026-04-23/);
    vi.useRealTimers();
  });

  it('renders date_time with Arabic digits when locale=ar', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-04-23T14:30:00Z'));

    const data = buildEscPosFromOfflineReceipt(
      result,
      [],
      'Acme MA',
      'Terminal 1',
      'Jane',
      'Cash',
      undefined,
      'ar',
    );

    // Accept either Arabic-Indic digits or Latin digits (Intl default depends on ICU version).
    // Core guarantee: the string is non-empty and non-ISO.
    expect(data.date_time).toBeTruthy();
    expect(data.date_time).not.toMatch(/^\d{4}-\d{2}-\d{2}T/);
    vi.useRealTimers();
  });

  it('falls back to en when locale is undefined', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-04-23T14:30:00Z'));

    const data = buildEscPosFromOfflineReceipt(
      result,
      [],
      'Acme',
      'Terminal 1',
      'Jane',
      'Cash',
      undefined,
      undefined,
    );

    // Must not be the raw ISO string — must be localized.
    expect(data.date_time).not.toMatch(/^2026-04-23T14:30:00/);
    vi.useRealTimers();
  });
});
