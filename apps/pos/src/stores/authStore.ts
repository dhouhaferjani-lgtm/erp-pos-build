import { create } from 'zustand';
import { platform } from '@tauri-apps/plugin-os';
import { apiGet, apiPost, ApiRequestError } from '@/lib/api';
import { disconnectEcho } from '@/lib/echo';
import {
  getStoredValue,
  setStoredValue,
  removeStoredValue,
  StorageKeys,
} from '@/lib/storage';
import { getDeviceId } from '@/lib/device';
import { useTerminalStore } from '@/stores/terminalStore';
import { clearScanCache } from '@/lib/scan/scanResolutionCache';

export interface User {
  id: string;
  name: string;
  email: string;
  tenantId: string;
  phone: string | null;
  status: string;
  locale: string | null;
  timezone: string | null;
  roles: string[];
  permissions: string[];
  emailVerified: boolean;
}

export interface Company {
  id: string;
  name: string;
  legalName: string;
  countryCode: string;
  currency: string;
  locale: string;
  timezone: string;
}

interface AuthState {
  user: User | null;
  token: string | null;
  serverUrl: string | null;
  companyId: string | null;
  companies: Company[];
  isAuthenticated: boolean;
  isLoading: boolean;
  isInitialized: boolean;
}

interface AuthActions {
  login: (
    email: string,
    password: string,
    opts?: { signal?: AbortSignal },
  ) => Promise<void>;
  logout: () => void;
  checkSession: (opts?: { signal?: AbortSignal }) => Promise<void>;
  setCompany: (companyId: string) => void;
  initialize: (opts?: { signal?: AbortSignal }) => Promise<void>;
  refreshCompanyConfig: () => Promise<void>;
  fetchCompanies: (opts?: { signal?: AbortSignal }) => Promise<void>;
}

type AuthStore = AuthState & AuthActions;

const initialState: AuthState = {
  user: null,
  token: null,
  serverUrl: null,
  companyId: null,
  companies: [],
  isAuthenticated: false,
  isLoading: false,
  isInitialized: false,
};

function getTauriPlatform(): string {
  const p = platform();
  // Map Tauri platform names to backend-accepted values
  const platformMap: Record<string, string> = {
    macos: 'macos',
    windows: 'windows',
    linux: 'linux',
  };
  return platformMap[p] ?? 'macos';
}

function getServerUrl(): string {
  const envUrl = import.meta.env.VITE_API_URL as string | undefined;
  if (envUrl) return envUrl.replace(/\/+$/, '');
  // Fallback for development
  return 'http://localhost:8002';
}

