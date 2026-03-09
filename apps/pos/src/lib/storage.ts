import { load, type Store } from '@tauri-apps/plugin-store';

let store: Store | null = null;

async function getStore(): Promise<Store> {
  if (!store) {
    store = await load('izipos-settings.json');
  }
  return store;
}

export async function getStoredValue<T>(key: string): Promise<T | null> {
  const s = await getStore();
  const value = await s.get<T>(key);
  return value ?? null;
}

export async function setStoredValue<T>(key: string, value: T): Promise<void> {
  const s = await getStore();
  await s.set(key, value);
}

export async function removeStoredValue(key: string): Promise<void> {
  const s = await getStore();
  await s.delete(key);
}

export async function clearStore(): Promise<void> {
  const s = await getStore();
  await s.clear();
}

// Typed helpers for common keys
export const StorageKeys = {
  TOKEN: 'auth_token',
  SERVER_URL: 'server_url',
  USER: 'user',
  COMPANY_ID: 'company_id',
  COMPANIES: 'companies',
  TERMINAL: 'terminal',
  PENDING_TERMINAL_ID: 'pending_terminal_id',
} as const;
