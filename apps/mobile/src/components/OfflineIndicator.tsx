import { useMemo } from 'react';
import { View, StyleSheet } from 'react-native';
import { Text, ActivityIndicator } from 'react-native-paper';
import { useNetInfo } from '@react-native-community/netinfo';
import { useCountingStore } from '@/features/counting/store/countingStore';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';
import { WifiOff, CloudUpload } from 'lucide-react-native';

export function OfflineIndicator() {
  const netInfo = useNetInfo();

  // Use stable selectors - don't create new arrays on every render
  const allPendingCounts = useCountingStore((s) => s.pendingCounts);
  const allDrafts = useDraftCountingStore((s) => s.drafts);

  // Memoize filtered results to prevent infinite loops
  const pendingCounts = useMemo(
    () => allPendingCounts.filter((c) => !c.synced),
    [allPendingCounts]
  );

  const pendingDrafts = useMemo(
    () => allDrafts.filter(d => d.status === 'draft' || d.status === 'syncing'),
    [allDrafts]
  );

  const hasPendingDrafts = pendingDrafts.length > 0;
  const totalPending = pendingCounts.length + pendingDrafts.length;

  // Online and no pending operations - show nothing
  if (netInfo.isConnected && totalPending === 0) {
    return null;
  }

  // Offline
  if (!netInfo.isConnected) {
    return (
      <View style={[styles.banner, styles.offlineBanner]} testID="offline-banner">
        <WifiOff size={20} color="#b45309" />
        <Text style={styles.offlineText}>
          You're offline. {totalPending > 0 && `${totalPending} operation${totalPending > 1 ? 's' : ''} will sync when connected.`}
          {totalPending === 0 && 'Changes will sync when connected.'}
        </Text>
      </View>
    );
  }

  // Online with pending operations
  if (totalPending > 0) {
    return (
      <View style={[styles.banner, styles.syncingBanner]}>
        <CloudUpload size={20} color="#2563eb" />
        <ActivityIndicator size="small" color="#2563eb" />
        <Text style={styles.syncingText}>
          Syncing {totalPending} operation{totalPending > 1 ? 's' : ''}...
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
