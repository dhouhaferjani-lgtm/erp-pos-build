/**
 * T2.4 Day 2 — BootstrapErrorScreen component test.
 *
 * Verifies that the screen renders the right affordances for each phase's
 * recoverability profile (from `phaseRecoverable` at bootstrapStore.ts),
 * coerces the error label through SAFE_ERROR_NAMES (via the store), and
 * dispatches retry/skipWithCache/logout via the appropriate stores. The
 * recoverability matrix maps to:
 *
 *   - authenticating        → recoverable=false (no cached fallback)
 *   - fetching-companies    → recoverable=false (only runs when cache empty)
 *   - fetching-terminal     → recoverable=true iff terminal cache present
 *   - checking-pins         → recoverable=false (Codex r5 P1 security gate)
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, fireEvent, cleanup, act } from '@testing-library/react';

const retryMock = vi.fn();
const skipWithCacheMock = vi.fn();
const logoutMock = vi.fn();
let mockedPhase: 'idle' | 'authenticating' | 'fetching-companies' | 'fetching-terminal' | 'checking-pins' | 'ready' | 'error' = 'error';
let mockedError: {
  phase: 'authenticating' | 'fetching-companies' | 'fetching-terminal' | 'checking-pins';
  errorName: string;
  recoverable: boolean;
  retryCount: number;
} | null = null;

vi.mock('@/stores/bootstrapStore', () => ({
  useBootstrapStore: <T,>(selector: (s: unknown) => T): T => {
    const snapshot = {
      phase: mockedPhase,
      error: mockedError,
      retry: retryMock,
      skipWithCache: skipWithCacheMock,
    };
    return selector(snapshot);
  },
}));

vi.mock('@/stores/authStore', () => ({
  useAuthStore: <T,>(selector: (s: unknown) => T): T => {
    return selector({ logout: logoutMock });
  },
}));

import { BootstrapErrorScreen } from '../BootstrapErrorScreen';

beforeEach(() => {
  retryMock.mockReset().mockResolvedValue(undefined);
  skipWithCacheMock.mockReset().mockResolvedValue(undefined);
  logoutMock.mockReset();
  mockedPhase = 'error';
  mockedError = {
    phase: 'fetching-terminal',
    errorName: 'FetchTimeoutError',
    recoverable: true,
    retryCount: 0,
  };
  cleanup();
});

describe('BootstrapErrorScreen', () => {
  it('renders the error label verbatim — already coerced by the store boundary (T0.1 contract)', () => {
    mockedError = {
      phase: 'authenticating',
      errorName: 'ApiRequestError',
      recoverable: false,
      retryCount: 2,
    };
    const { getByTestId } = render(<BootstrapErrorScreen />);
    expect(getByTestId('bootstrap-error-label').textContent).toBe('ApiRequestError');
  });

  it('returns null when no error is in the store (defensive guard)', () => {
    mockedError = null;
    const { container } = render(<BootstrapErrorScreen />);
    expect(container.firstChild).toBeNull();
  });

  it('renders Use Cached Data affordance only when error.recoverable is true', () => {
    // Recoverable case — button present.
    mockedError = {
      phase: 'fetching-terminal',
      errorName: 'FetchTimeoutError',
      recoverable: true,
      retryCount: 0,
    };
    const recoverable = render(<BootstrapErrorScreen />);
    expect(recoverable.queryByTestId('bootstrap-skip-with-cache')).not.toBeNull();
    cleanup();

    // Non-recoverable — button absent.
    mockedError = {
      phase: 'checking-pins',
      errorName: 'FetchTimeoutError',
      recoverable: false,
      retryCount: 0,
    };
    const nonRecoverable = render(<BootstrapErrorScreen />);
    expect(nonRecoverable.queryByTestId('bootstrap-skip-with-cache')).toBeNull();
  });

  it('dispatches bootstrapStore.retry() when Retry is clicked', async () => {
    const { getByTestId } = render(<BootstrapErrorScreen />);
    await act(async () => {
      fireEvent.click(getByTestId('bootstrap-retry'));
    });
    expect(retryMock).toHaveBeenCalledTimes(1);
  });

  it('dispatches bootstrapStore.skipWithCache() when Use Cached Data is clicked', async () => {
    const { getByTestId } = render(<BootstrapErrorScreen />);
    await act(async () => {
      fireEvent.click(getByTestId('bootstrap-skip-with-cache'));
    });
    expect(skipWithCacheMock).toHaveBeenCalledTimes(1);
  });

  it('dispatches authStore.logout() when Sign Out is clicked', () => {
    const { getByTestId } = render(<BootstrapErrorScreen />);
    fireEvent.click(getByTestId('bootstrap-signout'));
    expect(logoutMock).toHaveBeenCalledTimes(1);
  });

  it('disables Sign Out while a retry is in flight (Codex PR #108 r5 P1 — logout-vs-runFromPhase race)', async () => {
    // Hold retry() in flight so isBusy stays true after the click.
    let resolveRetry: (() => void) | undefined;
    retryMock.mockImplementation(() => new Promise<void>((res) => { resolveRetry = res; }));

    const { getByTestId } = render(<BootstrapErrorScreen />);
    await act(async () => {
      fireEvent.click(getByTestId('bootstrap-retry'));
    });

    const signOut = getByTestId('bootstrap-signout') as HTMLButtonElement;
    expect(signOut.disabled).toBe(true);

    // Clicking the disabled button is a no-op — logout is not called.
    fireEvent.click(signOut);
    expect(logoutMock).not.toHaveBeenCalled();

    // Once the retry settles, the busy flag flips back and sign-out
    // becomes enabled again for the cashier.
    await act(async () => {
      resolveRetry?.();
    });
    expect(signOut.disabled).toBe(false);
  });

  it('toggles the View Details expander', () => {
    const { getByTestId, queryByTestId } = render(<BootstrapErrorScreen />);

    expect(queryByTestId('bootstrap-details')).toBeNull();
    fireEvent.click(getByTestId('bootstrap-details-toggle'));
    expect(queryByTestId('bootstrap-details')).not.toBeNull();

    const details = getByTestId('bootstrap-details');
    expect(details.textContent).toContain('fetching-terminal');
    expect(details.textContent).toContain('FetchTimeoutError');
    expect(details.textContent).toContain('0');
  });
});
