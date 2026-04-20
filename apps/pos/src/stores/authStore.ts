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
  login: (email: string, password: string) => Promise<void>;
  logout: () => void;
  checkSession: () => Promise<void>;
  setCompany: (companyId: string) => void;
  initialize: () => Promise<void>;
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

  initialize: async () => {
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
          await get().checkSession();
        } catch (error) {
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
      set({ isLoading: false, isInitialized: true });
    }
  },

  login: async (email: string, password: string) => {
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
      });

      console.log('[auth] Login successful, got token');

      const { user, token } = response;

      // Persist auth data
      await setStoredValue(StorageKeys.TOKEN, token);
      await setStoredValue(StorageKeys.USER, user);

      set({
        user,
        token,
        isAuthenticated: true,
      });

      console.log('[auth] Fetching companies...');

      // Fetch user companies
      const companies = await apiGet<Company[]>('/user/companies');
      await setStoredValue(StorageKeys.COMPANIES, companies);

      console.log('[auth] Got companies:', companies.length);

      set({ companies });

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

  checkSession: async () => {
    const response = await apiGet<User>('/auth/me');
    set({ user: response, isAuthenticated: true });
    await setStoredValue(StorageKeys.USER, response);
  },

  setCompany: (companyId: string) => {
    set({ companyId });
    void setStoredValue(StorageKeys.COMPANY_ID, companyId);
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
  },
}));
