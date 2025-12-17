import { useState } from 'react';
import { View, ScrollView, StyleSheet, Alert } from 'react-native';
import {
  Text,
  TextInput,
  Button,
  Card,
  ActivityIndicator,
  Chip,
  IconButton,
  FAB,
  Menu,
  Divider,
} from 'react-native-paper';
import { useRouter, useLocalSearchParams } from 'expo-router';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';
import { useDraftSync } from '@/features/counting/services/draftSyncService';
import { countingApi } from '@/features/counting/api/countingApi';
import {
  Package,
  UserPlus,
  ScanBarcode,
  Trash2,
  MoreVertical,
  Play,
} from 'lucide-react-native';

export default function DraftEditScreen() {
  const router = useRouter();
  const { id } = useLocalSearchParams<{ id: string }>();
  const localId = id; // Now using localId from URL

  const draft = useDraftCountingStore((state) =>
    state.drafts.find(d => d.localId === localId)
  );
  const updateDraft = useDraftCountingStore((state) => state.updateDraft);
  const deleteDraft = useDraftCountingStore((state) => state.deleteDraft);
  const { syncAll } = useDraftSync();

  const [menuVisible, setMenuVisible] = useState(false);
  const [isEditingTitle, setIsEditingTitle] = useState(false);
  const [isEditingInstructions, setIsEditingInstructions] = useState(false);
  const [editedTitle, setEditedTitle] = useState('');
  const [editedInstructions, setEditedInstructions] = useState('');
  const [isActivating, setIsActivating] = useState(false);

  if (!draft) {
    return (
      <View style={styles.centered}>
        <Text variant="bodyLarge">Draft not found</Text>
        <Button mode="outlined" onPress={() => router.back()} style={{ marginTop: 16 }}>
          Go Back
        </Button>
      </View>
    );
  }

  const statusColors: Record<string, string> = {
    draft: '#f59e0b',
    syncing: '#3b82f6',
    synced: '#10b981',
    sync_error: '#ef4444',
  };

  const handleSaveTitle = async () => {
    try {
      // Update locally (works offline!)
      updateDraft(localId, { title: editedTitle.trim() });
      setIsEditingTitle(false);

      // Trigger background sync (non-blocking)
      syncAll();
    } catch (error: any) {
      Alert.alert('Error', error.message || 'Failed to update title');
    }
  };

  const handleSaveInstructions = async () => {
    try {
      // Update locally (works offline!)
      updateDraft(localId, { instructions: editedInstructions.trim() });
      setIsEditingInstructions(false);

      // Trigger background sync (non-blocking)
      syncAll();
    } catch (error: any) {
      Alert.alert('Error', error.message || 'Failed to update instructions');
    }
  };

  const handleActivate = () => {
    if (draft.productIds.length === 0) {
      Alert.alert('Cannot Activate', 'Please add at least one product before activating');
      return;
    }

    // Activation requires network connection (fiscal operation)
    if (!draft.id) {
      Alert.alert(
        'Sync Required',
        'This draft needs to be synced to the server before activation. Please ensure you have network connection and try again.',
        [{ text: 'OK' }]
      );
      return;
    }

    Alert.alert(
      'Activate Counting Operation',
      'This will transition the draft to an active counting operation. Requires network connection. Are you sure?',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Activate',
          style: 'default',
          onPress: async () => {
            setIsActivating(true);
            try {
              await countingApi.activateDraft(Number(draft.id), true);
              Alert.alert('Success', 'Counting operation activated', [
                {
                  text: 'OK',
                  onPress: () => router.replace('/tasks'),
                },
              ]);
            } catch (error: any) {
              Alert.alert('Error', error.message || 'Failed to activate. Check network connection.');
            } finally {
              setIsActivating(false);
            }
          },
        },
      ]
    );
  };

  return (
    <View style={styles.container}>
      <ScrollView contentContainerStyle={styles.content}>
        {/* Header with Status */}
        <Card style={styles.card}>
          <Card.Content>
            <View style={styles.statusRow}>
              <Text variant="titleLarge" style={{ flex: 1 }}>
                {draft.title || 'Untitled Draft'}
              </Text>
              <Chip
                mode="flat"
                textStyle={{
                  fontSize: 10,
                  color: statusColors[draft.status],
                }}
                style={{
                  backgroundColor: `${statusColors[draft.status]}20`,
                  height: 24,
                }}
              >
                {draft.status.replace('_', ' ').toUpperCase()}
              </Chip>
            </View>
            <Text variant="bodySmall" style={styles.uuid}>
              #{draft.localId.slice(0, 8)} • {draft.scopeType.replace('_', ' ')}
            </Text>
          </Card.Content>
        </Card>

        {/* Title Edit */}
        <Card style={styles.card}>
          <Card.Content>
            <View style={styles.editRow}>
              <Text variant="titleMedium" style={{ flex: 1 }}>
                Title
              </Text>
              {!isEditingTitle && (
                <IconButton
                  icon="pencil"
                  size={20}
                  onPress={() => {
                    setEditedTitle(draft.title || '');
                    setIsEditingTitle(true);
                  }}
                />
              )}
            </View>
            {isEditingTitle ? (
              <>
                <TextInput
                  value={editedTitle}
                  onChangeText={setEditedTitle}
                  mode="outlined"
                  style={styles.input}
                  autoFocus
                />
                <View style={styles.editActions}>
                  <Button
                    mode="outlined"
                    onPress={() => setIsEditingTitle(false)}
                    compact
                  >
                    Cancel
                  </Button>
                  <Button mode="contained" onPress={handleSaveTitle} compact>
                    Save
                  </Button>
                </View>
              </>
            ) : (
              <Text variant="bodyMedium">
                {draft.title || <Text style={{ color: '#9ca3af' }}>No title set</Text>}
              </Text>
            )}
          </Card.Content>
        </Card>

        {/* Instructions Edit */}
        <Card style={styles.card}>
          <Card.Content>
            <View style={styles.editRow}>
              <Text variant="titleMedium" style={{ flex: 1 }}>
                Instructions
              </Text>
              {!isEditingInstructions && (
                <IconButton
                  icon="pencil"
                  size={20}
                  onPress={() => {
                    setEditedInstructions(draft.instructions || '');
                    setIsEditingInstructions(true);
                  }}
                />
              )}
            </View>
            {isEditingInstructions ? (
              <>
                <TextInput
                  value={editedInstructions}
                  onChangeText={setEditedInstructions}
                  mode="outlined"
                  multiline
                  numberOfLines={3}
                  style={styles.input}
                  autoFocus
                />
                <View style={styles.editActions}>
                  <Button
                    mode="outlined"
                    onPress={() => setIsEditingInstructions(false)}
                    compact
                  >
                    Cancel
                  </Button>
                  <Button
                    mode="contained"
                    onPress={handleSaveInstructions}
                    compact
                  >
                    Save
                  </Button>
                </View>
              </>
            ) : (
              <Text variant="bodyMedium">
                {draft.instructions || <Text style={{ color: '#9ca3af' }}>No instructions</Text>}
              </Text>
            )}
          </Card.Content>
        </Card>

        {/* Products Count */}
        <Card style={styles.card}>
          <Card.Content>
            <View style={styles.productHeader}>
              <View style={{ flex: 1 }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                  <Package size={20} color="#6b7280" />
                  <Text variant="titleMedium">Products</Text>
                </View>
                <Text variant="bodySmall" style={{ color: '#6b7280', marginTop: 4 }}>
                  {draft.productIds.length} product{draft.productIds.length !== 1 ? 's' : ''} added
                </Text>
              </View>
              <Button
                mode="outlined"
                icon={({ size, color }) => <ScanBarcode size={size} color={color} />}
                onPress={() =>
                  router.push(`/counting/draft/${draft.localId}/scan` as const)
                }
              >
                Scan to Add
              </Button>
            </View>

            {draft.productIds.length === 0 && (
              <View style={styles.emptyProducts}>
                <Text variant="bodyMedium" style={{ color: '#9ca3af' }}>
                  No products added yet
                </Text>
                <Text variant="bodySmall" style={{ color: '#9ca3af', marginTop: 4 }}>
                  Use the scanner to add products to this counting operation
                </Text>
              </View>
            )}
          </Card.Content>
        </Card>

        {/* Actions */}
        <Card style={styles.card}>
          <Card.Content>
            <Text variant="titleMedium" style={{ marginBottom: 12 }}>
              Actions
            </Text>

            <Button
              mode="outlined"
              icon={({ size, color }) => <UserPlus size={size} color={color} />}
              onPress={() =>
                router.push(`/counting/draft/${draft.localId}/assign` as const)
              }
              style={styles.actionButton}
            >
              Assign Counters
            </Button>

            <Button
              mode="contained"
              icon={({ size, color }) => <Play size={size} color={color} />}
              onPress={handleActivate}
              loading={isActivating}
              style={[styles.actionButton, { backgroundColor: '#10b981' }]}
              disabled={draft.productIds.length === 0 || isActivating}
            >
              Activate Counting
            </Button>
          </Card.Content>
        </Card>
      </ScrollView>

      {/* Floating Action Menu */}
      <FAB.Group
        open={menuVisible}
        visible
        icon={menuVisible ? 'close' : 'dots-vertical'}
        actions={[
          {
            icon: () => <Trash2 size={20} color="#ef4444" />,
            label: 'Delete Draft',
            onPress: () => {
              Alert.alert(
                'Delete Draft',
                'Are you sure? This cannot be undone.',
                [
                  { text: 'Cancel', style: 'cancel' },
                  {
                    text: 'Delete',
                    style: 'destructive',
                    onPress: () => {
                      deleteDraft(localId);
                      router.back();
                    },
                  },
                ]
              );
            },
            color: '#ef4444',
          },
        ]}
        onStateChange={({ open }) => setMenuVisible(open)}
        style={styles.fab}
      />
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
    paddingBottom: 80, // Space for FAB
  },
  centered: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#f3f4f6',
  },
  card: {
    marginBottom: 12,
  },
  statusRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  uuid: {
    color: '#6b7280',
    marginTop: 4,
  },
  editRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: 8,
  },
  input: {
    marginTop: 8,
  },
  editActions: {
    flexDirection: 'row',
    gap: 8,
    marginTop: 12,
    justifyContent: 'flex-end',
  },
  productHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  emptyProducts: {
    paddingVertical: 24,
    alignItems: 'center',
  },
  actionButton: {
    marginBottom: 8,
  },
  fab: {
    position: 'absolute',
    bottom: 16,
    right: 16,
  },
});
