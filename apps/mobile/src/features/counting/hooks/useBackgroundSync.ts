import { useEffect, useRef } from 'react';
import { AppState, AppStateStatus } from 'react-native';
import NetInfo from '@react-native-community/netinfo';
import { useCountingStore } from '../store/countingStore';
import { countingApi } from '../api/countingApi';
import { useQueryClient } from '@tanstack/react-query';
import { countingKeys } from '../api/queries';

/**
 * Background sync service for pending counts
 *
 * Automatically syncs pending counts when:
 * - App comes to foreground
 * - Network connection is restored
 * - Every 30 seconds while app is active
 */
export function useBackgroundSync() {
  const queryClient = useQueryClient();
  const pendingCounts = useCountingStore((state) => state.pendingCounts);
  const markSynced = useCountingStore((state) => state.markSynced);
  const markSyncError = useCountingStore((state) => state.markSyncError);
  const syncIntervalRef = useRef<ReturnType<typeof setInterval> | undefined>(undefined);
  const isSyncingRef = useRef<boolean>(false);

  const syncPendingCounts = async () => {
    // Prevent concurrent syncs
    if (isSyncingRef.current) {
      return;
    }

    // Check if there are pending counts
    if (pendingCounts.length === 0) {
      return;
    }

    // Check network connectivity
    const netState = await NetInfo.fetch();
    if (!netState.isConnected) {
      return;
    }

    isSyncingRef.current = true;

    try {
      // Process pending counts one by one
      for (const pending of pendingCounts) {
        try {
          await countingApi.submitCount(
            pending.countingId,
            pending.itemId,
            pending.quantity,
            pending.notes
          );

          // Mark as synced (removes from queue)
          markSynced(pending.id);

          // Invalidate queries to refresh UI
          queryClient.invalidateQueries({
            queryKey: countingKeys.session(pending.countingId),
          });
          queryClient.invalidateQueries({
            queryKey: countingKeys.tasks(),
          });
        } catch (error) {
          // Mark error but continue with other pending counts
          const errorMessage = error instanceof Error ? error.message : 'Sync failed';
          markSyncError(pending.id, errorMessage);
        }
      }
    } finally {
      isSyncingRef.current = false;
    }
  };

  useEffect(() => {
    // Sync on mount
    syncPendingCounts();

    // Set up periodic sync (every 30 seconds)
    syncIntervalRef.current = setInterval(syncPendingCounts, 30000);

    // Listen to app state changes
    const appStateSubscription = AppState.addEventListener(
      'change',
      (nextAppState: AppStateStatus) => {
        if (nextAppState === 'active') {
          // Sync when app comes to foreground
          syncPendingCounts();
        }
      }
    );

    // Listen to network state changes
    const networkUnsubscribe = NetInfo.addEventListener((state) => {
      if (state.isConnected) {
        // Sync when network is restored
        syncPendingCounts();
      }
    });

    // Cleanup
    return () => {
      if (syncIntervalRef.current) {
        clearInterval(syncIntervalRef.current);
      }
      appStateSubscription.remove();
      networkUnsubscribe();
    };
  }, [pendingCounts.length]); // Re-run when pending count changes

  return {
    pendingCount: pendingCounts.length,
    hasPending: pendingCounts.length > 0,
    syncNow: syncPendingCounts,
  };
}
