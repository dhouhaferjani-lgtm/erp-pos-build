import { useEffect, useRef, useState, useCallback } from 'react';
import { AppState } from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import { useDraftCountingStore } from '../store/draftCountingStore';
import { countingApi } from '../api/countingApi';
import type { DraftCounting } from '../store/draftCountingStore';

// Batch configuration
const BATCH_SIZE_DRAFTS = 50; // Max drafts per batch request
const BATCH_SIZE_PRODUCTS = 100; // Max products per batch request
const SYNC_INTERVAL_MS = 30000; // Sync every 30 seconds
const MAX_RETRY_ATTEMPTS = 3;
const RETRY_DELAY_MS = 5000;

export interface SyncProgress {
  stage: 'drafts' | 'products' | 'updates' | 'complete' | 'idle';
  current: number;
  total: number;
  message?: string;
}

/**
 * Draft Sync Service
 *
 * Handles background synchronization of draft counting operations with the server.
 * Features:
 * - Batch synchronization (50 drafts, 100 products per request)
 * - Automatic retry with exponential backoff
 * - Progress tracking for UI feedback
 * - Handles new drafts, product additions, and draft updates
 */
export class DraftSyncService {
  private isSyncing = false;
  private retryAttempts = new Map<string, number>();
  private progressCallback?: (progress: SyncProgress) => void;

  /**
   * Synchronize all pending draft operations
   */
  async syncAll(onProgress?: (progress: SyncProgress) => void): Promise<void> {
    if (this.isSyncing) {
      console.log('[DraftSync] Already syncing, skipping...');
      return;
    }

    this.isSyncing = true;
    this.progressCallback = onProgress;

    try {
      const store = useDraftCountingStore.getState();
      const drafts = store.drafts;

      // Filter drafts that need syncing
      const newDrafts = drafts.filter(d => d.status === 'draft' && d.id === null);
      const syncedDraftsWithProducts = drafts.filter(
        d => d.status === 'synced' && d.id !== null && d.productIds.length > 0
      );
      const draftsNeedingUpdate = drafts.filter(
        d => d.status === 'draft' && d.id !== null
      );

      if (newDrafts.length === 0 && syncedDraftsWithProducts.length === 0 && draftsNeedingUpdate.length === 0) {
        this.reportProgress({ stage: 'idle', current: 0, total: 0, message: 'Nothing to sync' });
        return;
      }

      // Step 1: Sync new drafts
      if (newDrafts.length > 0) {
        await this.syncNewDrafts(newDrafts);
      }

      // Step 2: Sync draft updates
      if (draftsNeedingUpdate.length > 0) {
        await this.syncDraftUpdates(draftsNeedingUpdate);
      }

      // Step 3: Sync product additions
      if (syncedDraftsWithProducts.length > 0) {
        await this.syncProductAdditions(syncedDraftsWithProducts);
      }

      this.reportProgress({
        stage: 'complete',
        current: 1,
        total: 1,
        message: 'Sync complete',
      });
    } catch (error: any) {
      console.error('[DraftSync] Sync failed:', error);
      this.reportProgress({
        stage: 'idle',
        current: 0,
        total: 0,
        message: `Sync error: ${error.message}`,
      });
    } finally {
      this.isSyncing = false;
    }
  }

  /**
   * Sync new drafts that haven't been created on the server yet
   */
  private async syncNewDrafts(drafts: DraftCounting[]): Promise<void> {
    const store = useDraftCountingStore.getState();

    this.reportProgress({
      stage: 'drafts',
      current: 0,
      total: drafts.length,
      message: `Syncing ${drafts.length} new drafts`,
    });

    // Process in batches of 50
    for (let i = 0; i < drafts.length; i += BATCH_SIZE_DRAFTS) {
      const batch = drafts.slice(i, i + BATCH_SIZE_DRAFTS);

      try {
        const response = await countingApi.batchCreateDrafts(
          batch.map(draft => ({
            localId: draft.localId,
            title: draft.title,
            scopeType: draft.scopeType,
            instructions: draft.instructions,
            executionMode: draft.settings.executionMode,
            requiresCount2: draft.settings.requiresCount2,
            requiresCount3: draft.settings.requiresCount3,
            allowUnexpectedItems: draft.settings.allowUnexpectedItems,
            scopeFilters: { product_ids: draft.productIds },
            count1UserId: draft.assignedCounters.count1UserId,
            count2UserId: draft.assignedCounters.count2UserId,
            count3UserId: draft.assignedCounters.count3UserId,
          }))
        );

        // Update store with server IDs
        response.success.forEach(result => {
          store.markSynced(result.localId, result.serverId);
          this.retryAttempts.delete(result.localId);
        });

        // Handle errors
        response.errors.forEach(error => {
          const attempts = (this.retryAttempts.get(error.localId) || 0) + 1;
          this.retryAttempts.set(error.localId, attempts);

          if (attempts >= MAX_RETRY_ATTEMPTS) {
            store.markSyncError(error.localId, error.error);
            console.error(`[DraftSync] Draft ${error.localId} failed after ${attempts} attempts:`, error.error);
          }
        });

        this.reportProgress({
          stage: 'drafts',
          current: Math.min(i + BATCH_SIZE_DRAFTS, drafts.length),
          total: drafts.length,
          message: `Synced batch ${Math.floor(i / BATCH_SIZE_DRAFTS) + 1}`,
        });
      } catch (error: any) {
        console.error('[DraftSync] Batch create failed:', error);

        // Mark all drafts in batch as errored
        batch.forEach(draft => {
          const attempts = (this.retryAttempts.get(draft.localId) || 0) + 1;
          this.retryAttempts.set(draft.localId, attempts);

          if (attempts >= MAX_RETRY_ATTEMPTS) {
            store.markSyncError(draft.localId, error.message);
          }
        });
      }
    }
  }

