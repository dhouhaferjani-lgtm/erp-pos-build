import { useState } from 'react';
import { View, ScrollView, StyleSheet } from 'react-native';
import {
  Text,
  TextInput,
  Button,
  Card,
  Snackbar,
} from 'react-native-paper';
import { useRouter } from 'expo-router';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';
import { useDraftSync } from '@/features/counting/services/draftSyncService';
import { ChevronRight } from 'lucide-react-native';

const SCOPE_TYPES = [
  { value: 'product', label: 'Product', description: 'Count specific products' },
  {
    value: 'product_location',
    label: 'Product + Location',
    description: 'Count products in specific locations',
  },
  { value: 'location', label: 'Location', description: 'Count a warehouse location' },
  { value: 'category', label: 'Category', description: 'Count by product category' },
  {
    value: 'warehouse',
    label: 'Warehouse',
    description: 'Count entire warehouse',
  },
  {
    value: 'full_inventory',
    label: 'Full Inventory',
    description: 'Count all inventory',
  },
];

export default function CreateDraftScreen() {
  const router = useRouter();
  const createDraft = useDraftCountingStore((state) => state.createDraft);
  const { syncAll } = useDraftSync();

  const [title, setTitle] = useState('');
  const [selectedScope, setSelectedScope] = useState<string | null>(null);
  const [instructions, setInstructions] = useState('');
  const [snackbarVisible, setSnackbarVisible] = useState(false);
  const [snackbarMessage, setSnackbarMessage] = useState('');
  const [isCreating, setIsCreating] = useState(false);

  const handleCreate = async () => {
    if (!selectedScope) {
      setSnackbarMessage('Please select a count scope');
      setSnackbarVisible(true);
      return;
    }

    try {
      setIsCreating(true);

      // Create draft locally (works offline!)
      const localId = createDraft({
        title: title.trim() || '',
        scopeType: selectedScope as any,
        instructions: instructions.trim() || '',
        settings: {
          requiresCount2: false,
          requiresCount3: false,
          allowUnexpectedItems: true,
          executionMode: 'sequential',
        },
        assignedCounters: {},
      });

      // Trigger background sync (non-blocking)
      syncAll();

      // Navigate to draft edit screen using localId
      router.replace(`/counting/draft/${localId}` as const);
    } catch (error: any) {
      setSnackbarMessage(error.message || 'Failed to create draft');
      setSnackbarVisible(true);
    } finally {
      setIsCreating(false);
    }
  };

  return (
    <ScrollView style={styles.container}>
      <View style={styles.content}>
        <Text variant="headlineSmall" style={styles.header}>
          Create Counting Operation
        </Text>
        <Text variant="bodyMedium" style={styles.subtitle}>
          Start a new counting operation. You can add products incrementally over
          time.
        </Text>

        <Card style={styles.card}>
          <Card.Content>
            <Text variant="titleMedium" style={styles.sectionTitle}>
              Basic Information
            </Text>

            <TextInput
              label="Title (optional)"
              value={title}
              onChangeText={setTitle}
              mode="outlined"
              style={styles.input}
              placeholder="e.g., Suspicious Stock Check"
            />

            <TextInput
              label="Instructions (optional)"
              value={instructions}
              onChangeText={setInstructions}
              mode="outlined"
              multiline
              numberOfLines={3}
              style={styles.input}
              placeholder="Special instructions for counters..."
            />
          </Card.Content>
        </Card>

        <Card style={styles.card}>
          <Card.Content>
            <Text variant="titleMedium" style={styles.sectionTitle}>
              Select Count Scope
            </Text>
            <Text variant="bodySmall" style={styles.sectionSubtitle}>
              Choose what type of counting operation this will be
            </Text>

            {SCOPE_TYPES.map((scope) => (
              <Card
                key={scope.value}
                mode={selectedScope === scope.value ? 'elevated' : 'outlined'}
                style={[
                  styles.scopeCard,
                  selectedScope === scope.value && styles.scopeCardSelected,
                ]}
                onPress={() => setSelectedScope(scope.value)}
              >
                <Card.Content style={styles.scopeCardContent}>
                  <View style={styles.scopeInfo}>
                    <Text variant="titleSmall">{scope.label}</Text>
                    <Text variant="bodySmall" style={styles.scopeDescription}>
                      {scope.description}
                    </Text>
                  </View>
                  {selectedScope === scope.value && (
                    <ChevronRight size={20} color="#6366f1" />
                  )}
                </Card.Content>
              </Card>
            ))}
          </Card.Content>
        </Card>

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
            onPress={handleCreate}
            disabled={!selectedScope || isCreating}
            loading={isCreating}
            style={[styles.button, styles.createButton]}
          >
            Create Draft
          </Button>
        </View>
      </View>

      <Snackbar
        visible={snackbarVisible}
        onDismiss={() => setSnackbarVisible(false)}
        duration={3000}
        action={{
          label: 'OK',
          onPress: () => setSnackbarVisible(false),
        }}
      >
        {snackbarMessage}
      </Snackbar>
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
  loadingText: {
    marginTop: 16,
    color: '#6b7280',
  },
  header: {
    marginBottom: 8,
  },
  subtitle: {
    color: '#6b7280',
    marginBottom: 24,
  },
  card: {
    marginBottom: 16,
  },
  sectionTitle: {
    marginBottom: 4,
  },
  sectionSubtitle: {
    color: '#6b7280',
    marginBottom: 16,
  },
  input: {
    marginBottom: 16,
  },
  scopeCard: {
    marginBottom: 8,
  },
  scopeCardSelected: {
    borderColor: '#6366f1',
    borderWidth: 2,
    backgroundColor: '#eef2ff',
  },
  scopeCardContent: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: 8,
  },
  scopeInfo: {
    flex: 1,
  },
  scopeDescription: {
    color: '#6b7280',
    marginTop: 2,
  },
  actions: {
    flexDirection: 'row',
    gap: 12,
    marginTop: 8,
    marginBottom: 32,
  },
  button: {
    flex: 1,
  },
  createButton: {
    backgroundColor: '#6366f1',
  },
});
