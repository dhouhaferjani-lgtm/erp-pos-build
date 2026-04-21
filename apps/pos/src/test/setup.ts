import '@testing-library/jest-dom/vitest';
import { beforeEach } from 'vitest';

// jsdom v27+ ships a Storage object whose methods are not accessible,
// which breaks zustand/persist middleware during test setup.
// Provide a minimal in-memory Storage polyfill so persisted stores work.
class MemoryStorage implements Storage {
  private store = new Map<string, string>();
  get length(): number { return this.store.size; }
  clear(): void { this.store.clear(); }
  getItem(key: string): string | null { return this.store.get(key) ?? null; }
  key(index: number): string | null { return Array.from(this.store.keys())[index] ?? null; }
  removeItem(key: string): void { this.store.delete(key); }
  setItem(key: string, value: string): void { this.store.set(key, value); }
}

Object.defineProperty(globalThis, 'localStorage', {
  value: new MemoryStorage(),
  writable: true,
  configurable: true,
});

Object.defineProperty(globalThis, 'sessionStorage', {
  value: new MemoryStorage(),
  writable: true,
  configurable: true,
});

beforeEach(() => {
  localStorage.clear();
  sessionStorage.clear();
});
