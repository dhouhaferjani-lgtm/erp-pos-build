/**
 * Phase H Task 50 — focused integration test for the scan-dispatch flow.
 *
 * The HomePage scan handler composes:
 *   dispatchScan → refundFlowStore.setPendingScanResult → ReceiptScanConfirmationSheet
 *     → onAccept → refundFlowStore.acceptPendingScan → (Task 52 hydrates cart)
 *     → onCancel → refundFlowStore.setPendingScanResult(null) → cart unchanged
 *
 * This test wires those pieces together without rendering the entire
 * HomePage tree (which depends on a half-dozen network-touching stores).
 * The acceptance contract that matters here:
 *
 *   1. Scanned receipt token → cart still empty until cashier accepts.
 *   2. NO API or HTTP fetch is invoked along the dispatch+sheet path.
 *   3. Cancel keeps the cart empty AND emits no accepted-token event.
 *   4. Accept emits the typed ReceiptTokenAccepted event AND leaves the
 *      cart empty (Task 52 will hydrate it on a separate path).
 *   5. A malformed token falls through to the existing product-barcode
 *      handler.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, fireEvent } from '@testing-library/react';

vi.mock('@/lib/db', () => ({
  queryAll: vi.fn(),
  queryOne: vi.fn(),
  execute: vi.fn().mockResolvedValue(undefined),
  getDatabase: vi.fn(),
}));
vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));
vi.mock('@tauri-apps/plugin-http', () => ({
  fetch: vi.fn(),
}));

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'receiptScan.title': 'Sale receipt detected',
        'receiptScan.body': `This is a sale receipt from ${String(opts?.date ?? '')}. Start a refund or exchange?`,
        'receiptScan.cancel': 'Cancel',
        'receiptScan.startRefund': 'Start refund',
        'receiptScan.dispatchError': "Couldn't read the local receipt index. Falling back to product lookup.",
      };
      return map[key] ?? key;
    },
    i18n: { language: 'en' },
  }),
}));

import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { queryOne } from '@/lib/db';
import { apiGet, apiPost } from '@/lib/api';
import { fetch as httpFetch } from '@tauri-apps/plugin-http';
import { dispatchScan } from '../dispatcher';
import { useRefundFlowStore } from '@/stores/refundFlowStore';
import { useCartStore } from '@/stores/cartStore';
import { ReceiptScanConfirmationSheet } from '@/components/pos/ReceiptScanConfirmationSheet';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

const TERMINAL_ID = 'term-1';

const RECEIPT_INDEX_ROW: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
  receipt_number: 'R-0001',
  terminal_id: TERMINAL_ID,
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  partner_id: null,
  synced_at: '2026-04-28T09:00:05+00:00',
};

const db = {} as import('@tauri-apps/plugin-sql').default;

beforeEach(() => {
  vi.clearAllMocks();
  useRefundFlowStore.setState({
    pendingScanResult: null,
    acceptedReceiptToken: null,
  });
  useCartStore.setState({ items: [], transactionDiscount: undefined });
});

/**
 * Mini-shell that mirrors HomePage's scan-handler wiring without dragging in
 * the full page. The fallthroughCallback represents HomePage's existing
 * product-barcode handler — we assert it is NOT invoked when a receipt
 * token resolves, AND that it IS invoked when the dispatcher returns
 * `'fallthrough'`.
 *
 * The shell ALSO mirrors HomePage's try/catch around `dispatchScan`: on a
 * thrown error, it surfaces a translated `scanMessage` toast and then still
 * falls through to product lookup. The "error toast on dispatcher throw"
 * test below relies on this mirror to validate the user-facing path without
 * dragging in the full HomePage tree.
 */
function ScanShell({
  scannedToken,
  fallthroughCallback,
}: {
  scannedToken: string | null;
  fallthroughCallback: (barcode: string) => void;
}) {
  const { t } = useTranslation('pos');
  const pendingScanResult = useRefundFlowStore((s) => s.pendingScanResult);
  const setPendingScanResult = useRefundFlowStore((s) => s.setPendingScanResult);
  const acceptPendingScan = useRefundFlowStore((s) => s.acceptPendingScan);
  const [scanMessage, setScanMessage] = useState<{ text: string; type: 'error' } | null>(null);

  // Imitate HomePage's wrapped handleBarcodeScan: dispatch first, fallthrough
  // to the existing product handler only when dispatcher returns fallthrough.
  // On error, surface a translated toast then still fall through (matching
  // the HomePage pattern — the cashier never gets stuck).
  const runScan = async () => {
    if (scannedToken === null) return;
    try {
      const result = await dispatchScan({
        token: scannedToken,
        db,
        terminalId: TERMINAL_ID,
      });
      if (result.kind === 'receipt-token') {
        setPendingScanResult(result.entry);
        return;
      }
      fallthroughCallback(scannedToken);
    } catch {
      setScanMessage({ text: t('receiptScan.dispatchError'), type: 'error' });
      fallthroughCallback(scannedToken);
    }
  };

  return (
    <div>
      <button type="button" onClick={() => void runScan()}>fire-scan</button>
      {scanMessage && <div role="alert">{scanMessage.text}</div>}
      <ReceiptScanConfirmationSheet
        entry={pendingScanResult}
        onCancel={() => setPendingScanResult(null)}
        onAccept={() => acceptPendingScan()}
      />
    </div>
  );
}