  /**
   * Sync product additions to drafts
   */
  private async syncProductAdditions(drafts: DraftCounting[]): Promise<void> {
    const store = useDraftCountingStore.getState();

    for (const draft of drafts) {
      if (!draft.id || draft.productIds.length === 0) continue;

      const totalProducts = draft.productIds.length;

      this.reportProgress({
        stage: 'products',
        current: 0,
        total: totalProducts,
        message: `Syncing products for draft ${draft.title || draft.localId.slice(0, 8)}`,
      });

      // Process in batches of 100
      for (let i = 0; i < draft.productIds.length; i += BATCH_SIZE_PRODUCTS) {
        const batch = draft.productIds.slice(i, i + BATCH_SIZE_PRODUCTS);

        try {
          await countingApi.batchAddProducts(
            draft.id,
            batch.map(productId => ({ productId }))
          );

          this.reportProgress({
            stage: 'products',
            current: Math.min(i + BATCH_SIZE_PRODUCTS, totalProducts),
            total: totalProducts,
            message: `Synced ${Math.min(i + BATCH_SIZE_PRODUCTS, totalProducts)}/${totalProducts} products`,
          });
        } catch (error: any) {
          console.error('[DraftSync] Batch add products failed:', error);
          store.markSyncError(draft.localId, `Failed to sync products: ${error.message}`);
          break; // Stop processing this draft
        }
      }
    }
  }

  /**
   * Sync draft updates (title, instructions, settings changes)
   */
  private async syncDraftUpdates(drafts: DraftCounting[]): Promise<void> {
    const store = useDraftCountingStore.getState();

    this.reportProgress({
      stage: 'updates',
      current: 0,
      total: drafts.length,
      message: `Syncing ${drafts.length} draft updates`,
    });

    // Process in batches of 50
    for (let i = 0; i < drafts.length; i += BATCH_SIZE_DRAFTS) {
      const batch = drafts.slice(i, i + BATCH_SIZE_DRAFTS);

      try {
        const response = await countingApi.batchUpdateDrafts(
          batch.map(draft => ({
            id: draft.id!,
            localId: draft.localId,
            data: {
              title: draft.title,
              instructions: draft.instructions,
              executionMode: draft.settings.executionMode,
              requiresCount2: draft.settings.requiresCount2,
              requiresCount3: draft.settings.requiresCount3,
              allowUnexpectedItems: draft.settings.allowUnexpectedItems,
              count1UserId: draft.assignedCounters.count1UserId,
              count2UserId: draft.assignedCounters.count2UserId,
              count3UserId: draft.assignedCounters.count3UserId,
            },
          }))
        );

        // Mark successful updates as synced
        response.success.forEach(result => {
          if (result.localId) {
            store.markSynced(result.localId, result.id);
          }
        });

        // Handle errors
        response.errors.forEach(error => {
          if (error.localId) {
            store.markSyncError(error.localId, error.error);
          }
        });

        this.reportProgress({
          stage: 'updates',
          current: Math.min(i + BATCH_SIZE_DRAFTS, drafts.length),
          total: drafts.length,
          message: `Updated batch ${Math.floor(i / BATCH_SIZE_DRAFTS) + 1}`,
        });
      } catch (error: any) {
        console.error('[DraftSync] Batch update failed:', error);
      }
    }
  }

  /**
   * Report progress to callback
   */
  private reportProgress(progress: SyncProgress): void {
    if (this.progressCallback) {
      this.progressCallback(progress);
    }
  }
}

/**
 * React hook for using the draft sync service
 *
 * Features:
 * - Auto-sync on mount
 * - Periodic sync every 30 seconds
 * - Sync on app foreground
 * - Sync on network reconnect
 * - Progress tracking
 */
export function useDraftSync() {
  const [isSyncing, setIsSyncing] = useState(false);
  const [progress, setProgress] = useState<SyncProgress>({
    stage: 'idle',
    current: 0,
    total: 0,
  });
  const syncService = useRef(new DraftSyncService()).current;

  const syncAll = useCallback(async () => {
    setIsSyncing(true);
    await syncService.syncAll(p => setProgress(p));
    setIsSyncing(false);
  }, [syncService]);

  useEffect(() => {
    // Initial sync on mount
    syncAll();

    // Periodic sync every 30 seconds
    const interval = setInterval(syncAll, SYNC_INTERVAL_MS);

    // Sync on app foreground
    const subscription = AppState.addEventListener('change', nextAppState => {
      if (nextAppState === 'active') {
        console.log('[DraftSync] App foregrounded, triggering sync');
        syncAll();
      }
    });

    // Sync on network reconnect
    const unsubscribe = NetInfo.addEventListener(state => {
      if (state.isConnected) {
        console.log('[DraftSync] Network reconnected, triggering sync');
        syncAll();
      }
    });

    return () => {
      clearInterval(interval);
      subscription.remove();
      unsubscribe();
    };
  }, [syncAll]);

  return { syncAll, isSyncing, progress };
}
