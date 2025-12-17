import { useState, useEffect } from 'react';
import { View, ScrollView, StyleSheet, Alert } from 'react-native';
import {
  Text,
  Card,
  Button,
  Switch,
  Divider,
} from 'react-native-paper';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';
import { useDraftSync } from '@/features/counting/services/draftSyncService';
import { User, Users } from 'lucide-react-native';

export default function AssignCountersScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const localId = id; // Now using localId from URL

  const draft = useDraftCountingStore((state) =>
    state.drafts.find(d => d.localId === localId)
  );
  const updateDraft = useDraftCountingStore((state) => state.updateDraft);
  const { syncAll } = useDraftSync();

  const [requiresCount2, setRequiresCount2] = useState(false);
  const [requiresCount3, setRequiresCount3] = useState(false);
  const [executionMode, setExecutionMode] = useState<'sequential' | 'parallel'>('sequential');
  const [isSaving, setIsSaving] = useState(false);

  // Initialize state from draft
  useEffect(() => {
    if (draft) {
      setRequiresCount2(draft.settings.requiresCount2);
      setRequiresCount3(draft.settings.requiresCount3);
      setExecutionMode(draft.settings.executionMode);
    }
  }, [draft]);

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

  const handleSave = async () => {
    try {
      setIsSaving(true);

      // Update locally (works offline!)
      updateDraft(localId, {
        settings: {
          ...draft.settings,
          requiresCount2,
          requiresCount3,
          executionMode,
        },
      });

      // Trigger background sync (non-blocking)
      syncAll();

      Alert.alert('Success', 'Counter settings saved', [
        { text: 'OK', onPress: () => router.back() },
      ]);
    } catch (error: any) {
      Alert.alert('Error', error.message || 'Failed to save settings');
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <ScrollView style={styles.container}>
      <View style={styles.content}>
        <Text variant="headlineSmall" style={styles.header}>
          Assign Counters
        </Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          Configure who will perform the counting and how it will be executed
        </Text>

        {/* Primary Counter (Always Required) */}
        <Card style={styles.card}>
          <Card.Content>
            <View style={styles.counterHeader}>
              <View style={styles.counterIcon}>
                <User size={20} color="#6366f1" />
              </View>
              <View style={{ flex: 1 }}>
                <Text variant="titleMedium">Primary Counter (Count 1)</Text>
                <Text variant="bodySmall" style={{ color: '#6b7280', marginTop: 4 }}>
                  Required - Will be automatically assigned when activated
                </Text>
              </View>
            </View>

            <View style={styles.info}>
              <Text variant="bodySmall" style={styles.infoText}>
                The person who created this draft will be assigned as the primary
                counter when the counting operation is activated.
              </Text>
            </View>
          </Card.Content>
        </Card>

        {/* Count Settings */}
        <Card style={styles.card}>
          <Card.Content>
            <Text variant="titleMedium" style={{ marginBottom: 16 }}>
              Additional Counts
            </Text>

            <View style={styles.settingRow}>
              <View style={{ flex: 1 }}>
                <Text variant="bodyMedium">Require Second Count</Text>
                <Text variant="bodySmall" style={{ color: '#6b7280', marginTop: 2 }}>
                  A second person will verify the count
                </Text>
              </View>
              <Switch value={requiresCount2} onValueChange={setRequiresCount2} />
            </View>

            <Divider style={styles.divider} />

            <View style={styles.settingRow}>
              <View style={{ flex: 1 }}>
                <Text variant="bodyMedium">Require Third Count</Text>
                <Text variant="bodySmall" style={{ color: '#6b7280', marginTop: 2 }}>
                  A third person will count if there are discrepancies
                </Text>
              </View>
              <Switch
                value={requiresCount3}
                onValueChange={setRequiresCount3}
                disabled={!requiresCount2}
              />
            </View>

            {requiresCount3 && !requiresCount2 && (
              <Text
                variant="bodySmall"
                style={{ color: '#ef4444', marginTop: 8 }}
              >
                Third count requires second count to be enabled
              </Text>
            )}
          </Card.Content>
        </Card>

        {/* Execution Mode */}
        <Card style={styles.card}>
          <Card.Content>
            <Text variant="titleMedium" style={{ marginBottom: 12 }}>
              Execution Mode
            </Text>

            <Card
              mode={executionMode === 'sequential' ? 'elevated' : 'outlined'}
              style={[
                styles.modeCard,
                executionMode === 'sequential' && styles.modeCardSelected,
              ]}
              onPress={() => setExecutionMode('sequential')}
            >
              <Card.Content style={styles.modeCardContent}>
                <View style={{ flex: 1 }}>
                  <Text variant="titleSmall">Sequential</Text>
                  <Text variant="bodySmall" style={{ color: '#6b7280', marginTop: 2 }}>
                    Counts happen one after another (recommended)
                  </Text>
                </View>
              </Card.Content>
            </Card>

            <Card
              mode={executionMode === 'parallel' ? 'elevated' : 'outlined'}
              style={[
                styles.modeCard,
                executionMode === 'parallel' && styles.modeCardSelected,
              ]}
              onPress={() => setExecutionMode('parallel')}
            >
              <Card.Content style={styles.modeCardContent}>
                <View style={{ flex: 1 }}>
                  <Text variant="titleSmall">Parallel</Text>
                  <Text variant="bodySmall" style={{ color: '#6b7280', marginTop: 2 }}>
                    Multiple counters can work simultaneously
                  </Text>
                </View>
              </Card.Content>
            </Card>
          </Card.Content>
        </Card>

        {/* Counter Assignment Info */}
        <Card style={styles.card}>
          <Card.Content>
            <View style={{ flexDirection: 'row', gap: 8, marginBottom: 8 }}>
              <Users size={20} color="#6b7280" />
              <Text variant="titleMedium">Counter Assignment</Text>
            </View>
            <Text variant="bodySmall" style={{ color: '#6b7280' }}>
              Additional counters can be assigned by administrators from the web
              interface after the counting operation is activated.
            </Text>
          </Card.Content>
        </Card>

        {/* Actions */}
        <View style={styles.actions}>
          <Button
            mode="outlined"
            onPress={() => router.back()}
            style={styles.button}
          >
            Cancel
          </Button>
          <Button
            mode="contained"
            onPress={handleSave}
            disabled={isSaving}
            loading={isSaving}
            style={[styles.button, styles.saveButton]}
          >
            Save Settings
          </Button>
        </View>
      </View>
    </ScrollView>
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
  },
  header: {
    marginBottom: 8,
  },
  subtitle: {
    color: '#6b7280',
    marginBottom: 24,
  },
  card: {
    marginBottom: 12,
  },
  counterHeader: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
  },
  counterIcon: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#eef2ff',
    justifyContent: 'center',
    alignItems: 'center',
  },
  info: {
    marginTop: 12,
    padding: 12,
    backgroundColor: '#f0f9ff',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#bae6fd',
  },
  infoText: {
    color: '#0369a1',
  },
  settingRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
  },
  divider: {
    marginVertical: 16,
  },
  modeCard: {
    marginBottom: 8,
  },
  modeCardSelected: {
    borderColor: '#6366f1',
    borderWidth: 2,
    backgroundColor: '#eef2ff',
  },
  modeCardContent: {
    paddingVertical: 8,
  },
  actions: {
    flexDirection: 'row',
    gap: 12,
    marginTop: 16,
    marginBottom: 32,
  },
  button: {
    flex: 1,
  },
  saveButton: {
    backgroundColor: '#6366f1',
  },
});
