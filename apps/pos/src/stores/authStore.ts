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
  legal_name?: string | null;
  tax_id?: string | null;
  address_street?: string | null;
  address_city?: string | null;
  address_postal_code?: string | null;
  countryCode: string;
  country_code?: string | null;
  currency: string;
  locale: string;
  timezone: string;
}

export interface Organization {
  tenant_id: string;
  name: string;
  slug: string;
}

interface LoginSuccessResponse {
  user: User;
  token: string;
  tokenType: string;
  deviceId: string | null;
}

interface OrgSelectionResponse {
  requires_org_selection: true;
  organizations: Organization[];
}

type LoginApiResponse = LoginSuccessResponse | OrgSelectionResponse;

export type LoginOutcome =
  | { status: 'authenticated' }
  | { status: 'requires_org_selection'; organizations: Organization[] };

/** Thrown when the backend returns an org-picker shape for a call that
 *  already supplied an explicit tenant_id (contract violation). Prevents
 *  any re-POST loop. */
export class UnexpectedLoginResponseError extends Error {
  constructor() {
    super('Unexpected login response: org selection returned for an explicit tenant.');
    this.name = 'UnexpectedLoginResponseError';
  }
}

function isOrgSelection(r: LoginApiResponse): r is OrgSelectionResponse {
  return 'requires_org_selection' in r;
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
    opts?: { signal?: AbortSignal; tenantId?: string },
  ) => Promise<LoginOutcome>;
  logout: () => void;
  unbindDevice: () => Promise<void>;
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
    opts?: { signal?: AbortSignal; tenantId?: string },
  ): Promise<LoginOutcome> => {
    if (get().isLoading) {
      throw new Error('A login is already in progress.');
    }

    const serverUrl = getServerUrl();
    set({ isLoading: true, serverUrl });

    // FIX 1: tiny abort-check helper — throws AbortError when the signal
    // has already fired. Called at every await boundary before committing
    // state or returning a result. Matches the pattern used in
    // checkSession / fetchCompanies.
    const abortIfCancelled = (signal?: AbortSignal): void => {
      if (signal?.aborted) throw new DOMException('Aborted', 'AbortError');
    };

    // FIX 3: hoist the duplicated request-body builder. The first POST
    // includes tenant_id ONLY when opts.tenantId was supplied (preserving
    // existing semantics). The auto-select re-POST passes storedTenantId.
    const buildLoginBody = (tenantId?: string): Record<string, unknown> => ({
      email,
      password,
      device_id: getDeviceId(),
      device_name: 'IziPOS Desktop',
      platform: getTauriPlatform(),
      ...(tenantId ? { tenant_id: tenantId } : {}),
    });

    // Local helper: given an authenticated login response, run the existing
    // transactional companies-fetch + persist, then best-effort persist the
    // device tenant hint. Reused by the auto-select re-POST (Task 3).
    const completeAuthentication = async (
      res: LoginSuccessResponse,
    ): Promise<void> => {
      const { user, token } = res;

      // T1.1: snapshot prior auth, write a temp token for the companies
      // fetch, restore verbatim on failure. (Unchanged behavior.)
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
        set(priorAuth);
        throw error;
      }

      // FIX 1 (abort gate 1): a cancel that fires after /user/companies
      // resolves but before we commit storage/state must still roll back
      // and propagate the abort — never commit half-auth state.
      if (opts?.signal?.aborted) {
        set(priorAuth);
        throw new DOMException('Aborted', 'AbortError');
      }

      await setStoredValue(StorageKeys.TOKEN, token);
      await setStoredValue(StorageKeys.USER, user);
      await setStoredValue(StorageKeys.COMPANIES, companies);

      set({ user, token, companies, isAuthenticated: true });

      if (companies.length === 1 && companies[0]) {
        const companyId = companies[0].id;
        await setStoredValue(StorageKeys.COMPANY_ID, companyId);
        set({ companyId });
      }

      // Best-effort, non-authoritative device tenant hint (MAJOR 4):
      // a failure here must NEVER throw or corrupt the committed auth state.
      try {
        await setStoredValue(StorageKeys.LOGIN_TENANT_ID, user.tenantId);
      } catch (e) {
        console.warn('[auth] failed to persist LOGIN_TENANT_ID (non-fatal):', e);
      }
    };

    try {
      const response = await apiPost<LoginApiResponse>(
        '/auth/login',
        buildLoginBody(opts?.tenantId),
        { signal: opts?.signal },
      );

      if (isOrgSelection(response)) {
        // Contract: an explicit-tenant call can never get a picker shape.
        if (opts?.tenantId) throw new UnexpectedLoginResponseError();

        // FIX 2: wrap LOGIN_TENANT_ID read as best-effort. A read failure
        // degrades to "no stored tenant" → show picker; must NOT crash login.
        let storedTenantId: string | null = null;
        try {
          storedTenantId = await getStoredValue<string>(StorageKeys.LOGIN_TENANT_ID);
        } catch (e) {
          console.warn('[auth] failed to read LOGIN_TENANT_ID (non-fatal, showing picker):', e);
          storedTenantId = null;
        }

        // FIX 1 (abort gate 2): check after the getStoredValue read and
        // before we either re-POST or return the picker.
        abortIfCancelled(opts?.signal);

        const match =
          storedTenantId != null &&
          response.organizations.some((o) => o.tenant_id === storedTenantId);

        if (match) {
          // Auto-select: exactly one extra POST (no recursion / no loop).
          const second = await apiPost<LoginApiResponse>(
            '/auth/login',
            buildLoginBody(storedTenantId ?? undefined),
            { signal: opts?.signal },
          );

          if (isOrgSelection(second)) throw new UnexpectedLoginResponseError();
          await completeAuthentication(second);
          return { status: 'authenticated' };
        }

        // Stale or no stored tenant → let the UI show the picker.
        return { status: 'requires_org_selection', organizations: response.organizations };
      }

      await completeAuthentication(response);
      return { status: 'authenticated' };
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

  // Sub-Spec B: deliberate, manager-initiated full device sign-out. Unlike
  // logout() (used by automatic 401s / setup / bootstrap, which keep the
  // device's tenant binding), this ALSO clears LOGIN_TENANT_ID so the next
  // login re-resolves the tenant email-first. Auth-owned only — feature-store
  // cleanup is done by teardownPosSessionStores() at the UI layer to avoid an
  // operatorStore<->authStore import cycle.
  //
  // FIX 1 (MAJOR): clear LOGIN_TENANT_ID FIRST (awaited) so the key is gone
  // before logout() flips isAuthenticated→false and routes to login. A
  // fire-and-forget delete after logout() can race against the next login
  // attempt, letting the old binding survive an unbind.
  unbindDevice: async () => {
    try {
      await removeStoredValue(StorageKeys.LOGIN_TENANT_ID);
    } catch (e) {
      console.warn('[auth] failed to clear LOGIN_TENANT_ID during unbind:', e);
    }
    get().logout();
  },
}));
