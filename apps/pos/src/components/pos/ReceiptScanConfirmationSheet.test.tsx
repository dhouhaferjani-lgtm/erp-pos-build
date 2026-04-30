import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ReceiptScanConfirmationSheet } from './ReceiptScanConfirmationSheet';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'receiptScan.title': 'Sale receipt detected',
        'receiptScan.body': `This is a sale receipt from ${String(opts?.date ?? '')}. Start a refund or exchange?`,
        'receiptScan.cancel': 'Cancel',
        'receiptScan.startRefund': 'Start refund',
      };
      return map[key] ?? key;
    },
    i18n: { language: 'en' },
  }),
}));

// Assert: rendering this sheet must NOT touch the network at all.
vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));
vi.mock('@tauri-apps/plugin-http', () => ({
  fetch: vi.fn(),
}));

import { apiGet, apiPost } from '@/lib/api';
import { fetch as httpFetch } from '@tauri-apps/plugin-http';

const ENTRY: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
  receipt_number: 'R-0001',
  terminal_id: 'term-1',
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  partner_id: null,
  synced_at: '2026-04-28T09:00:05+00:00',
};

beforeEach(() => {
  vi.clearAllMocks();
});

describe('ReceiptScanConfirmationSheet', () => {
  it('renders nothing when entry is null', () => {
    const { container } = render(
      <ReceiptScanConfirmationSheet
        entry={null}
        onCancel={() => {}}
        onAccept={() => {}}
      />,
    );
    expect(container.textContent).toBe('');
  });

  it('renders the title, body (with the receipt date), and both buttons when entry is provided', () => {
    render(
      <ReceiptScanConfirmationSheet
        entry={ENTRY}
        onCancel={() => {}}
        onAccept={() => {}}
      />,
    );

    expect(screen.getByText('Sale receipt detected')).toBeInTheDocument();
    // Body interpolates the receipt's posted_at — assert *some* date string
    // appears (locale-formatted from posted_at).
    expect(screen.getByText(/This is a sale receipt from .+\. Start a refund or exchange\?/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Start refund' })).toBeInTheDocument();
  });

  it('invokes onCancel when Cancel is clicked', () => {
    const onCancel = vi.fn();
    const onAccept = vi.fn();
    render(
      <ReceiptScanConfirmationSheet
        entry={ENTRY}
        onCancel={onCancel}
        onAccept={onAccept}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(onCancel).toHaveBeenCalledTimes(1);
    expect(onAccept).not.toHaveBeenCalled();
  });

  it('invokes onAccept when Start refund is clicked', () => {
    const onCancel = vi.fn();
    const onAccept = vi.fn();
    render(
      <ReceiptScanConfirmationSheet
        entry={ENTRY}
        onCancel={onCancel}
        onAccept={onAccept}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Start refund' }));
    expect(onAccept).toHaveBeenCalledTimes(1);
    expect(onCancel).not.toHaveBeenCalled();
  });

  it('does NOT touch the network during render or interaction', () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called from confirmation sheet');
    });
    const onCancel = vi.fn();
    const onAccept = vi.fn();

    render(
      <ReceiptScanConfirmationSheet
        entry={ENTRY}
        onCancel={onCancel}
        onAccept={onAccept}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Start refund' }));

    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });
});
