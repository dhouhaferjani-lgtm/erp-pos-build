import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { User, Company } from '../authStore';

// ── Mocks ─────────────────────────────────────────────────────────────────
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
  return { apiGet: vi.fn(), apiPost: vi.fn(), ApiRequestError };
});

vi.mock('@/lib/echo', () => ({ disconnectEcho: vi.fn() }));

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

const recordAuditEvent = vi.fn().mockResolvedValue(undefined);
vi.mock('@/lib/audit/recordAuditEvent', () => ({
  recordAuditEvent: (...args: unknown[]) => recordAuditEvent(...args),
}));

import { apiGet, apiPost } from '@/lib/api';
import { getStoredValue } from '@/lib/storage';
import { useAuthStore } from '../authStore';

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
    name: 'Acme Co',
    legalName: 'Acme Co Ltd',
    countryCode: 'FR',
    currency: 'EUR',
    locale: 'fr',
    timezone: 'Europe/Paris',
  },
];

function lastCallOfType(type: string): Record<string, unknown> | undefined {
  for (let i = recordAuditEvent.mock.calls.length - 1; i >= 0; i--) {
    const arg = recordAuditEvent.mock.calls[i]![0] as Record<string, unknown>;
    if (arg.type === type) return arg;
  }
  return undefined;
}

function resetAuth(): void {
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
}

describe('authStore — Task 7 audit emits', () => {
  beforeEach(() => {
    resetAuth();
    vi.clearAllMocks();
    recordAuditEvent.mockResolvedValue(undefined);
    vi.mocked(getStoredValue).mockResolvedValue(null as never);
  });

  describe('pos.login', () => {
    it('emits pos.login on a single-tenant login with explicit resolved context', async () => {
      vi.mocked(apiPost).mockResolvedValueOnce({
        user: mockUser,
        token: 'jwt-123',
        tokenType: 'Bearer',
        deviceId: 'device-123',
      });
      vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

      await useAuthStore.getState().login('test@example.com', 'password');

      const call = lastCallOfType('pos.login');
      expect(call).toBeDefined();
      expect(call!.aggregateType).toBe('PosSession');
      expect(call!.aggregateId).toBe('device-123');
      // Context passed EXPLICITLY (store may lag the commit).
      expect(call!.tenantId).toBe('tenant-1');
      expect(call!.operatorId).toBe('user-1');
      expect(call!.companyId).toBe('company-1'); // auto-selected (single company)
      const payload = call!.payload as Record<string, unknown>;
      expect(payload.multi_tenant).toBe(false);
      expect(payload.via_picker).toBe(false);
    });

    it('via_picker is true when an explicit tenantId is supplied to login', async () => {
      vi.mocked(apiPost).mockResolvedValueOnce({
        user: mockUser,
        token: 'jwt-123',
        tokenType: 'Bearer',
        deviceId: 'device-123',
      });
      vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

      await useAuthStore
        .getState()
        .login('test@example.com', 'password', { tenantId: 'tenant-1' });

      const payload = lastCallOfType('pos.login')!.payload as Record<string, unknown>;
      expect(payload.via_picker).toBe(true);
    });

    it('multi_tenant and via_picker are true on the auto-select re-POST path', async () => {
      // First POST returns the org picker; stored tenant matches → re-POST.
      vi.mocked(apiPost)
        .mockResolvedValueOnce({
          requires_org_selection: true,
          organizations: [{ tenant_id: 'tenant-1', name: 'Acme', slug: 'acme' }],
        })
        .mockResolvedValueOnce({
          user: mockUser,
          token: 'jwt-123',
          tokenType: 'Bearer',
          deviceId: 'device-123',
        });
      vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);
      vi.mocked(getStoredValue).mockResolvedValue('tenant-1' as never);

      await useAuthStore.getState().login('multi@example.com', 'password');

      const payload = lastCallOfType('pos.login')!.payload as Record<string, unknown>;
      expect(payload.multi_tenant).toBe(true);
      expect(payload.via_picker).toBe(true);
    });

    it('does NOT emit pos.login when login fails', async () => {
      vi.mocked(apiPost).mockRejectedValue(new Error('Invalid credentials'));

      await expect(
        useAuthStore.getState().login('bad@example.com', 'wrong'),
      ).rejects.toThrow();

      expect(lastCallOfType('pos.login')).toBeUndefined();
    });

    it('does NOT emit pos.login when only an org picker is returned (no auth)', async () => {
      vi.mocked(apiPost).mockResolvedValueOnce({
        requires_org_selection: true,
        organizations: [{ tenant_id: 'tenant-9', name: 'X', slug: 'x' }],
      });

      const outcome = await useAuthStore
        .getState()
        .login('multi@example.com', 'password');

      expect(outcome.status).toBe('requires_org_selection');
      expect(lastCallOfType('pos.login')).toBeUndefined();
    });

    it('login still succeeds when the audit emit rejects', async () => {
      recordAuditEvent.mockRejectedValue(new Error('audit down'));
      vi.mocked(apiPost).mockResolvedValueOnce({
        user: mockUser,
        token: 'jwt-123',
        tokenType: 'Bearer',
        deviceId: 'device-123',
      });
      vi.mocked(apiGet).mockResolvedValueOnce(mockCompanies);

      const outcome = await useAuthStore.getState().login('test@example.com', 'password');

      expect(outcome.status).toBe('authenticated');
      expect(useAuthStore.getState().isAuthenticated).toBe(true);
    });
  });

  describe('pos.device_unbind', () => {
    it('emits pos.device_unbind capturing tenant/operator BEFORE teardown', async () => {
      useAuthStore.setState({
        user: mockUser,
        token: 'jwt-123',
        companyId: 'company-1',
        isAuthenticated: true,
      });

      await useAuthStore.getState().unbindDevice();

      const call = lastCallOfType('pos.device_unbind');
      expect(call).toBeDefined();
      expect(call!.aggregateType).toBe('PosSession');
      expect(call!.aggregateId).toBe('device-123');
      expect(call!.tenantId).toBe('tenant-1');
      expect(call!.operatorId).toBe('user-1');
      expect(call!.companyId).toBe('company-1');
      // State is torn down by the time the emit fires.
      expect(useAuthStore.getState().isAuthenticated).toBe(false);
      expect(useAuthStore.getState().user).toBeNull();
    });

    it('does NOT emit pos.device_unbind when there was no authenticated user', async () => {
      await useAuthStore.getState().unbindDevice();
      expect(lastCallOfType('pos.device_unbind')).toBeUndefined();
    });

    it('unbind still completes when the audit emit rejects', async () => {
      recordAuditEvent.mockRejectedValue(new Error('audit down'));
      useAuthStore.setState({ user: mockUser, token: 'jwt-123', isAuthenticated: true });

      await expect(useAuthStore.getState().unbindDevice()).resolves.toBeUndefined();
      expect(useAuthStore.getState().isAuthenticated).toBe(false);
    });
  });
});
