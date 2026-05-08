/**
 * T1.1 Step 1.5 — LoginPage "still trying" affordance + Cancel button.
 *
 * BEFORE: when /auth/login hung (server overloaded, captive portal
 * half-broken, or a sub-T0.3-timeout slow path), the cashier saw a
 * frozen spinner with no escape — no cancel, no "still trying" text,
 * no recovery. T0.3's read-timeout caps the abort to 10s, but for
 * captive-portal scenarios that respond after 8-15s with HTML, the
 * cashier had zero feedback during the wait.
 *
 * AFTER:
 *   - After 8s of isLoading, the LoginPage shows a "still working"
 *     sub-text under the spinner.
 *   - After the same 8s, a Cancel button appears that aborts the
 *     in-flight login() via an AbortController whose signal is
 *     threaded down to apiPost / apiGet via T0.3's fetchWithTimeout.
 *   - Re-clicking Sign in after cancellation creates a fresh
 *     AbortController.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  initReactI18next: { type: '3rdParty', init: () => {} },
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  ApiRequestError: class ApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  },
  getErrorMessage: (e: unknown) => (e instanceof Error ? e.message : 'unknown'),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    TOKEN: 'auth_token',
    USER: 'user',
    COMPANY_ID: 'company_id',
    COMPANIES: 'companies',
    TERMINAL: 'terminal',
    PENDING_TERMINAL_ID: 'pending_terminal_id',
  },
}));

vi.mock('@/lib/echo', () => ({
  disconnectEcho: vi.fn(),
}));

vi.mock('@tauri-apps/plugin-os', () => ({
  platform: vi.fn(() => 'macos'),
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: vi.fn(() => 'device-test'),
}));

import { LoginPage } from '../LoginPage';
import { useAuthStore } from '@/stores/authStore';
import { useConnectivityStore } from '@/stores/connectivityStore';

describe('LoginPage — T1.1 Step 1.5 still-trying + Cancel', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    useConnectivityStore.setState({
      isOnline: true,
      serverReachable: true,
      lastCheckedAt: Date.now(),
    } as never);

    useAuthStore.setState({
      user: null,
      token: null,
      serverUrl: 'http://localhost:8002',
      companyId: null,
      companies: [],
      isAuthenticated: false,
      isLoading: false,
      isInitialized: true,
    } as never);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('T1.1: LoginPage does NOT show "still trying" sub-text before 8s of isLoading', () => {
    useAuthStore.setState({ isLoading: true } as never);
    render(<LoginPage />);
    act(() => {
      vi.advanceTimersByTime(7000);
    });
    expect(screen.queryByTestId('login-still-trying')).not.toBeInTheDocument();
    expect(screen.queryByTestId('login-cancel')).not.toBeInTheDocument();
  });

  it('T1.1: LoginPage shows "still trying" sub-text after 8s of isLoading', () => {
    useAuthStore.setState({ isLoading: true } as never);
    render(<LoginPage />);
    act(() => {
      vi.advanceTimersByTime(8000);
    });
    expect(screen.getByTestId('login-still-trying')).toBeInTheDocument();
  });

  it('T1.1: LoginPage shows Cancel button after 8s of isLoading', () => {
    useAuthStore.setState({ isLoading: true } as never);
    render(<LoginPage />);
    act(() => {
      vi.advanceTimersByTime(8000);
    });
    expect(screen.getByTestId('login-cancel')).toBeInTheDocument();
  });

  it('T1.1: LoginPage Cancel button aborts in-flight login via signal', () => {
    // login() captures the threaded signal and never resolves on its own
    // — only an abort can settle it. We do NOT await any state update
    // tied to this promise, since awaiting would block the test.
    let capturedSignal: AbortSignal | undefined;
    const loginSpy = vi.fn(
      (
        _email: string,
        _password: string,
        opts?: { signal?: AbortSignal },
      ) => {
        capturedSignal = opts?.signal;
        return new Promise<void>(() => {});
      },
    );

    useAuthStore.setState({ login: loginSpy as never } as never);

    render(<LoginPage />);

    // Fill required fields and submit.
    fireEvent.change(screen.getByLabelText('auth.email'), {
      target: { value: 'test@example.com' },
    });
    fireEvent.change(screen.getByLabelText('auth.password'), {
      target: { value: 'pass-w-8-chars' },
    });
    fireEvent.submit(
      screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!,
    );

    // Mimic the real authStore.login flipping isLoading=true.
    act(() => {
      useAuthStore.setState({ isLoading: true } as never);
    });

    // Advance to 8s — Cancel button should appear.
    act(() => {
      vi.advanceTimersByTime(8000);
    });

    const cancelButton = screen.getByTestId('login-cancel');
    fireEvent.click(cancelButton);

    expect(loginSpy).toHaveBeenCalledTimes(1);
    expect(capturedSignal).toBeDefined();
    expect(capturedSignal!.aborted).toBe(true);
  });
});
