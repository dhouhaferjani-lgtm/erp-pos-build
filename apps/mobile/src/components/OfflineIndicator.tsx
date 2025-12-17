import { View, StyleSheet } from 'react-native';
import { Text, ActivityIndicator } from 'react-native-paper';
import { useNetInfo } from '@react-native-community/netinfo';
import { useCountingStore } from '@/features/counting/store/countingStore';
import { useDraftSync } from '@/features/counting/services/draftSyncService';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';
import { WifiOff, CloudUpload } from 'lucide-react-native';

export function OfflineIndicator() {
  const netInfo = useNetInfo();
  const pendingCounts = useCountingStore((s) =>
    s.pendingCounts.filter((c) => !c.synced)
  );
  const { isSyncing: isDraftSyncing, progress: draftProgress } = useDraftSync();
  const drafts = useDraftCountingStore((s) => s.drafts);

  // Count pending draft operations
  const pendingDrafts = drafts.filter(d => d.status === 'draft' || d.status === 'syncing');
  const hasPendingDrafts = pendingDrafts.length > 0;

  // Online and no pending operations - show nothing
  if (netInfo.isConnected && pendingCounts.length === 0 && !hasPendingDrafts && !isDraftSyncing) {
    return null;
  }

  // Offline
  if (!netInfo.isConnected) {
    const totalPending = pendingCounts.length + pendingDrafts.length;
    return (
      <View style={[styles.banner, styles.offlineBanner]}>
        <WifiOff size={20} color="#b45309" />
        <Text style={styles.offlineText}>
          You're offline. {totalPending > 0 && `${totalPending} operation${totalPending > 1 ? 's' : ''} will sync when connected.`}
          {totalPending === 0 && 'Changes will sync when connected.'}
        </Text>
      </View>
    );
  }

  // Online with active draft sync
  if (isDraftSyncing && draftProgress.stage !== 'idle') {
    let message = draftProgress.message || 'Syncing drafts...';

    // Show detailed progress for different stages
    if (draftProgress.stage === 'drafts') {
      message = `Syncing ${draftProgress.current}/${draftProgress.total} drafts...`;
    } else if (draftProgress.stage === 'products') {
      message = `Syncing ${draftProgress.current}/${draftProgress.total} products...`;
    } else if (draftProgress.stage === 'updates') {
      message = `Syncing ${draftProgress.current}/${draftProgress.total} updates...`;
    } else if (draftProgress.stage === 'complete') {
      message = 'Draft sync complete';
    }

    return (
      <View style={[styles.banner, styles.syncingBanner]}>
        <CloudUpload size={20} color="#2563eb" />
        <ActivityIndicator size="small" color="#2563eb" />
        <Text style={styles.syncingText}>{message}</Text>
      </View>
    );
  }

  // Online with pending counts
  if (pendingCounts.length > 0) {
    return (
      <View style={[styles.banner, styles.syncingBanner]}>
        <CloudUpload size={20} color="#2563eb" />
        <ActivityIndicator size="small" color="#2563eb" />
        <Text style={styles.syncingText}>
          Syncing {pendingCounts.length} pending count
          {pendingCounts.length > 1 ? 's' : ''}...
        </Text>
      </View>
    );
  }

  // Online with pending drafts (not actively syncing)
  if (hasPendingDrafts) {
    return (
      <View style={[styles.banner, styles.syncingBanner]}>
        <CloudUpload size={20} color="#2563eb" />
        <Text style={styles.syncingText}>
          {pendingDrafts.length} draft{pendingDrafts.length > 1 ? 's' : ''} pending sync
        </Text>
      </View>
    );
  }

  return null;
}

const styles = StyleSheet.create({
  banner: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    paddingVertical: 12,
    gap: 8,
  },
  offlineBanner: { backgroundColor: '#fef3c7' },
  offlineText: { color: '#b45309', flex: 1 },
  syncingBanner: { backgroundColor: '#dbeafe' },
  syncingText: { color: '#2563eb', flex: 1 },
});
