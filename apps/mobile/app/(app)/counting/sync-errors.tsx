import { useState } from 'react';
import { View, ScrollView, StyleSheet, Alert } from 'react-native';
import {
  Text,
  Card,
  Button,
  IconButton,
  Chip,
  ActivityIndicator,
} from 'react-native-paper';
import { useRouter } from 'expo-router';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';
import { useDraftSync } from '@/features/counting/services/draftSyncService';
import {
  AlertCircle,
  RefreshCw,
  Trash2,
  ChevronRight,
  CheckCircle,
} from 'lucide-react-native';

export default function SyncErrorsScreen() {
  const router = useRouter();
  const drafts = useDraftCountingStore((state) => state.drafts);
  const deleteDraft = useDraftCountingStore((state) => state.deleteDraft);
  const retrySync = useDraftCountingStore((state) => state.retrySync);
  const { syncAll, isSyncing } = useDraftSync();
  const [retryingIds, setRetryingIds] = useState<Set<string>>(new Set());

  // Filter drafts with sync errors
  const erroredDrafts = drafts.filter((d) => d.status === 'sync_error');

  const handleRetryOne = async (localId: string) => {
    setRetryingIds((prev) => new Set(prev).add(localId));

    try {
      // Reset status to draft to allow retry
      retrySync(localId);

      // Trigger sync
      await syncAll();

      Alert.alert('Success', 'Sync retry initiated');
    } catch (error: any) {
      Alert.alert('Error', error.message || 'Failed to retry sync');
    } finally {
      setRetryingIds((prev) => {
        const next = new Set(prev);
        next.delete(localId);
        return next;
      });
    }
  };

  const handleRetryAll = async () => {
    Alert.alert(
      'Retry All Errors',
      `Retry syncing ${erroredDrafts.length} failed draft${erroredDrafts.length > 1 ? 's' : ''}?`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Retry All',
          style: 'default',
          onPress: async () => {
            try {
              // Reset all errored drafts
              erroredDrafts.forEach((draft) => {
                retrySync(draft.localId);
              });

              // Trigger sync
              await syncAll();

              Alert.alert('Success', 'Sync retry initiated for all errors');
            } catch (error: any) {
              Alert.alert('Error', error.message || 'Failed to retry sync');
            }
          },
        },
      ]
    );
  };

  const handleDeleteOne = (localId: string, title: string) => {
    Alert.alert(
      'Delete Failed Draft',
      `Delete "${title || 'Untitled'}"? This cannot be undone.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Delete',
          style: 'destructive',
          onPress: () => {
            deleteDraft(localId);
          },
        },
      ]
    );
  };

  const handleClearAll = () => {
    Alert.alert(
      'Clear All Errors',
      `Delete ${erroredDrafts.length} failed draft${erroredDrafts.length > 1 ? 's' : ''}? This cannot be undone.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Delete All',
          style: 'destructive',
          onPress: () => {
            erroredDrafts.forEach((draft) => {
              deleteDraft(draft.localId);
            });
          },
        },
      ]
    );
  };

  // Empty state
  if (erroredDrafts.length === 0) {
    return (
      <View style={styles.centered}>
        <CheckCircle size={64} color="#10b981" />
        <Text variant="titleLarge" style={{ marginTop: 16 }}>
          No Sync Errors
        </Text>
        <Text
          variant="bodyMedium"
          style={{ color: '#6b7280', marginTop: 8, textAlign: 'center' }}
        >
          All draft operations have synced successfully
        </Text>
        <Button
          mode="outlined"
          onPress={() => router.back()}
          style={{ marginTop: 24 }}
        >
          Go Back
        </Button>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <ScrollView contentContainerStyle={styles.content}>
        {/* Header */}
        <Card style={styles.headerCard}>
          <Card.Content>
            <View style={styles.headerContent}>
              <View style={{ flex: 1 }}>
                <Text variant="titleLarge">Sync Errors</Text>
                <Text variant="bodyMedium" style={{ color: '#6b7280', marginTop: 4 }}>
                  {erroredDrafts.length} draft{erroredDrafts.length > 1 ? 's' : ''} failed to sync
                </Text>
              </View>
              <AlertCircle size={32} color="#ef4444" />
            </View>
          </Card.Content>
        </Card>

        {/* Actions */}
        <View style={styles.actions}>
          <Button
            mode="outlined"
            icon={({ size, color }) => <RefreshCw size={size} color={color} />}
            onPress={handleRetryAll}
            disabled={isSyncing}
            style={styles.actionButton}
          >
            Retry All
          </Button>
          <Button
            mode="outlined"
            icon={({ size, color }) => <Trash2 size={size} color={color} />}
            onPress={handleClearAll}
            textColor="#ef4444"
            style={styles.actionButton}
          >
            Clear All
          </Button>
        </View>

        {/* Error List */}
        {erroredDrafts.map((draft) => (
          <Card key={draft.localId} style={styles.errorCard}>
            <Card.Content>
              <View style={styles.errorHeader}>
                <View style={{ flex: 1 }}>
                  <Text variant="titleMedium">
                    {draft.title || 'Untitled Draft'}
                  </Text>
                  <Text variant="bodySmall" style={{ color: '#6b7280', marginTop: 2 }}>
                    #{draft.localId.slice(0, 8)} • {draft.scopeType.replace('_', ' ')}
                  </Text>
                </View>
                <Chip
                  mode="flat"
                  textStyle={{ fontSize: 10, color: '#dc2626' }}
                  style={{
                    backgroundColor: '#fee2e2',
                    height: 24,
                  }}
                >
                  ERROR
                </Chip>
              </View>

              {/* Error Message */}
              {draft.syncError && (
                <View style={styles.errorMessage}>
                  <AlertCircle size={16} color="#ef4444" />
                  <Text
                    variant="bodySmall"
                    style={{ color: '#991b1b', flex: 1, marginLeft: 8 }}
                  >
                    {draft.syncError}
                  </Text>
                </View>
              )}

              {/* Draft Info */}
              <View style={styles.draftInfo}>
                <Text variant="bodySmall" style={{ color: '#6b7280' }}>
                  {draft.productIds.length} product{draft.productIds.length !== 1 ? 's' : ''}
                </Text>
                {draft.lastModifiedAt && (
                  <Text variant="bodySmall" style={{ color: '#6b7280' }}>
                    • Last updated {new Date(draft.lastModifiedAt).toLocaleString()}
                  </Text>
                )}
              </View>

              {/* Actions */}
              <View style={styles.cardActions}>
                <Button
                  mode="outlined"
                  icon={({ size, color }) => <RefreshCw size={size} color={color} />}
                  onPress={() => handleRetryOne(draft.localId)}
                  disabled={retryingIds.has(draft.localId) || isSyncing}
                  loading={retryingIds.has(draft.localId)}
                  compact
                  style={{ flex: 1, marginRight: 8 }}
                >
                  Retry
                </Button>
                <Button
                  mode="outlined"
                  icon={({ size, color }) => <Trash2 size={size} color={color} />}
                  onPress={() => handleDeleteOne(draft.localId, draft.title)}
                  textColor="#ef4444"
                  compact
                  style={{ flex: 1 }}
                >
                  Delete
                </Button>
              </View>
            </Card.Content>
          </Card>
        ))}

        {/* Help Card */}
        <Card style={styles.helpCard}>
          <Card.Content>
            <Text variant="titleSmall" style={{ marginBottom: 8 }}>
              Why do sync errors happen?
            </Text>
            <Text variant="bodySmall" style={{ color: '#6b7280', lineHeight: 20 }}>
              • Network connection lost during sync{'\n'}
              • Server temporarily unavailable{'\n'}
              • Invalid data that couldn't be processed{'\n'}
              • Permissions changed on the server
            </Text>
            <Text
              variant="bodySmall"
              style={{ color: '#6b7280', marginTop: 12, lineHeight: 20 }}
            >
              Retry will attempt to sync again. If errors persist, you may need to delete the
              draft and recreate it.
            </Text>
          </Card.Content>
        </Card>
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f3f4f6',
  },
  content: {
    padding: 16,
  },
  centered: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#f3f4f6',
    padding: 16,
  },
  headerCard: {
    marginBottom: 16,
  },
  headerContent: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 16,
  },
  actions: {
    flexDirection: 'row',
    gap: 12,
    marginBottom: 16,
  },
  actionButton: {
    flex: 1,
  },
  errorCard: {
    marginBottom: 12,
    borderLeftWidth: 4,
    borderLeftColor: '#ef4444',
  },
  errorHeader: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
    marginBottom: 12,
  },
  errorMessage: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    backgroundColor: '#fee2e2',
    padding: 12,
    borderRadius: 8,
    marginBottom: 12,
  },
  draftInfo: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginBottom: 12,
  },
  cardActions: {
    flexDirection: 'row',
    gap: 8,
  },
  helpCard: {
    marginTop: 8,
    backgroundColor: '#f0f9ff',
    borderWidth: 1,
    borderColor: '#bae6fd',
  },
});