describe('Phase H Task 50 — scan dispatch flow integration', () => {
  it('Acceptance 1+2: scanned receipt token → sheet shown, cart unchanged, NO network calls', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called from scan dispatch path');
    });

    render(
      <ScanShell
        scannedToken="1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob"
        fallthroughCallback={vi.fn()}
      />,
    );

    await act(async () => {
      fireEvent.click(screen.getByText('fire-scan'));
    });

    // Sheet appears
    expect(screen.getByText('Sale receipt detected')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Start refund' })).toBeInTheDocument();

    // Cart still empty — never silently mutated
    expect(useCartStore.getState().items).toEqual([]);

    // Acceptance 2: NO API or HTTP fetch
    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });

  it('Acceptance 3: Cancel closes the sheet, leaves cart empty, emits no event', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    render(
      <ScanShell
        scannedToken="1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob"
        fallthroughCallback={vi.fn()}
      />,
    );

    await act(async () => {
      fireEvent.click(screen.getByText('fire-scan'));
    });

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.queryByText('Sale receipt detected')).not.toBeInTheDocument();
    expect(useCartStore.getState().items).toEqual([]);
    expect(useRefundFlowStore.getState().acceptedReceiptToken).toBeNull();
  });

  it('Acceptance 4: Start refund emits ReceiptTokenAccepted with the right payload, cart still empty', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce(RECEIPT_INDEX_ROW);

    render(
      <ScanShell
        scannedToken="1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob"
        fallthroughCallback={vi.fn()}
      />,
    );

    await act(async () => {
      fireEvent.click(screen.getByText('fire-scan'));
    });

    fireEvent.click(screen.getByRole('button', { name: 'Start refund' }));

    // Cart must remain unchanged at this point — Task 52 hydrates it.
    expect(useCartStore.getState().items).toEqual([]);

    // Typed event emitted on the Zustand slot
    expect(useRefundFlowStore.getState().acceptedReceiptToken).toEqual({
      receiptUuid: RECEIPT_INDEX_ROW.receipt_uuid,
      receiptNumber: RECEIPT_INDEX_ROW.receipt_number,
      receiptToken: RECEIPT_INDEX_ROW.qr_token,
      postedAt: RECEIPT_INDEX_ROW.posted_at,
      total: RECEIPT_INDEX_ROW.total,
      currency: RECEIPT_INDEX_ROW.currency,
    });
    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
  });

  it('Fallthrough: malformed token routes to the existing product-barcode handler, sheet stays closed', async () => {
    const fallthrough = vi.fn();

    render(
      <ScanShell
        scannedToken="not-a-token"
        fallthroughCallback={fallthrough}
      />,
    );

    await act(async () => {
      fireEvent.click(screen.getByText('fire-scan'));
    });

    expect(fallthrough).toHaveBeenCalledWith('not-a-token');
    expect(screen.queryByText('Sale receipt detected')).not.toBeInTheDocument();
    expect(useRefundFlowStore.getState().pendingScanResult).toBeNull();
  });

  it('Dispatcher throw: surfaces translated error toast AND still falls through to product lookup', async () => {
    // Force the local-index lookup to throw — simulates a SQLite failure
    // mid-scan (corrupt DB, locked file, etc.). The cashier MUST see
    // feedback rather than a silent miss.
    vi.mocked(queryOne).mockRejectedValueOnce(new Error('sqlite: database is locked'));
    const fallthrough = vi.fn();

    render(
      <ScanShell
        scannedToken="1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob"
        fallthroughCallback={fallthrough}
      />,
    );

    await act(async () => {
      fireEvent.click(screen.getByText('fire-scan'));
    });

    // Toast appears with the translated dispatchError message
    expect(
      screen.getByRole('alert'),
    ).toHaveTextContent(/Couldn't read the local receipt index/);
    // Cashier is never stuck — fallthrough still runs
    expect(fallthrough).toHaveBeenCalledWith(
      '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
    );
    // Sheet stays closed — the dispatch result was never trusted
    expect(screen.queryByText('Sale receipt detected')).not.toBeInTheDocument();
  });

  it('Fallthrough: receipt scanned at a different terminal routes to product lookup (Codex finding G)', async () => {
    vi.mocked(queryOne).mockResolvedValueOnce({
      ...RECEIPT_INDEX_ROW,
      terminal_id: 'other-terminal',
    });
    const fallthrough = vi.fn();

    render(
      <ScanShell
        scannedToken="1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob"
        fallthroughCallback={fallthrough}
      />,
    );

    await act(async () => {
      fireEvent.click(screen.getByText('fire-scan'));
    });

    expect(fallthrough).toHaveBeenCalledWith(
      '1:keyabc:550e8400-e29b-41d4-a716-446655440000:macblob',
    );
    expect(screen.queryByText('Sale receipt detected')).not.toBeInTheDocument();
  });
});
