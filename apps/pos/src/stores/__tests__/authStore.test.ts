import { describe, it, expect, beforeEach, vi } from 'vitest';
import { useAuthStore } from '../authStore';
import type { User, Company } from '../authStore';

// Mock dependencies
vi.mock('@tauri-apps/plugin-os', () => ({
  platform: vi.fn(() => 'macos'),
}));

vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

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
  },
}));

vi.mock('@/lib/device', () => ({
  getDeviceId: vi.fn(() => 'device-123'),
}));

import { apiGet, apiPost } from '@/lib/api';
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
});
