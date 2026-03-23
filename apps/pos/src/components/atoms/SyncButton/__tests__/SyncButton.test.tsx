import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { SyncButton } from '../SyncButton';

const mockTriggerSync = vi.fn();

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (key === 'sync.lastSync' && opts?.time) return `Last sync: ${String(opts.time)}`;
      return key;
    },
  }),
}));

vi.mock('@/stores/syncStore', () => ({
  useSyncStore: vi.fn((selector: (state: Record<string, unknown>) => unknown) =>
    selector({
      isSyncing: false,
      lastSyncAt: null,
      triggerSync: mockTriggerSync,
      ...mockState,
    }),
  ),
}));

let mockState: Record<string, unknown> = {};

beforeEach(() => {
  mockState = {};
  mockTriggerSync.mockClear();
});

describe('SyncButton', () => {
  it('renders a button', () => {
    render(<SyncButton />);
    const button = screen.getByRole('button');
    expect(button).toBeInTheDocument();
  });

  it('shows syncing text when isSyncing is true', () => {
    mockState = { isSyncing: true };
    render(<SyncButton />);
    expect(screen.getByText('sync.syncing')).toBeInTheDocument();
  });

  it('calls triggerSync on click', () => {
    render(<SyncButton />);
    fireEvent.click(screen.getByRole('button'));
    expect(mockTriggerSync).toHaveBeenCalledOnce();
  });

  it('is disabled when syncing', () => {
    mockState = { isSyncing: true };
    render(<SyncButton />);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('shows syncNow text when not syncing', () => {
    render(<SyncButton />);
    expect(screen.getByText('sync.syncNow')).toBeInTheDocument();
  });

  it('shows last sync time text when lastSyncAt is set', () => {
    // Use a timestamp ~5 minutes in the past
    mockState = { lastSyncAt: Date.now() - 5 * 60_000 };
    render(<SyncButton />);
    expect(screen.getByText(/Last sync:/)).toBeInTheDocument();
  });
});