export const useAuthStore = create<AuthStore>()((set, get) => ({
  ...initialState,

  initialize: async (opts?: { signal?: AbortSignal }) => {
    const serverUrl = getServerUrl();
    set({ isLoading: true, serverUrl });
    try {
      const token = await getStoredValue<string>(StorageKeys.TOKEN);
      const user = await getStoredValue<User>(StorageKeys.USER);
      const companyId = await getStoredValue<string>(StorageKeys.COMPANY_ID);
      const companies = await getStoredValue<Company[]>(StorageKeys.COMPANIES);

      if (token && user) {
        set({
          token,
          user,
          companyId,
          companies: companies ?? [],
          isAuthenticated: true,
        });

        // Validate the token is still valid (only logout on 401, not network errors)
        try {
          await get().checkSession({ signal: opts?.signal });
        } catch (error) {
          if (opts?.signal?.aborted) return;
          if (error instanceof ApiRequestError && error.status === 401) {
            // Token is genuinely expired/invalid — must re-login
            get().logout();
          } else {
            // Network error or timeout — keep existing auth state for offline use
            console.warn('[auth] Session check failed (likely offline), keeping cached auth:', error);
          }
        }
      }
    } catch (error) {
      console.error('Failed to initialize auth:', error);
    } finally {
      if (!opts?.signal?.aborted) {
        set({ isLoading: false, isInitialized: true });
      }
    }
  },

  login: async (
    email: string,
    password: string,
    opts?: { signal?: AbortSignal },
  ) => {
    const serverUrl = getServerUrl();
    set({ isLoading: true, serverUrl });

    try {
      console.log('[auth] Attempting login to', serverUrl);

      const response = await apiPost<{
        user: User;
        token: string;
        tokenType: string;
        deviceId: string | null;
      }>('/auth/login', {
        email,
        password,
        device_id: getDeviceId(),
        device_name: 'IziPOS Desktop',
        platform: getTauriPlatform(),
      }, { signal: opts?.signal });

      console.log('[auth] Login successful, got token');

      const { user, token } = response;

      // T1.1 Step 1.1: transactional persist. The previous flow persisted
      // TOKEN+USER and flipped isAuthenticated=true BEFORE fetching
      // /user/companies. A network drop in the small window between the
      // login POST returning and companies resolving left a half-finished
      // session: TOKEN+USER persisted in Tauri Store, isAuthenticated:true
      // in memory, companies:[]. On the next boot, AppRouter routed to
      // TerminalSetupPage which immediately threw because companyId was
      // null. We now hold all in-memory and on-disk state changes until
      // BOTH /auth/login AND /user/companies have resolved successfully —
      // a failure in either leaves the prior auth state untouched.
      console.log('[auth] Fetching companies...');

      // Use a short-lived auth header for this single fetch — apiGet reads
      // the in-store token via getHeaders(), but we haven't committed it
      // yet. Codex round-1 finding (c): snapshot the FULL prior auth
      // state before the temp write so a failure restores exactly what
      // was there. Otherwise re-attempting login() over an already-valid
      // session corrupts the in-memory snapshot when /user/companies
      // fails (token gets nulled while user/companies/isAuthenticated
      // still describe the previous session).
      const priorAuth = {
        token: get().token,
        user: get().user,
        companies: get().companies,
        companyId: get().companyId,
        isAuthenticated: get().isAuthenticated,
      };
      set({ token });

      let companies: Company[];
      try {
        companies = await apiGet<Company[]>('/user/companies', undefined, {
          signal: opts?.signal,
        });
      } catch (error) {
        // Restore the prior in-memory auth verbatim; we never persisted
        // anything new and we must not corrupt a previously-valid
        // session. Disk persistence is untouched.
        set(priorAuth);
        throw error;
      }

      console.log('[auth] Got companies:', companies.length);

      // Persist auth data only after BOTH calls succeeded.
      await setStoredValue(StorageKeys.TOKEN, token);
      await setStoredValue(StorageKeys.USER, user);
      await setStoredValue(StorageKeys.COMPANIES, companies);

      set({
        user,
        token,
        companies,
        isAuthenticated: true,
      });

      // Auto-select if single company
      if (companies.length === 1 && companies[0]) {
        const companyId = companies[0].id;
        await setStoredValue(StorageKeys.COMPANY_ID, companyId);
        set({ companyId });
      }
    } catch (error) {
      console.error('[auth] Login failed:', error);
      throw error;
    } finally {
      set({ isLoading: false });
    }
  },

  checkSession: async (opts?: { signal?: AbortSignal }) => {
    const response = await apiGet<User>('/auth/me', undefined, {
      signal: opts?.signal,
    });
    if (opts?.signal?.aborted) return;
    set({ user: response, isAuthenticated: true });
    await setStoredValue(StorageKeys.USER, response);
  },

  setCompany: (companyId: string) => {
    set({ companyId });
    void setStoredValue(StorageKeys.COMPANY_ID, companyId);
  },

  // T1.1 Step 1.2: re-fetch /user/companies for the recovery screen
  // (rendered when isAuthenticated && companies.length === 0). Throws on
  // failure so the component can render the typed-fields-only error UI.
  //
  // Codex round-1 finding (b): a stale companyId left over from a prior
  // session must be cleared when the refreshed companies no longer
  // include it. Otherwise apiGet's getHeaders() keeps sending
  // X-Company-Id pointing at a company the user has been revoked from,
  // AND AppRouter skips the multi-company-selection branch (which gates
  // on `!companyId`) — leaving the user stuck on a phantom company.
  fetchCompanies: async (opts?: { signal?: AbortSignal }) => {
    const companies = await apiGet<Company[]>('/user/companies', undefined, {
      signal: opts?.signal,
    });
    if (opts?.signal?.aborted) return;
    await setStoredValue(StorageKeys.COMPANIES, companies);

    const currentCompanyId = get().companyId;
    const stillValid =
      currentCompanyId !== null &&
      companies.some((c) => c.id === currentCompanyId);

    if (!stillValid) {
      // Drop the stale id so the router routes correctly and apiGet
      // stops sending the wrong X-Company-Id header.
      await removeStoredValue(StorageKeys.COMPANY_ID);
      set({ companies, companyId: null });
    } else {
      set({ companies });
    }

    // Auto-select only when exactly one company is returned AND the
    // user does not already have a valid selection — this prevents
    // overwriting a deliberate prior selection in edge cases.
    if (companies.length === 1 && companies[0] && !stillValid) {
      const companyId = companies[0].id;
      await setStoredValue(StorageKeys.COMPANY_ID, companyId);
      set({ companyId });
    }
  },

  refreshCompanyConfig: async () => {
    try {
      const config = await apiGet<import('@/types/companyConfig').CompanyConfig>('/company/config');
      const { useProductStore } = await import('@/stores/productStore');
      useProductStore.setState({ companyConfig: config });
    } catch (error) {
      // Graceful: keep existing cached config; log at debug level only.
      console.debug('[auth] refreshCompanyConfig failed (keeping cached config):', error);
    }
  },

  logout: () => {
    disconnectEcho();
    set({ ...initialState, serverUrl: getServerUrl(), isInitialized: true });
    void removeStoredValue(StorageKeys.TOKEN);
    void removeStoredValue(StorageKeys.USER);
    void removeStoredValue(StorageKeys.COMPANY_ID);
    void removeStoredValue(StorageKeys.COMPANIES);
    void removeStoredValue(StorageKeys.TERMINAL);
    void removeStoredValue(StorageKeys.PENDING_TERMINAL_ID);
    useTerminalStore.getState().reset();

    // Codex round-3 P2 (PR #98) — clear the tenant-scoped scan LRU
    // from the CENTRAL logout path, not only from the UI flows that
    // happen to call productStore.reset() first. The sync scheduler's
    // confirmed-401 path goes through here directly, so this is the
    // canonical session-ending hook. Companion clear in
    // productStore.reset() stays as defense-in-depth for code paths
    // that reset products without calling logout (e.g. PinEntryPage's
    // explicit user-switch flow).
    clearScanCache();

    // T2.4 Day 2 (Codex PR #108 r1 P2) — every logout path (sign-out
    // from BootstrapErrorScreen, sync-scheduler 401, PinEntryPage,
    // and this store's own initialize-time 401) must also reset the
    // bootstrap state machine. Without this, a logout while bootstrap
    // is in `phase === 'error'` would leave AppRouter rendering the
    // error screen indefinitely because that branch is checked before
    // the `!isAuthenticated` LoginPage branch. Dynamic import keeps
    // the bootstrapStore → authStore dependency one-way; the runtime
    // chunk is tiny.
    void import('@/stores/bootstrapStore').then(({ useBootstrapStore }) => {
      useBootstrapStore.getState().reset();
    });
  },
}));
