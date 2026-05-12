import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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

// T1.3 Codex round-2 finding 5: hard-restore real timers between tests
// so a thrown assertion inside a fake-timers test cannot leak
// `vi.useFakeTimers()` into the next test (which would silently change
// timer semantics for any setTimeout-using test that follows).
afterEach(() => {
  vi.useRealTimers();
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

  it('T1.3: amber dot is aria-hidden so screen readers do not double-announce the degraded state', () => {
    // The button's aria-label carries the degraded announcement (see
    // the "button aria-label incorporates degraded state" test below).
    // The dot itself is purely visual — Codex round-2 finding 6
    // (cleanup of round-1 MAJOR-1).
    mockState = {
      lastSyncResult: { degraded: true, errors: ['boom'] as string[] },
    };
    render(<SyncButton />);
    const dot = screen.getByTestId('sync-degraded-dot');
    expect(dot).toHaveAttribute('aria-hidden', 'true');
    // The hover-tooltip (`title`) remains on the dot for sighted users;
    // screen readers ignore it because the dot is aria-hidden.
    expect(dot).toHaveAttribute('title', 'sync.degradedTitle');
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

  // T1.3 Codex round-1 finding 1: the button's aria-label (the screen-
  // reader announcement for the interactive control) must incorporate
  // the degraded state. The amber dot's own aria-label isn't enough
  // because the button is the focus target.
  it('T1.3: button aria-label incorporates degraded state when degraded', () => {
    mockState = {
      lastSyncResult: { degraded: true, errors: [] as string[] },
    };
    render(<SyncButton />);
    const button = screen.getByRole('button');
    const label = button.getAttribute('aria-label') ?? '';
    expect(label).toContain('sync.syncNow');
    expect(label).toContain('sync.degradedTitle');
  });

  it('T1.3: button aria-label is the bare syncNow text when not degraded', () => {
    mockState = { lastSyncResult: null };
    render(<SyncButton />);
    const button = screen.getByRole('button');
    expect(button.getAttribute('aria-label')).toBe('sync.syncNow');
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
