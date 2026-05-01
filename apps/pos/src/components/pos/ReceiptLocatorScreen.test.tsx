/**
 * ReceiptLocatorScreen — Vitest test suite (Phase H Task 51, M2-UI fix)
 *
 * Critical invariants:
 *   - NO network call may be issued by any flow exercised here. apiGet,
 *     apiPost, @tauri-apps/plugin-http.fetch, and globalThis.fetch are mocked
 *     and asserted not-called across every test.
 *   - The "Find by customer" tab MUST NOT exist. Phase 2 will rebuild it with
 *     proper identifier resolution (phone / email / loyalty mirror).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import type { LocalReceiptQrIndexEntry } from '@/lib/offline/voucherRepository';

// ─── No-fetch contract: mock every network path ───────────────────────────────

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  getErrorMessage: vi.fn((e: unknown) => String(e)),
}));

vi.mock('@tauri-apps/plugin-http', () => ({
  fetch: vi.fn(),
}));

// ─── Mock SQLite DB via voucherRepository (local-only helpers) ───────────────

vi.mock('@/lib/offline/voucherRepository', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/offline/voucherRepository')>();
  return {
    ...actual,
    findReceiptByNumber: vi.fn(),
  };
});

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

// ─── Mock stores ─────────────────────────────────────────────────────────────

// The store mock must expose `.getState` so ReceiptLocatorScreen can call it.
// Variables referenced inside vi.mock factories must be declared with vi.fn()
// directly inside the factory — they cannot reference outer const declarations
// because vi.mock is hoisted above all variable declarations.
vi.mock('@/stores/refundFlowStore', () => {
  const setPendingScanResult = vi.fn();
  const acceptPendingScan = vi.fn();

  const storeState = {
    pendingScanResult: null,
    acceptedReceiptToken: null,
    setPendingScanResult,
    acceptPendingScan,
    consumeAcceptedReceiptToken: vi.fn(),
    clearAccepted: vi.fn(),
  };

  const useRefundFlowStore = vi.fn(
    (selector: (s: typeof storeState) => unknown) => selector(storeState),
  ) as unknown as {
    (selector: (s: typeof storeState) => unknown): unknown;
    getState: () => typeof storeState;
  };
  useRefundFlowStore.getState = () => storeState;

  return { useRefundFlowStore };
});

// After the mock is in place, we obtain references to the mocked fns via getState.
// These are set in beforeEach so they always reflect the current mock state.
// eslint-disable-next-line prefer-const
let mockSetPendingScanResult = vi.fn();
// eslint-disable-next-line prefer-const
let mockAcceptPendingScan = vi.fn();

vi.mock('@/stores/operatorStore', () => ({
  useOperatorStore: vi.fn(),
}));

vi.mock('@/stores/terminalStore', () => ({
  useTerminalStore: vi.fn(),
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: vi.fn(),
}));

// ─── react-i18next mock ──────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string, _opts?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'receiptLocator.title': 'Returns / Exchange',
        'receiptLocator.entryButton': 'Returns / Exchange',
        'receiptLocator.scanHint': 'Enter the receipt number or scan the QR code.',
        'receiptLocator.numberPlaceholder': 'Receipt number',
        'receiptLocator.search': 'Search',
        'receiptLocator.searching': 'Searching...',
        'receiptLocator.found': 'Receipt found',
        'receiptLocator.notFound': 'No receipt found for that number.',
        'receiptLocator.wrongTerminal': 'This receipt belongs to a different terminal.',
        'receiptLocator.refundThis': 'Refund this',
      };
      return map[key] ?? key;
    },
    i18n: { language: 'en' },
  }),
}));

// ─── Test fixtures ────────────────────────────────────────────────────────────

const TERMINAL_ENTRY: LocalReceiptQrIndexEntry = {
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440000',
  qr_token: '1:kid:550e8400-e29b-41d4-a716-446655440000:mac',
  receipt_number: 'R-0001',
  terminal_id: 'term-1',
  posted_at: '2026-04-28T09:00:00+00:00',
  total: '12500',
  currency: 'EUR',
  partner_id: null,
  synced_at: '2026-04-28T09:00:05+00:00',
};

const OTHER_TERMINAL_ENTRY: LocalReceiptQrIndexEntry = {
  ...TERMINAL_ENTRY,
  receipt_uuid: '550e8400-e29b-41d4-a716-446655440099',
  receipt_number: 'R-0002',
  terminal_id: 'term-OTHER',
};

// ─── Import mocked modules (AFTER vi.mock declarations) ───────────────────────

import { ReceiptLocatorScreen } from './ReceiptLocatorScreen';
import { apiGet, apiPost } from '@/lib/api';
import { fetch as httpFetch } from '@tauri-apps/plugin-http';
import { findReceiptByNumber } from '@/lib/offline/voucherRepository';
import { useOperatorStore } from '@/stores/operatorStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { useAuthStore } from '@/stores/authStore';
import { useRefundFlowStore } from '@/stores/refundFlowStore';

// ─── Store setup helper ───────────────────────────────────────────────────────

function setupStores() {
  // Cast to unknown first to bypass strict store-selector compatibility checks
  // in test mocks — these mocks only supply the slice of state that
  // ReceiptLocatorScreen reads, not the full store shape.
  (useOperatorStore as unknown as ReturnType<typeof vi.fn>).mockImplementation(
    (selector: (s: { operator: { id: string; name: string; permissions: string[]; roles: string[] } | null }) => unknown) =>
      selector({ operator: { id: 'op-1', name: 'Alice', permissions: [], roles: ['cashier'] } }),
  );

  (useTerminalStore as unknown as ReturnType<typeof vi.fn>).mockImplementation(
    (selector: (s: { terminal: { id: string; name: string } | null }) => unknown) =>
      selector({ terminal: { id: 'term-1', name: 'Terminal 1' } }),
  );

  (useAuthStore as unknown as ReturnType<typeof vi.fn>).mockImplementation(
    (selector: (s: { companyId: string | null }) => unknown) =>
      selector({ companyId: 'company-1' }),
  );
}

// ─── Tests ────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.clearAllMocks();
  // Re-bind each time: clearAllMocks resets the fns held in the factory's
  // storeState, so we fetch fresh references from getState() each beforeEach.
  const state = useRefundFlowStore.getState();
  mockSetPendingScanResult = state.setPendingScanResult as ReturnType<typeof vi.fn>;
  mockAcceptPendingScan = state.acceptPendingScan as ReturnType<typeof vi.fn>;
});

describe('ReceiptLocatorScreen', () => {
  // ── M2-UI: Customer tab must not exist (Codex review M2) ─────────────────

  it('does NOT render a Find-by-customer tab (M2-UI: tab dropped, Phase 2 will rebuild)', () => {
    setupStores();
    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    expect(screen.queryByText(/find by customer/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/rechercher par client/i)).not.toBeInTheDocument();
  });

  it('does NOT render a tab bar at all (single search field, no tablist)', () => {
    setupStores();
    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
    expect(screen.queryByRole('tab')).not.toBeInTheDocument();
  });

  // ── Rendering ──────────────────────────────────────────────────────────────

  it('renders nothing when isOpen is false', () => {
    setupStores();
    const { container } = render(<ReceiptLocatorScreen isOpen={false} onClose={() => {}} />);
    expect(container.textContent).toBe('');
  });

  it('renders the receipt-number search field when open', () => {
    setupStores();
    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    expect(screen.getByRole('textbox', { name: 'Receipt number' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Search' })).toBeInTheDocument();
  });

  // ── Scan / type: not found ─────────────────────────────────────────────────

  it('shows not-found message when receipt number does not exist in local SQLite', async () => {
    setupStores();
    vi.mocked(findReceiptByNumber).mockResolvedValue(null);

    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    fireEvent.change(screen.getByRole('textbox', { name: 'Receipt number' }), {
      target: { value: 'R-9999' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => {
      expect(screen.getByText('No receipt found for that number.')).toBeInTheDocument();
    });

    expect(mockSetPendingScanResult).not.toHaveBeenCalled();
    expect(mockAcceptPendingScan).not.toHaveBeenCalled();
  });

  // ── Scan / type: wrong terminal ──────────────────────────────────────────

  it('shows wrong-terminal message when receipt belongs to a different terminal; no event emitted', async () => {
    setupStores();
    vi.mocked(findReceiptByNumber).mockResolvedValue(OTHER_TERMINAL_ENTRY);

    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    fireEvent.change(screen.getByRole('textbox', { name: 'Receipt number' }), {
      target: { value: 'R-0002' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => {
      expect(screen.getByText('This receipt belongs to a different terminal.')).toBeInTheDocument();
    });

    expect(mockSetPendingScanResult).not.toHaveBeenCalled();
    expect(mockAcceptPendingScan).not.toHaveBeenCalled();
  });

  // ── Scan / type: found → Refund this → event emitted ─────────────────────

  it('displays receipt summary and emits event via refundFlowStore when "Refund this" is clicked', async () => {
    setupStores();
    vi.mocked(findReceiptByNumber).mockResolvedValue(TERMINAL_ENTRY);
    const onClose = vi.fn();

    render(<ReceiptLocatorScreen isOpen={true} onClose={onClose} />);

    fireEvent.change(screen.getByRole('textbox', { name: 'Receipt number' }), {
      target: { value: 'R-0001' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    // Wait for the receipt row to appear
    await waitFor(() => {
      expect(screen.getByText('R-0001')).toBeInTheDocument();
    });

    // Receipt summary is shown
    expect(screen.getByText('12500 EUR')).toBeInTheDocument();

    // Click "Refund this"
    fireEvent.click(screen.getByRole('button', { name: 'Refund this' }));

    expect(mockSetPendingScanResult).toHaveBeenCalledWith(TERMINAL_ENTRY);
    expect(mockAcceptPendingScan).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  // ── ARIA: alerts for error states ─────────────────────────────────────────

  it('announces "not found" error via role="alert"', async () => {
    setupStores();
    vi.mocked(findReceiptByNumber).mockResolvedValue(null);

    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    fireEvent.change(screen.getByRole('textbox', { name: 'Receipt number' }), {
      target: { value: 'R-9999' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
      expect(screen.getByRole('alert')).toHaveTextContent('No receipt found for that number.');
    });
  });

  it('announces "wrong terminal" error via role="alert"', async () => {
    setupStores();
    vi.mocked(findReceiptByNumber).mockResolvedValue(OTHER_TERMINAL_ENTRY);

    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    fireEvent.change(screen.getByRole('textbox', { name: 'Receipt number' }), {
      target: { value: 'R-0002' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
      expect(screen.getByRole('alert')).toHaveTextContent('This receipt belongs to a different terminal.');
    });
  });

  // ── No-fetch contract ─────────────────────────────────────────────────────

  it('does NOT touch the network during any flow (scan, found, refund)', async () => {
    setupStores();
    vi.mocked(findReceiptByNumber).mockResolvedValue(TERMINAL_ENTRY);

    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockImplementation(() => {
      throw new Error('globalThis.fetch must not be called');
    });

    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    fireEvent.change(screen.getByRole('textbox', { name: 'Receipt number' }), {
      target: { value: 'R-0001' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => {
      expect(screen.getByText('R-0001')).toBeInTheDocument();
    });

    fireEvent.click(screen.getByRole('button', { name: 'Refund this' }));

    expect(apiGet).not.toHaveBeenCalled();
    expect(apiPost).not.toHaveBeenCalled();
    expect(httpFetch).not.toHaveBeenCalled();
    expect(fetchSpy).not.toHaveBeenCalled();

    fetchSpy.mockRestore();
  });

  // ── Fixed-size invariant: modal body never resizes ────────────────────────

  it('maintains a fixed min-height content area for modal stability', () => {
    setupStores();
    render(<ReceiptLocatorScreen isOpen={true} onClose={() => {}} />);

    // The content wrapper must carry min-h-[280px] so the xl modal never
    // collapses or grows when the result set changes.
    const contentArea = document.querySelector('[data-testid="receipt-locator-body"]');
    expect(contentArea).not.toBeNull();
    expect(contentArea?.className).toContain('min-h-');
  });
});
