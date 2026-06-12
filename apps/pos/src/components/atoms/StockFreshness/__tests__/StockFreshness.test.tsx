/**
 * Task 12 — stock staleness hint beside the SyncButton freshness display.
 *
 * Reads `stock_last_sync` from sync_metadata (written by pullLocationStock)
 * and renders `pos:stock.asOf` with the same relative-time format the
 * SyncButton uses ('<1m' / 'Xm' / 'Xh'). Renders NOTHING when the metadata
 * is absent (Menu tenants / never-pulled terminals / browser dev).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { StockFreshness } from '../StockFreshness';
import { useAuthStore } from '@/stores/authStore';

vi.mock('react-i18next', async (importActual) => {
  const actual = await importActual<typeof import('react-i18next')>();
  return {
    ...actual,
    useTranslation: () => ({
      t: (key: string, opts?: Record<string, unknown>) => {
        if (opts?.['time'] !== undefined) return `${key}:${String(opts['time'])}`;
        return key;
      },
    }),
  };
});

vi.mock('@/lib/db', () => ({
  getDatabase: vi.fn().mockResolvedValue({}),
}));

const getSyncMetadataMock = vi.fn<(db: unknown, key: string) => Promise<string | null>>();
vi.mock('@/lib/db/repositories/syncLogRepository', () => ({
  getSyncMetadata: (db: unknown, key: string) => getSyncMetadataMock(db, key),
}));

describe('StockFreshness', () => {
  beforeEach(() => {
    getSyncMetadataMock.mockReset();
    useAuthStore.setState({ companyId: 'company-1' });
  });

  it('renders the formatted as-of time when stock_last_sync is present', async () => {
    const fiveMinAgo = new Date(Date.now() - 5 * 60_000).toISOString();
    getSyncMetadataMock.mockResolvedValue(fiveMinAgo);

    render(<StockFreshness />);

    await waitFor(() => {
      expect(screen.getByText('stock.asOf:5m')).toBeInTheDocument();
    });
    expect(getSyncMetadataMock).toHaveBeenCalledWith(expect.anything(), 'stock_last_sync');
  });

  it('uses the normal gray tint (no stale title) within the 15-minute window (FU-10)', async () => {
    getSyncMetadataMock.mockResolvedValue(new Date(Date.now() - 5 * 60_000).toISOString());

    render(<StockFreshness />);

    const el = await screen.findByTestId('stock-freshness');
    expect(el.className).toContain('text-gray-500');
    expect(el.className).not.toContain('text-amber-600');
    expect(el).not.toHaveAttribute('title');
  });

  it('switches to an amber tint with a stale title beyond 15 minutes (FU-10)', async () => {
    getSyncMetadataMock.mockResolvedValue(new Date(Date.now() - 20 * 60_000).toISOString());

    render(<StockFreshness />);

    const el = await screen.findByTestId('stock-freshness');
    expect(el.className).toContain('text-amber-600');
    expect(el.className).not.toContain('text-gray-500');
    expect(el).toHaveAttribute('title', 'stock.staleTitle');
  });

  it('renders nothing when the metadata is absent', async () => {
    getSyncMetadataMock.mockResolvedValue(null);

    const { container } = render(<StockFreshness />);

    await waitFor(() => {
      expect(getSyncMetadataMock).toHaveBeenCalled();
    });
    expect(container).toBeEmptyDOMElement();
  });

  it('renders nothing when the local DB is unavailable (browser dev)', async () => {
    getSyncMetadataMock.mockRejectedValue(new Error('no sqlite'));

    const { container } = render(<StockFreshness />);

    await waitFor(() => {
      expect(getSyncMetadataMock).toHaveBeenCalled();
    });
    expect(container).toBeEmptyDOMElement();
  });
});
