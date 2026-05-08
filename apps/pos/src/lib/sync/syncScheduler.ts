import type Database from '@tauri-apps/plugin-sql';
import { runFullSync, type SyncResult } from './syncService';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useAuthStore } from '@/stores/authStore';
import { useSyncStore } from '@/stores/syncStore';
import { useProductStore } from '@/stores/productStore';
import { usePaymentStore } from '@/stores/paymentStore';
import { useTerminalStore } from '@/stores/terminalStore';
import { serializeErrorForLog } from '@/lib/errorLogging';

const BASE_INTERVAL_MS = 60_000; // 1 minute
const MAX_INTERVAL_MS = 5 * 60_000; // 5 minutes
const BACKOFF_MULTIPLIER = 2;

export class SyncScheduler {
  private intervalId: ReturnType<typeof setInterval> | null = null;
  private currentInterval = BASE_INTERVAL_MS;
  private db: Database;
  private terminalId: string;
  private unsubscribeConnectivity: (() => void) | null = null;

  constructor(db: Database, terminalId: string) {
    this.db = db;
    this.terminalId = terminalId;
  }

  start(): void {
    if (this.intervalId) return;

    // Run initial sync
    void this.tick();

    this.intervalId = setInterval(() => {
      void this.tick();
    }, this.currentInterval);

    // Trigger immediate sync when connectivity resumes
    this.unsubscribeConnectivity = useConnectivityStore.subscribe(
      (state, prev) => {
        if (state.isOnline && !prev.isOnline) {
          console.info('[SyncScheduler] Connectivity restored, triggering immediate sync');
          this.resetInterval();
          void this.tick();
        }
      },
    );
  }

  stop(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = null;
    }
    if (this.unsubscribeConnectivity) {
      this.unsubscribeConnectivity();
      this.unsubscribeConnectivity = null;
    }
    this.currentInterval = BASE_INTERVAL_MS;
  }

  async syncNow(): Promise<SyncResult | null> {
    return this.tick();
  }

  private async tick(): Promise<SyncResult | null> {
    const { isOnline } = useConnectivityStore.getState();
    if (!isOnline) return null;

    const { isSyncing } = useSyncStore.getState();
    if (isSyncing) return null;

    useSyncStore.getState().startSync();

    try {
      const result = await runFullSync(this.db, this.terminalId);

      // Refresh in-memory product store from SQLite after sync pulls new data
      useProductStore.getState().refreshFromSQLite().catch((err: unknown) => {
        console.error('[SyncScheduler] productStore refreshFromSQLite failed:', err);
      });
      // T0.5: rehydrate paymentStore from SQLite alongside productStore.
      // Fire-and-forget in parallel — independent failure modes (e.g. a
      // transient SQLite lock on one shouldn't starve the other). Each
      // refresh has its own internal try/catch that uses
      // `serializeErrorForLog`, so reaching this `.catch` would mean the
      // promise itself rejected (extremely unlikely given the inner guard).
      // T0.5 Codex round-1 (g): outer `.catch` uses `serializeErrorForLog`
      // to bound the log payload — a raw `err` reference could spread an
      // axios-shaped error with `config.url` / auth headers into devtools.
      usePaymentStore.getState().refreshFromSQLite().catch((err: unknown) => {
        console.error('[SyncScheduler] paymentStore refreshFromSQLite failed', {
          ...serializeErrorForLog(err),
        });
      });

      if (result.chainBreak) {
        // Try to identify the last successfully synced receipt for operator context
        const { getLastSyncedReceiptNumber } = await import('@/lib/db/repositories/offlineReceiptRepository');
        const lastSynced = await getLastSyncedReceiptNumber(this.db);
        useSyncStore.getState().setChainBreak(true, lastSynced);
      }

      useSyncStore.getState().completeSync(result);

      // After any sync that pulled terminal_state, refresh the hashChainReady flag so
      // cold-start banners disappear as soon as the terminal is bootstrapped.
      if (result.terminalStatePulled) {
        await useTerminalStore.getState().refreshHashChainReady();
      }

      // If sync errors contain "Unauthorized" (401), the token may be expired.
      // Re-validate via /auth/me — on confirmed 401, logout so user can re-login.
      const hasUnauthorized = result.errors.some((e) => e.includes('Unauthorized'));
      if (hasUnauthorized) {
        console.warn('[SyncScheduler] Sync returned 401 errors — re-validating session');
        try {
          await useAuthStore.getState().checkSession();
        } catch (sessionError) {
          const { ApiRequestError } = await import('@/lib/api');
          if (sessionError instanceof ApiRequestError && sessionError.status === 401) {
            console.warn('[SyncScheduler] Token confirmed expired — logging out');
            useAuthStore.getState().logout();
          } else {
            // Network error → token might still be valid when server is reachable.
            // Log at warn so intermittent outages don't drown devtools in errors
            // (Codex review 2026-05-08 finding (h)). checkSession runs every
            // sync tick (~30s); a 10-min outage produces ~20 entries.
            console.warn('[POS][syncScheduler] checkSession failed (non-401, transient)', {
              ...serializeErrorForLog(sessionError),
            });
          }
        }
      }

      // Reset backoff on success
      if (result.receiptsFailed === 0 && !hasUnauthorized) {
        this.resetInterval();
      } else {
        this.backoff();
      }

      return result;
    } catch (error) {
      console.error('[POS][syncScheduler] tick threw', {
        ...serializeErrorForLog(error),
        currentInterval: this.currentInterval,
      });
      const message = error instanceof Error ? error.message : 'Sync failed';
      useSyncStore.getState().failSync(message);
      this.backoff();
      return null;
    }
  }

  private backoff(): void {
    this.currentInterval = Math.min(
      this.currentInterval * BACKOFF_MULTIPLIER,
      MAX_INTERVAL_MS,
    );
    this.restart();
  }

  private resetInterval(): void {
    if (this.currentInterval !== BASE_INTERVAL_MS) {
      this.currentInterval = BASE_INTERVAL_MS;
      this.restart();
    }
  }

  private restart(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = setInterval(() => {
        void this.tick();
      }, this.currentInterval);
    }
  }
}
