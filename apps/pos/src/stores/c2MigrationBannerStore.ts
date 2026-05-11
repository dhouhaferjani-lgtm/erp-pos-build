import { create } from 'zustand';
import { getStoredValue, setStoredValue, StorageKeys } from '@/lib/storage';

/**
 * Reactive mirror of `StorageKeys.C2_MIGRATION_BANNER` so `C2MigrationBanner`
 * can react to a POST-MOUNT migration dump.
 *
 * Why this exists (Codex PR #118 round-5 P2):
 *
 *   The migration's destructive cart-line dump runs at AppRouter mount
 *   time. When the company-config fetch is unavailable on a cached/offline
 *   boot, the migration returns `{ deferred: true }` and `AppShell` mounts
 *   anyway (fail-open per the safety contract). A 30s retry then re-runs
 *   the migration. If that retry dumps carts, the in-memory `holdStore` is
 *   reconciled (`App.tsx`) and the Tauri Store flag is set to `true` — but
 *   `C2MigrationBanner` had already mounted and read the flag as `false`,
 *   so its local `useState` never re-syncs and the cashier sees the held
 *   carts disappear without any notification.
 *
 *   The Tauri Store is the persistent boundary; this Zustand store is the
 *   reactive in-memory layer. Both are written on every transition so the
 *   banner survives a process restart AND re-renders within the current
 *   session when a deferred retry succeeds.
 *
 * Lifecycle:
 *
 *   1. App boot → `hydrate()` reads the persisted flag (covers the case
 *      where the previous session crashed before the cashier dismissed
 *      the banner). Banner reads `visible` via selector.
 *   2. Migration dumps at mount → `setBannerPending(true)` in
 *      `runC2BareCartLineDump` writes Tauri Store; `hydrate()` running on
 *      mount picks it up.
 *   3. DEFERRED migration retry dumps after mount → `App.tsx`'s post-dump
 *      branch calls `show()` which flips the Zustand flag in-memory. The
 *      migration ALSO writes Tauri Store for cross-boot durability.
 *   4. Cashier dismisses → `dismiss()` writes Tauri Store false + clears
 *      the Zustand flag.
 */
interface C2MigrationBannerState {
  visible: boolean;
  /**
   * Read the persisted flag once on app boot. Idempotent — safe to call
   * multiple times; only transitions the in-memory flag when the storage
   * value is `true`.
   */
  hydrate: () => Promise<void>;
  /**
   * Imperatively mark the banner visible. Used by `App.tsx`'s post-mount
   * migration retry branch so the banner re-renders without depending on
   * a Tauri Store change listener (which the abstraction does not expose).
   */
  show: () => void;
  /**
   * Clear both the in-memory flag and the persisted flag.
   */
  dismiss: () => Promise<void>;
}

export const useC2MigrationBannerStore = create<C2MigrationBannerState>()((set) => ({
  visible: false,

  hydrate: async () => {
    const pending = await getStoredValue<boolean>(StorageKeys.C2_MIGRATION_BANNER);
    if (pending === true) {
      set({ visible: true });
    }
  },

  show: () => {
    set({ visible: true });
  },

  dismiss: async () => {
    await setStoredValue(StorageKeys.C2_MIGRATION_BANNER, false);
    set({ visible: false });
  },
}));
