import { useState } from 'react';
import { View, FlatList, RefreshControl, StyleSheet } from 'react-native';
import {
  Text,
  Card,
  ProgressBar,
  ActivityIndicator,
  SegmentedButtons,
  Button,
  Chip,
} from 'react-native-paper';
import { useRouter } from 'expo-router';
import {
  useCountingTasks,
  useDraftCountings,
} from '@/features/counting/api/queries';
import { OfflineIndicator } from '@/components/OfflineIndicator';
import { format, isPast, formatDistanceToNow } from 'date-fns';
import { AlertTriangle, Plus, FileEdit } from 'lucide-react-native';

export default function TasksScreen() {
  const router = useRouter();
  const [activeTab, setActiveTab] = useState('tasks');

  const {
    data: tasks,
    isLoading: tasksLoading,
    refetch: refetchTasks,
    isRefetching: tasksRefetching,
  } = useCountingTasks();

  const {
    data: drafts,
    isLoading: draftsLoading,
    refetch: refetchDrafts,
    isRefetching: draftsRefetching,
  } = useDraftCountings();

  const isLoading = activeTab === 'tasks' ? tasksLoading : draftsLoading;
  const isRefetching = activeTab === 'tasks' ? tasksRefetching : draftsRefetching;
  const refetch = activeTab === 'tasks' ? refetchTasks : refetchDrafts;

  if (isLoading) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <OfflineIndicator />

      <View style={styles.tabContainer}>
        <SegmentedButtons
          value={activeTab}
          onValueChange={setActiveTab}
          buttons={[
            {
              value: 'tasks',
              label: 'My Tasks',
            },
            {
              value: 'drafts',
              label: 'My Drafts',
            },
          ]}
          style={styles.tabs}
        />
      </View>

      {activeTab === 'tasks' ? (
        <FlatList
          data={tasks}
          keyExtractor={(item) => item.id.toString()}
          renderItem={({ item }) => {
            const progress =
              item.progress.total > 0
                ? item.progress.counted / item.progress.total
                : 0;
            const isOverdue =
              item.scheduled_end && isPast(new Date(item.scheduled_end));

            return (
              <Card
                style={[styles.card, isOverdue && styles.overdueCard]}
                onPress={() => router.push(`/counting/${item.id}` as const)}
              >
                <Card.Content>
                  <View style={styles.cardHeader}>
                    <Text variant="titleMedium">
                      {item.scope_type.replace('_', ' ')} Count
                    </Text>
                    {isOverdue && (
                      <View style={styles.overdueBadge}>
                        <AlertTriangle size={14} color="#dc2626" />
                        <Text style={styles.overdueText}>OVERDUE</Text>
                      </View>
                    )}
                  </View>

                  <Text variant="bodySmall" style={styles.uuid}>
                    #{item.uuid.slice(0, 8)}
                  </Text>

                  <View style={styles.progressContainer}>
                    <ProgressBar progress={progress} style={styles.progressBar} />
                    <Text variant="bodySmall" style={styles.progressText}>
                      {item.progress.counted} / {item.progress.total} items
                    </Text>
                  </View>

                  {item.scheduled_end && (
                    <Text variant="bodySmall" style={styles.deadline}>
                      Deadline:{' '}
                      {format(new Date(item.scheduled_end), 'MMM d, h:mm a')}
                    </Text>
                  )}
                </Card.Content>
              </Card>
            );
          }}
          refreshControl={
            <RefreshControl refreshing={isRefetching} onRefresh={refetch} />
          }
          contentContainerStyle={styles.list}
          ListEmptyComponent={
            <View style={styles.empty}>
              <Text variant="bodyLarge" style={styles.emptyText}>
                No counting tasks assigned
              </Text>
            </View>
          }
        />
      ) : (
        <View style={{ flex: 1 }}>
          <View style={styles.draftHeader}>
            <Button
              mode="contained"
              icon={({ size, color }) => <Plus size={size} color={color} />}
              onPress={() => router.push('/counting/create-draft' as const)}
              style={styles.createButton}
            >
              Create Count
            </Button>
          </View>

          <FlatList
            data={drafts}
            keyExtractor={(item) => item.uuid}
            renderItem={({ item }) => {
              const statusColors: Record<string, string> = {
                draft: '#f59e0b',
                syncing: '#3b82f6',
                synced: '#10b981',
                sync_error: '#ef4444',
              };

              return (
                <Card
                  style={styles.card}
                  onPress={() =>
                    router.push(`/counting/draft/${item.id}` as const)
                  }
                >
                  <Card.Content>
                    <View style={styles.cardHeader}>
                      <View style={styles.draftTitleRow}>
                        <FileEdit size={18} color="#6b7280" />
                        <Text variant="titleMedium" numberOfLines={1}>
                          {item.title || 'Untitled Draft'}
                        </Text>
                      </View>
                      <Chip
                        mode="flat"
                        textStyle={{
                          fontSize: 10,
                          color: statusColors[item.status],
                        }}
                        style={{
                          backgroundColor: `${statusColors[item.status]}20`,
                          height: 24,
                        }}
                      >
                        {item.status.replace('_', ' ').toUpperCase()}
                      </Chip>
                    </View>

                    <Text variant="bodySmall" style={styles.uuid}>
                      #{item.uuid.slice(0, 8)}
                    </Text>

                    <View style={styles.draftInfo}>
                      <Text variant="bodySmall" style={styles.draftInfoText}>
                        {item.productCount} product
                        {item.productCount !== 1 ? 's' : ''} added
                      </Text>
                      <Text variant="bodySmall" style={styles.draftInfoText}>
                        •
                      </Text>
                      <Text variant="bodySmall" style={styles.draftInfoText}>
                        {item.scopeType.replace('_', ' ')}
                      </Text>
                    </View>

                    <Text variant="bodySmall" style={styles.lastModified}>
                      Last modified:{' '}
                      {item.lastModifiedAt
                        ? formatDistanceToNow(new Date(item.lastModifiedAt), {
                            addSuffix: true,
                          })
                        : formatDistanceToNow(new Date(item.createdAt), {
                            addSuffix: true,
                          })}
                    </Text>
                  </Card.Content>
                </Card>
              );
            }}
            refreshControl={
              <RefreshControl refreshing={isRefetching} onRefresh={refetch} />
            }
            contentContainerStyle={styles.list}
            ListEmptyComponent={
              <View style={styles.empty}>
                <Text variant="bodyLarge" style={styles.emptyText}>
                  No draft counts yet
                </Text>
                <Text variant="bodySmall" style={styles.emptySubtext}>
                  Create a new count to start adding products
                </Text>
              </View>
            }
          />
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f3f4f6' },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  tabContainer: { paddingHorizontal: 16, paddingVertical: 12 },
  tabs: { backgroundColor: '#fff' },
  draftHeader: {
    paddingHorizontal: 16,
    paddingBottom: 12,
  },
  createButton: {
    borderRadius: 8,
  },
  list: { padding: 16, gap: 12 },
  card: { marginBottom: 12 },
  overdueCard: {
    borderColor: '#fca5a5',
    borderWidth: 1,
    backgroundColor: '#fef2f2',
  },
  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  draftTitleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    flex: 1,
    marginEnd: 8,
  },
  overdueBadge: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  overdueText: { color: '#dc2626', fontSize: 12, fontWeight: '600' },
  uuid: { color: '#6b7280', marginTop: 2 },
  progressContainer: { marginTop: 12 },
  progressBar: { height: 8, borderRadius: 4 },
  progressText: { color: '#6b7280', marginTop: 4 },
  deadline: { color: '#6b7280', marginTop: 8 },
  draftInfo: {
    flexDirection: 'row',
    gap: 8,
    marginTop: 8,
    alignItems: 'center',
  },
  draftInfoText: { color: '#6b7280' },
  lastModified: { color: '#6b7280', marginTop: 8 },
  empty: { paddingVertical: 48, alignItems: 'center' },
  emptyText: { color: '#6b7280' },
  emptySubtext: { color: '#9ca3af', marginTop: 4 },
});
