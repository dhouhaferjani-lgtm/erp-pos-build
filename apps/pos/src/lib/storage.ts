import { load, type Store } from '@tauri-apps/plugin-store';
import { invoke } from '@tauri-apps/api/core';

let store: Store | null = null;

async function getStore(): Promise<Store> {
  if (!store) {
    store = await load('izipos-settings.json');
  }
  return store;
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
  SHIFT: 'current_shift',
  C2_MIGRATION_BANNER: 'c2_migration_banner',
  // Sub-Spec A: device-bound preferred tenant for email-first login.
  // Non-authoritative hint (auto-selects the org picker); NOT encrypted.
  LOGIN_TENANT_ID: 'login_tenant_id',
} as const;

const ENCRYPTED_KEYS = new Set<string>([StorageKeys.TOKEN]);

export async function getStoredValue<T>(key: string): Promise<T | null> {
  const s = await getStore();
  const value = await s.get<T>(key);
  if (value == null) return null;

  if (ENCRYPTED_KEYS.has(key) && typeof value === 'string') {
    try {
      const decrypted = await invoke<string>('decrypt_secret', { encrypted: value });
      return decrypted as T;
    } catch {
      // Migration: if value looks like a raw JWT, encrypt it in-place and return
      if (value.startsWith('ey')) {
        try {
          const encrypted = await invoke<string>('encrypt_secret', { plaintext: value });
          await s.set(key, encrypted);
          return value as T;
        } catch {
          // Encryption failed — return null to force re-login
        }
      }
      return null;
    }
  }

  return value;
}

export async function setStoredValue<T>(key: string, value: T): Promise<void> {
  const s = await getStore();

  if (ENCRYPTED_KEYS.has(key) && typeof value === 'string') {
    const encrypted = await invoke<string>('encrypt_secret', { plaintext: value });
    await s.set(key, encrypted);
    return;
  }

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
