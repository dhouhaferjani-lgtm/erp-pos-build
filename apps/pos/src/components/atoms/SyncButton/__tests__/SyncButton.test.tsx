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

  // T1.3 Step 4.3: tristate sync indicator. The amber dot renders only
  // when `lastSyncResult.degraded === true`. Two negative cases (null
  // result, degraded === false) confirm the dot is absent in the
  // happy path; the positive case confirms the dot + tooltip render
  // when a tick degraded.
  it('T1.3: renders amber dot when lastSyncResult.degraded is true', () => {
    mockState = {
      lastSyncResult: { degraded: true, errors: [] as string[] },
    };
    render(<SyncButton />);
    const dot = screen.getByTestId('sync-degraded-dot');
    expect(dot).toBeInTheDocument();
  });

  it('T1.3: amber dot has the configNotLoaded-equivalent tooltip via aria-label', () => {
    mockState = {
      lastSyncResult: { degraded: true, errors: ['boom'] as string[] },
    };
    render(<SyncButton />);
    const dot = screen.getByTestId('sync-degraded-dot');
    // Mocked t() returns the key verbatim — the production component
    // MUST pass the degradedTitle key into the aria-label so screen
    // readers announce the state. The i18n smoke test (separate file)
    // verifies the key actually resolves to translated text in en + fr.
    expect(dot).toHaveAttribute('aria-label', 'sync.degradedTitle');
  });

  it('T1.3: does NOT render amber dot when lastSyncResult is null', () => {
    mockState = { lastSyncResult: null };
    render(<SyncButton />);
    expect(screen.queryByTestId('sync-degraded-dot')).not.toBeInTheDocument();
  });

  it('T1.3: does NOT render amber dot when lastSyncResult.degraded is false', () => {
    mockState = {
      lastSyncResult: { degraded: false, errors: [] as string[] },
    };
    render(<SyncButton />);
    expect(screen.queryByTestId('sync-degraded-dot')).not.toBeInTheDocument();
  });

  it('debounces rapid double-clicks (second click within 500 ms is ignored)', async () => {
    vi.useFakeTimers();
    render(<SyncButton />);
    const button = screen.getByRole('button');

    fireEvent.click(button);
    fireEvent.click(button);
    fireEvent.click(button);

    expect(mockTriggerSync).toHaveBeenCalledOnce();

    // After the debounce window elapses, clicks fire again.
    await vi.advanceTimersByTimeAsync(501);
    fireEvent.click(button);
    expect(mockTriggerSync).toHaveBeenCalledTimes(2);

    vi.useRealTimers();
  });
});
