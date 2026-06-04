import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useAuthStore } from '../authStore';
import type { User, Company } from '../authStore';

// Mock dependencies
vi.mock('@tauri-apps/plugin-os', () => ({
  platform: vi.fn(() => 'macos'),
}));

vi.mock('@/lib/api', () => {
  class ApiRequestError extends Error {
    constructor(
      public readonly status: number,
      public readonly apiMessage: string,
      public readonly code: string,
    ) {
      super(apiMessage);
      this.name = 'ApiRequestError';
    }
  }
  return {
    apiGet: vi.fn(),
    apiPost: vi.fn(),
    ApiRequestError,
  };
});

vi.mock('@/lib/echo', () => ({
  disconnectEcho: vi.fn(),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn().mockResolvedValue(null),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  removeStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    TOKEN: 'auth_token',
    SERVER_URL: 'server_url',
    USER: 'user',
    COMPANY_ID: 'company_id',
    COMPANIES: 'companies',
    TERMINAL: 'terminal',
    PENDING_TERMINAL_ID: 'pending_terminal_id',
    LOGIN_TENANT_ID: 'login_tenant_id',
  },
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: vi.fn(() => 'device-123'),
}));

import { apiGet, apiPost, ApiRequestError } from '@/lib/api';
import { disconnectEcho } from '@/lib/echo';
import { getStoredValue, setStoredValue, removeStoredValue } from '@/lib/storage';

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

