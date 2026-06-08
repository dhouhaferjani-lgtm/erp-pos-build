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
import { describe, it, expect, beforeAll, beforeEach, afterEach, vi } from 'vitest';
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
    LOGIN_TENANT_ID: 'login_tenant_id',
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
import type { User, Company } from '@/stores/authStore';
// Capture the real login action before any test can replace it with a spy.
// This lets the picker describe's beforeEach restore the real implementation.
let originalLoginAction: ReturnType<typeof useAuthStore.getState>['login'];
import { useConnectivityStore } from '@/stores/connectivityStore';
import { apiGet, apiPost } from '@/lib/api';
import { getStoredValue } from '@/lib/storage';

const mockUser: User = {
  id: 'user-1',
  name: 'Test User',
  email: 'test@example.com',
  tenantId: 'tenant-1',
  phone: null,
  status: 'active',
  locale: 'en',
  timezone: 'UTC',
  roles: ['admin'],
  permissions: ['pos.access'],
  emailVerified: true,
};

const mockCompanies: Company[] = [
  {
    id: 'company-1',
    name: 'Test Co',
    legalName: 'Test Company SAS',
    countryCode: 'FR',
    currency: 'EUR',
    locale: 'fr',
    timezone: 'Europe/Paris',
  },
];

// Capture the real login implementation ONCE, before any test mutates the store.
beforeAll(() => {
  originalLoginAction = useAuthStore.getState().login;
});

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

  it('T1.1: LoginPage Cancel button aborts in-flight login via signal', async () => {
    // Codex round-1 finding (g): the test must prove the attempt SETTLES
    // when Cancel fires, not just that the signal flipped to aborted.
    // The mocked login() now actually rejects when its signal aborts,
    // and the test asserts isLoading clears and the still-trying UI is
    // gone after the click — matching the user-visible end-state.
    let capturedSignal: AbortSignal | undefined;
    const loginSpy = vi.fn(
      (
        _email: string,
        _password: string,
        opts?: { signal?: AbortSignal },
      ) =>
        new Promise<void>((_resolve, reject) => {
          capturedSignal = opts?.signal;
          useAuthStore.setState({ isLoading: true } as never);
          opts?.signal?.addEventListener('abort', () => {
            useAuthStore.setState({ isLoading: false } as never);
            reject(new DOMException('aborted', 'AbortError'));
          });
        }),
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
    await act(async () => {
      fireEvent.submit(
        screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!,
      );
    });

    // Advance to 8s — Cancel button should appear.
    act(() => {
      vi.advanceTimersByTime(8000);
    });

    const cancelButton = screen.getByTestId('login-cancel');
    expect(screen.getByTestId('login-still-trying')).toBeInTheDocument();
    expect(useAuthStore.getState().isLoading).toBe(true);

    await act(async () => {
      fireEvent.click(cancelButton);
      // Flush microtasks so the abort listener and the rejection settle
      // before assertions.
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(loginSpy).toHaveBeenCalledTimes(1);
    expect(capturedSignal).toBeDefined();
    expect(capturedSignal!.aborted).toBe(true);
    // Attempt actually settled — UI no longer in spinner state.
    expect(useAuthStore.getState().isLoading).toBe(false);
    expect(screen.queryByTestId('login-still-trying')).not.toBeInTheDocument();
    expect(screen.queryByTestId('login-cancel')).not.toBeInTheDocument();
  });
});

describe('LoginPage — business picker', () => {
  beforeEach(() => {
    vi.mocked(getStoredValue).mockResolvedValue(null);
    vi.mocked(apiPost).mockReset();
    vi.mocked(apiGet).mockReset();
    useConnectivityStore.setState({
      isOnline: true,
      serverReachable: true,
      lastCheckedAt: Date.now(),
    } as never);
    // Restore the real login action in case a prior test replaced it with a spy.
    useAuthStore.setState({
      user: null, token: null, companies: [], companyId: null,
      isAuthenticated: false, isLoading: false,
      login: originalLoginAction,
    } as never);
  });

  it('renders the org picker (name + slug) when login requires selection', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      requires_org_selection: true,
      organizations: [
        { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
        { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
      ],
    });
    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });

    expect(await screen.findByTestId('org-picker')).toBeInTheDocument();
    expect(screen.getByText('Alpha')).toBeInTheDocument();
    expect(screen.getByText('alpha')).toBeInTheDocument(); // slug shown
  });

  it('selecting an org re-invokes login with tenantId', async () => {
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
      })
      .mockResolvedValueOnce({ user: mockUser, token: 'tok', tokenType: 'Bearer', deviceId: null });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    await screen.findByTestId('org-picker');

    await act(async () => { fireEvent.click(screen.getByRole('button', { name: /Beta/ })); });

    const calls = vi.mocked(apiPost).mock.calls;
    const lastCall = calls[calls.length - 1]!;
    expect((lastCall[1] as Record<string, unknown>).tenant_id).toBe('t-2');
  });

  it('aborting a manual pick commits no auth state (fresh controller)', async () => {
    // First POST resolves the picker immediately; second POST hangs so we can abort it.
    let capturedPickSignal: AbortSignal | undefined;
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
      })
      .mockImplementationOnce((_url, _body, opts) => {
        capturedPickSignal = (opts as { signal?: AbortSignal })?.signal;
        return new Promise((_res, rej) => {
          capturedPickSignal?.addEventListener('abort', () =>
            rej(new DOMException('Aborted', 'AbortError')),
          );
        });
      });

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    // Submit the form — first apiPost returns org-picker immediately.
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    // Picker should be visible now (real timers).
    await screen.findByTestId('org-picker');

    // Use fake timers only for the STILL_TRYING threshold, around the pick click.
    vi.useFakeTimers();
    // Start the pick (second apiPost hangs). void discards the promise so the
    // outer act() resolves immediately after the click handler fires.
    act(() => { fireEvent.click(screen.getByRole('button', { name: /Beta/ })); });
    // Advance past STILL_TRYING_THRESHOLD_MS so the cancel button appears.
    act(() => { vi.advanceTimersByTime(8000); });
    vi.useRealTimers();

    // Now the org-pick-cancel button should be present; click it to abort.
    await act(async () => {
      fireEvent.click(screen.getByTestId('org-pick-cancel'));
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(useAuthStore.getState().isAuthenticated).toBe(false);
    expect(useAuthStore.getState().token).toBeNull();
    // Strengthened: the second apiPost's signal must have been aborted.
    expect(capturedPickSignal).toBeDefined();
    expect(capturedPickSignal!.aborted).toBe(true);
  });

  it('pick failure shows error message in the picker (account not active, 422)', async () => {
    const { ApiRequestError } = await import('@/lib/api');
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }],
      })
      .mockRejectedValueOnce(
        new ApiRequestError(422, 'Your account is not active. Please contact support.', 'VALIDATION_ERROR'),
      );

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    await screen.findByTestId('org-picker');

    await act(async () => { fireEvent.click(screen.getByRole('button', { name: /Alpha/ })); });

    expect(await screen.findByText('Your account is not active. Please contact support.')).toBeInTheDocument();
    // Picker must remain available for retry.
    expect(screen.getByTestId('org-picker')).toBeInTheDocument();
  });

  it('suspended org (403) shows error message in the picker', async () => {
    const { ApiRequestError } = await import('@/lib/api');
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }],
      })
      .mockRejectedValueOnce(
        new ApiRequestError(403, 'This organization is currently unavailable.', 'ORGANIZATION_UNAVAILABLE'),
      );

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    await screen.findByTestId('org-picker');

    await act(async () => { fireEvent.click(screen.getByRole('button', { name: /Alpha/ })); });

    expect(await screen.findByText('This organization is currently unavailable.')).toBeInTheDocument();
    // Picker must remain available for retry.
    expect(screen.getByTestId('org-picker')).toBeInTheDocument();
  });

  it('double-click same org → one tenant-bound POST', async () => {
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }],
      })
      .mockResolvedValueOnce({ user: mockUser, token: 'tok', tokenType: 'Bearer', deviceId: null });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    await screen.findByTestId('org-picker');

    const orgButton = screen.getByRole('button', { name: /Alpha/ });

    // Click twice in quick succession — after the first click, pendingTenantId is set,
    // disabling the button so the second click is a no-op.
    await act(async () => {
      fireEvent.click(orgButton);
      fireEvent.click(orgButton);
      await Promise.resolve();
      await Promise.resolve();
    });

    // Wait for auth to complete.
    await act(async () => { await Promise.resolve(); });

    // Exactly 2 total apiPost calls: the initial email-first + one tenant-bound pick.
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(2);
    const tenantCall = vi.mocked(apiPost).mock.calls[1]!;
    expect((tenantCall[1] as Record<string, unknown>).tenant_id).toBe('t-1');
  });

  it('rapid two different orgs → only first fires', async () => {
    vi.mocked(apiPost)
      .mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [
          { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
          { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
        ],
      })
      .mockResolvedValueOnce({ user: mockUser, token: 'tok', tokenType: 'Bearer', deviceId: null });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    render(<LoginPage />);
    fireEvent.change(screen.getByLabelText('auth.email'), { target: { value: 'm@e.com' } });
    fireEvent.change(screen.getByLabelText('auth.password'), { target: { value: 'password123' } });
    await act(async () => { fireEvent.submit(screen.getByRole('button', { name: 'auth.signIn' }).closest('form')!); });
    await screen.findByTestId('org-picker');

    // Click Alpha then Beta rapidly — Beta is disabled after Alpha sets pendingTenantId.
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /Alpha/ }));
      fireEvent.click(screen.getByRole('button', { name: /Beta/ }));
      await Promise.resolve();
      await Promise.resolve();
    });

    await act(async () => { await Promise.resolve(); });

    // Exactly 2 total apiPost calls: initial + one tenant-bound pick for Alpha only.
    expect(vi.mocked(apiPost)).toHaveBeenCalledTimes(2);
    const tenantCall = vi.mocked(apiPost).mock.calls[1]!;
    expect((tenantCall[1] as Record<string, unknown>).tenant_id).toBe('t-1');
    // Auth completed with one authenticated end-state.
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
  });
});
