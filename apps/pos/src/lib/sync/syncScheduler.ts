import type Database from '@tauri-apps/plugin-sql';
import { runFullSync, type SyncResult } from './syncService';
import { useConnectivityStore } from '@/stores/connectivityStore';
import { useSyncStore } from '@/stores/syncStore';
import { useProductStore } from '@/stores/productStore';

const BASE_INTERVAL_MS = 60_000; // 1 minute
const MAX_INTERVAL_MS = 5 * 60_000; // 5 minutes
const BACKOFF_MULTIPLIER = 2;

export class SyncScheduler {
  private intervalId: ReturnType<typeof setInterval> | null = null;
  private currentInterval = BASE_INTERVAL_MS;
  private db: Database;
  private terminalId: string;

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
  }

  stop(): void {
    if (this.intervalId) {
      clearInterval(this.intervalId);
      this.intervalId = null;
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
        console.error('[SyncScheduler] refreshFromSQLite failed:', err);
      });

      useSyncStore.getState().completeSync(result);

      // Reset backoff on success
      if (result.receiptsFailed === 0) {
        this.resetInterval();
      } else {
        this.backoff();
      }

      return result;
    } catch (error) {
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