describe('authStore', () => {
  beforeEach(() => {
    // Reset to initial state
    useAuthStore.setState({
      user: null,
      token: null,
      serverUrl: null,
      companyId: null,
      companies: [],
      isAuthenticated: false,
      isLoading: false,
      isInitialized: false,
    });
    vi.clearAllMocks();
  });

  it('has correct initial state', () => {
    const state = useAuthStore.getState();
    expect(state.user).toBeNull();
    expect(state.token).toBeNull();
    expect(state.isAuthenticated).toBe(false);
    expect(state.isLoading).toBe(false);
    expect(state.isInitialized).toBe(false);
  });

  it('logs in and stores user, token, and companies', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: mockUser,
      token: 'jwt-token-123',
      tokenType: 'Bearer',
      deviceId: 'device-123',
    });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    await useAuthStore.getState().login('test@example.com', 'password');

    const state = useAuthStore.getState();
    expect(state.user).toEqual(mockUser);
    expect(state.token).toBe('jwt-token-123');
    expect(state.isAuthenticated).toBe(true);
    expect(state.serverUrl).toBeTruthy();
    expect(state.companies).toEqual(mockCompanies);
    expect(state.isLoading).toBe(false);
  });

  it('auto-selects company when only one is returned', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: mockUser,
      token: 'jwt-token-123',
      tokenType: 'Bearer',
      deviceId: null,
    });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    await useAuthStore.getState().login('test@example.com', 'pass');

    expect(useAuthStore.getState().companyId).toBe('company-1');
  });

  it('does not auto-select company when multiple are returned', async () => {
    const twoCompanies = [
      ...mockCompanies,
      { ...mockCompanies[0]!, id: 'company-2', name: 'Second Co' },
    ];
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: mockUser,
      token: 'jwt-token-123',
      tokenType: 'Bearer',
      deviceId: null,
    });
    vi.mocked(apiGet).mockResolvedValueOnce(twoCompanies);

    await useAuthStore.getState().login('test@example.com', 'pass');

    expect(useAuthStore.getState().companyId).toBeNull();
  });

  it('rethrows login errors', async () => {
    vi.mocked(apiPost).mockRejectedValue(new Error('Invalid credentials'));

    await expect(
      useAuthStore.getState().login('bad@example.com', 'wrong'),
    ).rejects.toThrow('Invalid credentials');

    expect(useAuthStore.getState().isLoading).toBe(false);
  });

  it('sets company id', () => {
    useAuthStore.getState().setCompany('company-1');
    expect(useAuthStore.getState().companyId).toBe('company-1');
  });

  it('logs out and sets serverUrl from env', () => {
    useAuthStore.setState({
      user: mockUser,
      token: 'jwt-token-123',
      serverUrl: 'https://api.test.com',
      companyId: 'company-1',
      companies: mockCompanies,
      isAuthenticated: true,
      isInitialized: true,
    });

    useAuthStore.getState().logout();

    const state = useAuthStore.getState();
    expect(state.user).toBeNull();
    expect(state.token).toBeNull();
    expect(state.isAuthenticated).toBe(false);
    expect(state.serverUrl).toBeTruthy();
    expect(state.isInitialized).toBe(true);
    expect(disconnectEcho).toHaveBeenCalled();
    expect(removeStoredValue).toHaveBeenCalledWith('auth_token');
  });

  it('resets bootstrap state on logout (Codex PR #108 r1 P2 — sign-out escape hatch)', async () => {
    const { useBootstrapStore } = await import('@/stores/bootstrapStore');

    // Pre-set bootstrap into the error state — the trap scenario the
    // fix targets. AppRouter's early-return checks `phase === 'error'`
    // before the `!isAuthenticated` LoginPage branch, so without the
    // logout-side reset the cashier stays on BootstrapErrorScreen
    // after signing out.
    useBootstrapStore.setState({
      phase: 'error',
      error: {
        phase: 'fetching-terminal',
        errorName: 'FetchTimeoutError',
        recoverable: true,
        retryCount: 2,
      },
      lastSuccessfulPhase: 'fetching-companies',
      running: false,
    } as never);

    useAuthStore.getState().logout();

    // The reset wiring uses a dynamic import (avoids cyclic static
    // bootstrap → auth → bootstrap dependency). Wait for the import
    // promise to settle and its .then() to run; one macrotask flush is
    // enough because the module was pre-imported above so it is cached.
    await vi.waitFor(() => {
      expect(useBootstrapStore.getState().phase).toBe('ready');
    });

    const state = useBootstrapStore.getState();
    expect(state.phase).toBe('ready');
    expect(state.error).toBeNull();
    expect(state.lastSuccessfulPhase).toBeNull();
    expect(state.running).toBe(false);
  });

  it('checkSession updates user from server', async () => {
    const updatedUser = { ...mockUser, name: 'Updated Name' };
    vi.mocked(apiGet).mockResolvedValue(updatedUser);

    await useAuthStore.getState().checkSession();

    expect(useAuthStore.getState().user).toEqual(updatedUser);
    expect(useAuthStore.getState().isAuthenticated).toBe(true);
    expect(setStoredValue).toHaveBeenCalledWith('user', updatedUser);
  });

  it('initializes from stored values when all present', async () => {
    vi.mocked(getStoredValue)
      .mockResolvedValueOnce('jwt-token-123') // TOKEN
      .mockResolvedValueOnce(mockUser) // USER
      .mockResolvedValueOnce('company-1') // COMPANY_ID
      .mockResolvedValueOnce(mockCompanies); // COMPANIES

    // Mock checkSession
    vi.mocked(apiGet).mockResolvedValue(mockUser);

    await useAuthStore.getState().initialize();

    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(true);
    expect(state.token).toBe('jwt-token-123');
    expect(state.isInitialized).toBe(true);
    expect(state.isLoading).toBe(false);
  });

  it('initializes with serverUrl from env when no token stored', async () => {
    vi.mocked(getStoredValue)
      .mockResolvedValueOnce(null) // TOKEN
      .mockResolvedValueOnce(null) // USER
      .mockResolvedValueOnce(null) // COMPANY_ID
      .mockResolvedValueOnce(null); // COMPANIES

    await useAuthStore.getState().initialize();

    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
    expect(state.serverUrl).toBeTruthy();
    expect(state.isInitialized).toBe(true);
  });

  it('keeps auth state when checkSession fails with network error (offline)', async () => {
    vi.mocked(getStoredValue)
      .mockResolvedValueOnce('jwt-token-123')
      .mockResolvedValueOnce(mockUser)
      .mockResolvedValueOnce('company-1')
      .mockResolvedValueOnce(mockCompanies);

    // Network error — NOT a 401
    vi.mocked(apiGet).mockRejectedValue(new Error('Failed to fetch'));

    await useAuthStore.getState().initialize();

    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(true);
    expect(state.token).toBe('jwt-token-123');
    expect(state.user).toEqual(mockUser);
    expect(state.companyId).toBe('company-1');
    expect(state.isInitialized).toBe(true);
  });

  it('refreshCompanyConfig updates productStore.companyConfig on success', async () => {
    const { useProductStore } = await import('@/stores/productStore');
    vi.mocked(apiGet).mockResolvedValueOnce({
      all_enabled_modules: ['POS', 'Menu'],
      vertical: 'fnb',
      smart_prompts_enabled: true,
    });

    await useAuthStore.getState().refreshCompanyConfig();

    expect(useProductStore.getState().companyConfig?.all_enabled_modules).toContain('Menu');
  });

  it('refreshCompanyConfig swallows errors and keeps previous config', async () => {
    const { useProductStore } = await import('@/stores/productStore');
    useProductStore.setState({ companyConfig: { all_enabled_modules: ['POS'] } });
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('offline'));

    await expect(useAuthStore.getState().refreshCompanyConfig()).resolves.toBeUndefined();
    expect(useProductStore.getState().companyConfig?.all_enabled_modules).toEqual(['POS']);
  });

  // T1.1 Step 1.1: login transactional — persists must happen ONLY after
  // /user/companies resolves successfully. Otherwise a network drop after
  // the POST returns leaves TOKEN+USER persisted in Tauri Store with
  // companies:[] in state, and the next boot routes to TerminalSetupPage
  // which immediately throws because companyId is null.
  it('T1.1: login transactional — companies-fetch failure leaves no persisted state', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: mockUser,
      token: 'jwt-token-123',
      tokenType: 'Bearer',
      deviceId: 'device-123',
    });
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('Network drop after login POST'));

    await expect(
      useAuthStore.getState().login('test@example.com', 'password'),
    ).rejects.toThrow('Network drop after login POST');

    // Persistence must NOT have happened
    expect(setStoredValue).not.toHaveBeenCalledWith('auth_token', expect.anything());
    expect(setStoredValue).not.toHaveBeenCalledWith('user', expect.anything());
    expect(setStoredValue).not.toHaveBeenCalledWith('companies', expect.anything());

    // In-memory auth must NOT flip to authenticated
    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
    expect(state.token).toBeNull();
    expect(state.user).toBeNull();
    expect(state.companies).toEqual([]);
    expect(state.isLoading).toBe(false);
  });

  it('T1.1: login successful — persists in correct order (after companies-fetch resolves)', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: mockUser,
      token: 'jwt-token-123',
      tokenType: 'Bearer',
      deviceId: 'device-123',
    });
    vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

    await useAuthStore.getState().login('test@example.com', 'password');

    const setStoredValueMock = vi.mocked(setStoredValue);
    const apiGetMock = vi.mocked(apiGet);

    // Find the invocation orders. mock.invocationCallOrder is a global counter
    // across all vi.fn() calls within the test, so we can compare.
    const tokenPersistCall = setStoredValueMock.mock.calls.findIndex(
      (call) => call[0] === 'auth_token',
    );
    const userPersistCall = setStoredValueMock.mock.calls.findIndex(
      (call) => call[0] === 'user',
    );
    expect(tokenPersistCall).toBeGreaterThanOrEqual(0);
    expect(userPersistCall).toBeGreaterThanOrEqual(0);

    const tokenPersistOrder = setStoredValueMock.mock.invocationCallOrder[tokenPersistCall]!;
    const userPersistOrder = setStoredValueMock.mock.invocationCallOrder[userPersistCall]!;
    const companiesFetchOrder = apiGetMock.mock.invocationCallOrder[0]!;

    // Both TOKEN and USER persists must happen AFTER apiGet('/user/companies')
    expect(tokenPersistOrder).toBeGreaterThan(companiesFetchOrder);
    expect(userPersistOrder).toBeGreaterThan(companiesFetchOrder);
  });

  // Codex round-1 finding (c): a failed companies-fetch must restore the
  // prior in-memory auth state verbatim. Re-attempting login() over an
  // already-valid session must not corrupt token / user / companies /
  // companyId / isAuthenticated when the second login's companies-fetch
  // fails.
  it('T1.1: login over existing session — companies-fetch failure restores prior in-memory auth', async () => {
    // Seed an existing valid session.
    useAuthStore.setState({
      user: mockUser,
      token: 'jwt-prior-token',
      serverUrl: 'http://localhost:8002',
      companyId: 'company-1',
      companies: mockCompanies,
      isAuthenticated: true,
      isLoading: false,
      isInitialized: true,
    });

    // Second login attempt: POST resolves with NEW token + user; GET
    // /user/companies fails.
    const newUser: User = { ...mockUser, id: 'user-2', name: 'Second User' };
    vi.mocked(apiPost).mockResolvedValueOnce({
      user: newUser,
      token: 'jwt-new-token-failure',
      tokenType: 'Bearer',
      deviceId: null,
    });
    vi.mocked(apiGet).mockRejectedValueOnce(new Error('companies fetch failed'));

    await expect(
      useAuthStore.getState().login('second@example.com', 'pass'),
    ).rejects.toThrow('companies fetch failed');

    const state = useAuthStore.getState();
    // Prior session must be intact — token was NOT reset to null.
    expect(state.token).toBe('jwt-prior-token');
    expect(state.user).toEqual(mockUser);
    expect(state.companies).toEqual(mockCompanies);
    expect(state.companyId).toBe('company-1');
    expect(state.isAuthenticated).toBe(true);
    expect(state.isLoading).toBe(false);
  });

  // Codex round-1 finding (b): fetchCompanies must clear a stale
  // companyId when the refreshed list no longer contains it.
  it('T1.1: fetchCompanies — clears stale companyId when not in refreshed companies', async () => {
    useAuthStore.setState({
      isAuthenticated: true,
      companyId: 'company-stale-old',
      companies: [],
    });

    const refreshed: Company[] = [
      { ...mockCompanies[0]!, id: 'company-A', name: 'A' },
      { ...mockCompanies[0]!, id: 'company-B', name: 'B' },
    ];
    vi.mocked(apiGet).mockResolvedValueOnce(refreshed);

    await useAuthStore.getState().fetchCompanies();

    const state = useAuthStore.getState();
    expect(state.companies).toEqual(refreshed);
    expect(state.companyId).toBeNull();
    expect(removeStoredValue).toHaveBeenCalledWith('company_id');
  });

  it('T1.1: fetchCompanies — keeps companyId when still valid in refreshed companies', async () => {
    useAuthStore.setState({
      isAuthenticated: true,
      companyId: 'company-A',
      companies: [],
    });

    const refreshed: Company[] = [
      { ...mockCompanies[0]!, id: 'company-A', name: 'A' },
      { ...mockCompanies[0]!, id: 'company-B', name: 'B' },
    ];
    vi.mocked(apiGet).mockResolvedValueOnce(refreshed);

    await useAuthStore.getState().fetchCompanies();

    expect(useAuthStore.getState().companyId).toBe('company-A');
    expect(removeStoredValue).not.toHaveBeenCalledWith('company_id');
  });

  it('T1.1: fetchCompanies — auto-selects single returned company when prior selection was stale', async () => {
    useAuthStore.setState({
      isAuthenticated: true,
      companyId: 'company-stale',
      companies: [],
    });

    const refreshed: Company[] = [
      { ...mockCompanies[0]!, id: 'company-only', name: 'Only' },
    ];
    vi.mocked(apiGet).mockResolvedValueOnce(refreshed);

    await useAuthStore.getState().fetchCompanies();

    expect(useAuthStore.getState().companyId).toBe('company-only');
  });

  it('logs out when checkSession returns 401 (token expired)', async () => {
    vi.mocked(getStoredValue)
      .mockResolvedValueOnce('expired-token')
      .mockResolvedValueOnce(mockUser)
      .mockResolvedValueOnce('company-1')
      .mockResolvedValueOnce(mockCompanies);

    // 401 — token genuinely expired
    const { ApiRequestError } = await import('@/lib/api');
    vi.mocked(apiGet).mockRejectedValue(
      new ApiRequestError(401, 'Unauthorized', 'UNAUTHORIZED'),
    );

    await useAuthStore.getState().initialize();

    const state = useAuthStore.getState();
    expect(state.isAuthenticated).toBe(false);
    expect(state.token).toBeNull();
    expect(state.user).toBeNull();
  });

  describe('login — email-first multi-tenant', () => {
    beforeEach(() => {
      vi.mocked(getStoredValue).mockResolvedValue(null);
      useAuthStore.setState({
        user: null, token: null, companies: [], companyId: null,
        isAuthenticated: false, isLoading: false,
      });
    });

    it('single-tenant email: authenticates and POSTs without tenant_id', async () => {
      vi.mocked(apiPost).mockResolvedValueOnce({
        user: mockUser, token: 'tok-1', tokenType: 'Bearer', deviceId: 'dev-1',
      });
      vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

      const result = await useAuthStore.getState().login('test@example.com', 'password123');

      expect(result).toEqual({ status: 'authenticated' });
      expect(apiPost).toHaveBeenCalledTimes(1);
      const body = vi.mocked(apiPost).mock.calls[0]![1] as Record<string, unknown>;
      expect(body).not.toHaveProperty('tenant_id');
      expect(useAuthStore.getState().isAuthenticated).toBe(true);
    });

    it('multi-tenant email with no stored tenant: returns requires_org_selection', async () => {
      vi.mocked(apiPost).mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [
          { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
          { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
        ],
      });

      const result = await useAuthStore.getState().login('multi@example.com', 'password123');

      expect(result).toEqual({
        status: 'requires_org_selection',
        organizations: [
          { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
          { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
        ],
      });
      expect(apiPost).toHaveBeenCalledTimes(1);
      expect(useAuthStore.getState().isAuthenticated).toBe(false);
      expect(apiGet).not.toHaveBeenCalled(); // never fetched companies
    });
  });

  describe('login — auto-select persisted tenant', () => {
    beforeEach(() => {
      useAuthStore.setState({
        user: null, token: null, companies: [], companyId: null,
        isAuthenticated: false, isLoading: false,
      });
    });

    it('persisted tenant in org list: re-POSTs with tenant_id, no picker', async () => {
      vi.mocked(getStoredValue).mockImplementation(async (key: string) =>
        key === 'login_tenant_id' ? 't-2' : null,
      );
      vi.mocked(apiPost)
        .mockResolvedValueOnce({
          requires_org_selection: true,
          organizations: [
            { tenant_id: 't-1', name: 'Alpha', slug: 'alpha' },
            { tenant_id: 't-2', name: 'Beta', slug: 'beta' },
          ],
        })
        .mockResolvedValueOnce({
          user: { ...mockUser, tenantId: 't-2' }, token: 'tok-2', tokenType: 'Bearer', deviceId: null,
        });
      vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

      const result = await useAuthStore.getState().login('multi@example.com', 'password123');

      expect(result).toEqual({ status: 'authenticated' });
      expect(apiPost).toHaveBeenCalledTimes(2);
      const secondBody = vi.mocked(apiPost).mock.calls[1]![1] as Record<string, unknown>;
      expect(secondBody.tenant_id).toBe('t-2');
      expect(useAuthStore.getState().isAuthenticated).toBe(true);
    });

    it('persisted tenant NOT in org list (stale): returns picker, no re-POST', async () => {
      vi.mocked(getStoredValue).mockImplementation(async (key: string) =>
        key === 'login_tenant_id' ? 't-stale' : null,
      );
      vi.mocked(apiPost).mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
      });

      const result = await useAuthStore.getState().login('multi@example.com', 'password123');

      expect(result).toEqual({
        status: 'requires_org_selection',
        organizations: [{ tenant_id: 't-1', name: 'Alpha', slug: 'alpha' }, { tenant_id: 't-2', name: 'Beta', slug: 'beta' }],
      });
      expect(apiPost).toHaveBeenCalledTimes(1);
    });

    it('manual pick with tenantId persists LOGIN_TENANT_ID', async () => {
      vi.mocked(getStoredValue).mockResolvedValue(null);
      vi.mocked(apiPost).mockResolvedValueOnce({
        user: { ...mockUser, tenantId: 't-2' }, token: 'tok-2', tokenType: 'Bearer', deviceId: null,
      });
      vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

      await useAuthStore.getState().login('multi@example.com', 'password123', { tenantId: 't-2' });

      expect(setStoredValue).toHaveBeenCalledWith('login_tenant_id', 't-2');
    });

    it('explicit tenant returns picker shape: throws, no loop, no auth', async () => {
      vi.mocked(apiPost).mockResolvedValueOnce({
        requires_org_selection: true, organizations: [],
      });

      await expect(
        useAuthStore.getState().login('multi@example.com', 'password123', { tenantId: 't-1' }),
      ).rejects.toThrow(/Unexpected login response/);
      expect(apiPost).toHaveBeenCalledTimes(1);
      expect(useAuthStore.getState().isAuthenticated).toBe(false);
    });

    it('wrong password after auto-select: error surfaced, tenant NOT cleared', async () => {
      vi.mocked(getStoredValue).mockImplementation(async (key: string) =>
        key === 'login_tenant_id' ? 't-2' : null,
      );
      vi.mocked(apiPost)
        .mockResolvedValueOnce({
          requires_org_selection: true,
          organizations: [{ tenant_id: 't-1', name: 'A', slug: 'a' }, { tenant_id: 't-2', name: 'B', slug: 'b' }],
        })
        .mockRejectedValueOnce(new ApiRequestError(422, 'The provided credentials are incorrect.', 'VALIDATION_ERROR'));

      await expect(
        useAuthStore.getState().login('multi@example.com', 'wrong'),
      ).rejects.toThrow(/credentials/);
      expect(removeStoredValue).not.toHaveBeenCalledWith('login_tenant_id');
      expect(useAuthStore.getState().isAuthenticated).toBe(false);
    });
  });
});
