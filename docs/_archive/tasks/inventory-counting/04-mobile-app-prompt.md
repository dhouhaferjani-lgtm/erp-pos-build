# Claude Code Implementation Prompt: Mobile App - Inventory Counting (Expo)

## Project Context

You are implementing the Expo React Native mobile app for the Inventory Counting module in AutoERP. This app is used by warehouse staff (counters) to perform physical inventory counts.

**CRITICAL SECURITY REQUIREMENT: BLIND COUNTING**
- Counters must NEVER see theoretical/expected quantities
- Counters must NEVER see other counters' results
- This prevents bias and ensures accurate counts

**Monorepo Structure:**
```
autoerp/
├── apps/
│   ├── api/                    # Laravel 12 backend (already implemented)
│   ├── web/                    # React frontend (already implemented)
│   └── mobile/                 # Expo React Native ← YOU ARE HERE
├── packages/
│   └── shared/                 # Shared TypeScript types
└── docs/
```

**Reference Documents:**
- Mobile App Spec: `/docs/features/inventory-counting-mobile-expo.md`

---

## PHASE 1: Project Initialization

### Task 1.1: Create Expo Project

```bash
cd apps
npx create-expo-app@latest mobile --template tabs
cd mobile
```

### Task 1.2: Install Dependencies

```bash
# Core
npx expo install expo-router expo-camera expo-barcode-scanner expo-notifications expo-secure-store expo-network

# State & Data
npm install @tanstack/react-query zustand zod react-hook-form @hookform/resolvers

# UI
npm install react-native-paper react-native-safe-area-context
npx expo install react-native-screens

# Storage
npx expo install @react-native-async-storage/async-storage

# Network
npm install axios @react-native-community/netinfo

# Icons
npm install lucide-react-native react-native-svg
npx expo install react-native-svg

# Dev
npm install -D @types/react jest-expo @testing-library/react-native
```

### Task 1.3: Configure app.config.ts

```typescript
// apps/mobile/app.config.ts

import { ExpoConfig, ConfigContext } from 'expo/config';

export default ({ config }: ConfigContext): ExpoConfig => ({
  ...config,
  name: 'AutoERP',
  slug: 'autoerp-mobile',
  version: '1.0.0',
  orientation: 'portrait',
  icon: './assets/images/icon.png',
  scheme: 'autoerp',
  userInterfaceStyle: 'automatic',
  splash: {
    image: './assets/images/splash.png',
    resizeMode: 'contain',
    backgroundColor: '#ffffff',
  },
  assetBundlePatterns: ['**/*'],
  ios: {
    supportsTablet: true,
    bundleIdentifier: 'com.autoerp.mobile',
    infoPlist: {
      NSCameraUsageDescription: 'Camera is used to scan product barcodes during inventory counting',
    },
  },
  android: {
    adaptiveIcon: {
      foregroundImage: './assets/images/adaptive-icon.png',
      backgroundColor: '#ffffff',
    },
    package: 'com.autoerp.mobile',
    permissions: ['CAMERA'],
  },
  web: {
    bundler: 'metro',
    output: 'static',
    favicon: './assets/images/favicon.png',
  },
  plugins: [
    'expo-router',
    'expo-secure-store',
    [
      'expo-camera',
      {
        cameraPermission: 'Allow AutoERP to access your camera for barcode scanning.',
      },
    ],
    [
      'expo-notifications',
      {
        icon: './assets/images/notification-icon.png',
        color: '#ffffff',
      },
    ],
  ],
  experiments: {
    typedRoutes: true,
  },
});
```

### Task 1.4: Configure TypeScript

```json
// apps/mobile/tsconfig.json
{
  "extends": "expo/tsconfig.base",
  "compilerOptions": {
    "strict": true,
    "baseUrl": ".",
    "paths": {
      "@/*": ["src/*"],
      "@autoerp/shared/*": ["../../packages/shared/src/*"]
    }
  },
  "include": [
    "**/*.ts",
    "**/*.tsx",
    ".expo/types/**/*.ts",
    "expo-env.d.ts"
  ]
}
```

### Verification:
```bash
cd apps/mobile
npx expo start
# Press 'i' for iOS simulator or 'a' for Android emulator
```

---

## PHASE 2: Core Infrastructure

### Task 2.1: Create API Client

```typescript
// src/lib/api.ts

import axios from 'axios';
import * as SecureStore from 'expo-secure-store';

const API_BASE_URL = process.env.EXPO_PUBLIC_API_URL || 'http://localhost:8000/api/v1';

export const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Request interceptor for auth token
api.interceptors.request.use(async (config) => {
  const token = await SecureStore.getItemAsync('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor for error handling
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      await SecureStore.deleteItemAsync('auth_token');
      // Navigation to login will be handled by auth provider
    }
    return Promise.reject(error);
  }
);
```

### Task 2.2: Create Query Provider

```typescript
// src/providers/QueryProvider.tsx

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { PropsWithChildren } from 'react';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes
      retry: 2,
    },
  },
});

export function QueryProvider({ children }: PropsWithChildren) {
  return (
    <QueryClientProvider client={queryClient}>
      {children}
    </QueryClientProvider>
  );
}
```

### Task 2.3: Create Auth Provider

```typescript
// src/providers/AuthProvider.tsx

import { createContext, useContext, useState, useEffect, PropsWithChildren } from 'react';
import * as SecureStore from 'expo-secure-store';
import { api } from '@/lib/api';

interface User {
  id: number;
  name: string;
  email: string;
  current_company_id: number;
}

interface AuthContextType {
  user: User | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  selectCompany: (companyId: number) => Promise<void>;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: PropsWithChildren) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  
  useEffect(() => {
    checkAuth();
  }, []);
  
  const checkAuth = async () => {
    try {
      const token = await SecureStore.getItemAsync('auth_token');
      if (token) {
        const response = await api.get('/user');
        setUser(response.data.data);
      }
    } catch (error) {
      await SecureStore.deleteItemAsync('auth_token');
    } finally {
      setIsLoading(false);
    }
  };
  
  const login = async (email: string, password: string) => {
    const response = await api.post('/auth/login', { email, password });
    const { token, user: userData } = response.data.data;
    
    await SecureStore.setItemAsync('auth_token', token);
    setUser(userData);
  };
  
  const logout = async () => {
    try {
      await api.post('/auth/logout');
    } catch (error) {
      // Ignore errors
    }
    await SecureStore.deleteItemAsync('auth_token');
    setUser(null);
  };
  
  const selectCompany = async (companyId: number) => {
    await api.post('/user/switch-company', { company_id: companyId });
    const response = await api.get('/user');
    setUser(response.data.data);
  };
  
  return (
    <AuthContext.Provider
      value={{
        user,
        isLoading,
        isAuthenticated: !!user,
        login,
        logout,
        selectCompany,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
```

---

## PHASE 3: Counting Feature API

### Task 3.1: Create Counting API Client

```typescript
// src/features/counting/api/countingApi.ts

import { api } from '@/lib/api';

export interface CountingTask {
  id: number;
  uuid: string;
  status: string;
  scope_type: string;
  scheduled_end: string | null;
  instructions: string | null;
  my_count_number: 1 | 2 | 3;
  progress: {
    counted: number;
    total: number;
  };
}

/**
 * CRITICAL: This interface must NEVER include theoretical_qty
 */
export interface CountingItem {
  id: number;
  product: {
    id: number;
    name: string;
    sku: string;
    barcode: string | null;
    image_url: string | null;
  };
  variant: {
    id: number;
    name: string;
  } | null;
  location: {
    id: number;
    code: string;
    name: string;
  };
  warehouse: {
    id: number;
    name: string;
  };
  unit_of_measure: string;
  is_counted: boolean;
  my_count: number | null;
  my_count_at: string | null;
  // NEVER INCLUDE: theoretical_qty, count_1_qty, count_2_qty, count_3_qty
}

export interface CountingSession {
  counting: {
    id: number;
    uuid: string;
    status: string;
    instructions: string | null;
    deadline: string | null;
  };
  my_count_number: 1 | 2 | 3;
  items: CountingItem[];
  progress: {
    counted: number;
    total: number;
  };
}

export const countingApi = {
  // Get assigned tasks
  getTasks: async (): Promise<CountingTask[]> => {
    const response = await api.get('/inventory/countings/my-tasks');
    return response.data.data;
  },
  
  // Get counting session (counter view - BLIND)
  getSession: async (countingId: number): Promise<CountingSession> => {
    const response = await api.get(`/inventory/countings/${countingId}/counter-view`);
    return response.data.data;
  },
  
  // Get items to count (BLIND - no theoretical qty!)
  getItems: async (countingId: number, uncountedOnly = false): Promise<CountingItem[]> => {
    const response = await api.get(`/inventory/countings/${countingId}/items/to-count`, {
      params: { uncounted_only: uncountedOnly },
    });
    return response.data.data;
  },
  
  // Submit count
  submitCount: async (
    countingId: number,
    itemId: number,
    quantity: number,
    notes?: string
  ): Promise<void> => {
    await api.post(`/inventory/countings/${countingId}/items/${itemId}/count`, {
      quantity,
      notes,
    });
  },
  
  // Lookup by barcode
  lookupByBarcode: async (
    countingId: number,
    barcode: string
  ): Promise<{ found: boolean; data?: CountingItem; message?: string }> => {
    const response = await api.get(`/inventory/countings/${countingId}/lookup`, {
      params: { barcode },
    });
    return response.data;
  },
};
```

### Task 3.2: Create React Query Hooks

```typescript
// src/features/counting/api/queries.ts

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { countingApi } from './countingApi';

export const countingKeys = {
  all: ['counting'] as const,
  tasks: () => [...countingKeys.all, 'tasks'] as const,
  session: (id: number) => [...countingKeys.all, 'session', id] as const,
  items: (id: number) => [...countingKeys.all, 'items', id] as const,
};

export function useCountingTasks() {
  return useQuery({
    queryKey: countingKeys.tasks(),
    queryFn: countingApi.getTasks,
  });
}

export function useCountingSession(countingId: number) {
  return useQuery({
    queryKey: countingKeys.session(countingId),
    queryFn: () => countingApi.getSession(countingId),
    enabled: !!countingId,
  });
}

export function useCountingItems(countingId: number, uncountedOnly = false) {
  return useQuery({
    queryKey: [...countingKeys.items(countingId), { uncountedOnly }],
    queryFn: () => countingApi.getItems(countingId, uncountedOnly),
    enabled: !!countingId,
  });
}

export function useCountingItem(countingId: number, itemId: number) {
  const { data: session } = useCountingSession(countingId);
  
  return {
    data: session?.items.find((item) => item.id === itemId),
    isLoading: !session,
  };
}
```

---

## PHASE 4: Zustand Store for Offline

### Task 4.1: Create Counting Store

```typescript
// src/features/counting/store/countingStore.ts

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';

interface PendingCount {
  id: string;
  countingId: number;
  itemId: number;
  quantity: number;
  notes?: string;
  countedAt: string;
  synced: boolean;
  syncError?: string;
}

interface CountingState {
  pendingCounts: PendingCount[];
  activeTaskId: number | null;
  
  addPendingCount: (count: Omit<PendingCount, 'id' | 'synced' | 'countedAt'>) => string;
  markSynced: (id: string) => void;
  markSyncError: (id: string, error: string) => void;
  removePendingCount: (id: string) => void;
  setActiveTask: (id: number | null) => void;
  getPendingForItem: (countingId: number, itemId: number) => PendingCount | undefined;
}

export const useCountingStore = create<CountingState>()(
  persist(
    (set, get) => ({
      pendingCounts: [],
      activeTaskId: null,
      
      addPendingCount: (count) => {
        const id = `pending_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
        const pendingCount: PendingCount = {
          ...count,
          id,
          synced: false,
          countedAt: new Date().toISOString(),
        };
        
        set((state) => ({
          pendingCounts: [...state.pendingCounts, pendingCount],
        }));
        
        return id;
      },
      
      markSynced: (id) => {
        set((state) => ({
          pendingCounts: state.pendingCounts.filter((c) => c.id !== id),
        }));
      },
      
      markSyncError: (id, error) => {
        set((state) => ({
          pendingCounts: state.pendingCounts.map((c) =>
            c.id === id ? { ...c, syncError: error } : c
          ),
        }));
      },
      
      removePendingCount: (id) => {
        set((state) => ({
          pendingCounts: state.pendingCounts.filter((c) => c.id !== id),
        }));
      },
      
      setActiveTask: (id) => set({ activeTaskId: id }),
      
      getPendingForItem: (countingId, itemId) => {
        return get().pendingCounts.find(
          (c) => c.countingId === countingId && c.itemId === itemId && !c.synced
        );
      },
    }),
    {
      name: 'counting-offline-storage',
      storage: createJSONStorage(() => AsyncStorage),
    }
  )
);
```

### Task 4.2: Create Submit Count Hook with Offline Support

```typescript
// src/features/counting/hooks/useSubmitCount.ts

import { useMutation, useQueryClient } from '@tanstack/react-query';
import NetInfo from '@react-native-community/netinfo';
import { useCountingStore } from '../store/countingStore';
import { countingApi } from '../api/countingApi';
import { countingKeys } from '../api/queries';

interface SubmitCountParams {
  countingId: number;
  itemId: number;
  quantity: number;
  notes?: string;
}

export function useSubmitCount() {
  const queryClient = useQueryClient();
  const { addPendingCount, markSynced } = useCountingStore();
  
  return useMutation({
    mutationFn: async (params: SubmitCountParams) => {
      const netState = await NetInfo.fetch();
      
      // If offline, save to local queue
      if (!netState.isConnected) {
        const pendingId = addPendingCount(params);
        return { offline: true, pendingId };
      }
      
      // Try to submit online
      try {
        await countingApi.submitCount(
          params.countingId,
          params.itemId,
          params.quantity,
          params.notes
        );
        return { offline: false };
      } catch (error: any) {
        // Network error - save offline
        if (error.code === 'ERR_NETWORK' || error.message?.includes('Network')) {
          const pendingId = addPendingCount(params);
          return { offline: true, pendingId };
        }
        throw error;
      }
    },
    
    onSuccess: (_, params) => {
      queryClient.invalidateQueries({
        queryKey: countingKeys.session(params.countingId),
      });
      queryClient.invalidateQueries({
        queryKey: countingKeys.tasks(),
      });
    },
  });
}
```

---

## PHASE 5: Expo Router Screens

### Task 5.1: Root Layout

```typescript
// app/_layout.tsx

import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { PaperProvider, MD3LightTheme } from 'react-native-paper';
import { QueryProvider } from '@/providers/QueryProvider';
import { AuthProvider } from '@/providers/AuthProvider';
import { GestureHandlerRootView } from 'react-native-gesture-handler';

const theme = {
  ...MD3LightTheme,
  colors: {
    ...MD3LightTheme.colors,
    primary: '#2563eb',
    secondary: '#64748b',
  },
};

export default function RootLayout() {
  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <QueryProvider>
        <AuthProvider>
          <PaperProvider theme={theme}>
            <Stack screenOptions={{ headerShown: false }}>
              <Stack.Screen name="(auth)" />
              <Stack.Screen name="(app)" />
            </Stack>
            <StatusBar style="auto" />
          </PaperProvider>
        </AuthProvider>
      </QueryProvider>
    </GestureHandlerRootView>
  );
}
```

### Task 5.2: App Tab Layout

```typescript
// app/(app)/_layout.tsx

import { Redirect, Tabs } from 'expo-router';
import { useAuth } from '@/providers/AuthProvider';
import { ActivityIndicator, View } from 'react-native';
import { Home, ClipboardList, User } from 'lucide-react-native';

export default function AppLayout() {
  const { isAuthenticated, isLoading } = useAuth();
  
  if (isLoading) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
        <ActivityIndicator size="large" />
      </View>
    );
  }
  
  if (!isAuthenticated) {
    return <Redirect href="/login" />;
  }
  
  return (
    <Tabs
      screenOptions={{
        headerShown: true,
        tabBarActiveTintColor: '#2563eb',
      }}
    >
      <Tabs.Screen
        name="index"
        options={{
          title: 'Home',
          tabBarIcon: ({ color, size }) => <Home size={size} color={color} />,
        }}
      />
      <Tabs.Screen
        name="tasks"
        options={{
          title: 'My Tasks',
          tabBarIcon: ({ color, size }) => <ClipboardList size={size} color={color} />,
        }}
      />
      <Tabs.Screen
        name="profile"
        options={{
          title: 'Profile',
          tabBarIcon: ({ color, size }) => <User size={size} color={color} />,
        }}
      />
      <Tabs.Screen
        name="counting"
        options={{
          href: null, // Hide from tab bar
          headerShown: false,
        }}
      />
    </Tabs>
  );
}
```

### Task 5.3: Tasks Screen

```typescript
// app/(app)/tasks.tsx

import { View, FlatList, RefreshControl, StyleSheet } from 'react-native';
import { Text, Card, ProgressBar, ActivityIndicator } from 'react-native-paper';
import { useRouter } from 'expo-router';
import { useCountingTasks } from '@/features/counting/api/queries';
import { OfflineIndicator } from '@/components/OfflineIndicator';
import { format, isPast } from 'date-fns';
import { AlertTriangle } from 'lucide-react-native';

export default function TasksScreen() {
  const router = useRouter();
  const { data: tasks, isLoading, refetch, isRefetching } = useCountingTasks();
  
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
      
      <FlatList
        data={tasks}
        keyExtractor={(item) => item.id.toString()}
        renderItem={({ item }) => {
          const progress = item.progress.total > 0
            ? item.progress.counted / item.progress.total
            : 0;
          const isOverdue = item.scheduled_end && isPast(new Date(item.scheduled_end));
          
          return (
            <Card
              style={[styles.card, isOverdue && styles.overdueCard]}
              onPress={() => router.push(`/counting/${item.id}`)}
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
                    Deadline: {format(new Date(item.scheduled_end), 'MMM d, h:mm a')}
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
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: '#f3f4f6' },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  list: { padding: 16, gap: 12 },
  card: { marginBottom: 12 },
  overdueCard: { borderColor: '#fca5a5', borderWidth: 1, backgroundColor: '#fef2f2' },
  cardHeader: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' },
  overdueBadge: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  overdueText: { color: '#dc2626', fontSize: 12, fontWeight: '600' },
  uuid: { color: '#6b7280', marginTop: 2 },
  progressContainer: { marginTop: 12 },
  progressBar: { height: 8, borderRadius: 4 },
  progressText: { color: '#6b7280', marginTop: 4 },
  deadline: { color: '#6b7280', marginTop: 8 },
  empty: { paddingVertical: 48, alignItems: 'center' },
  emptyText: { color: '#6b7280' },
});
```

### Task 5.4: Item Count Screen (CRITICAL: Blind Counting)

```typescript
// app/(app)/counting/[id]/item/[itemId].tsx

/**
 * CRITICAL SECURITY: This screen must NEVER display:
 * - theoretical_qty / expected quantity
 * - Other counters' results (count_1_qty, count_2_qty, count_3_qty)
 * - Any hints about what the count "should" be
 * 
 * This is BLIND COUNTING - the counter must count without bias.
 */

import { useState } from 'react';
import { View, Image, ScrollView, KeyboardAvoidingView, Platform, StyleSheet } from 'react-native';
import { Text, Button, TextInput, Card, IconButton } from 'react-native-paper';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useCountingItem } from '@/features/counting/api/queries';
import { useSubmitCount } from '@/features/counting/hooks/useSubmitCount';
import { Minus, Plus } from 'lucide-react-native';

export default function ItemCountScreen() {
  const { id, itemId } = useLocalSearchParams<{ id: string; itemId: string }>();
  const router = useRouter();
  
  const { data: item, isLoading } = useCountingItem(
    parseInt(id, 10),
    parseInt(itemId, 10)
  );
  const submitCount = useSubmitCount();
  
  const [quantity, setQuantity] = useState<string>(
    item?.my_count?.toString() || ''
  );
  const [notes, setNotes] = useState('');
  
  if (isLoading || !item) {
    return (
      <View style={styles.centered}>
        <Text>Loading...</Text>
      </View>
    );
  }
  
  const handleIncrement = () => {
    const current = parseInt(quantity, 10) || 0;
    setQuantity((current + 1).toString());
  };
  
  const handleDecrement = () => {
    const current = parseInt(quantity, 10) || 0;
    if (current > 0) {
      setQuantity((current - 1).toString());
    }
  };
  
  const handleSubmit = async (scanNext: boolean = false) => {
    const qty = parseFloat(quantity);
    if (isNaN(qty) || qty < 0) return;
    
    await submitCount.mutateAsync({
      countingId: parseInt(id, 10),
      itemId: parseInt(itemId, 10),
      quantity: qty,
      notes: notes || undefined,
    });
    
    if (scanNext) {
      router.replace(`/counting/${id}/scan`);
    } else {
      router.back();
    }
  };
  
  return (
    <KeyboardAvoidingView
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
      style={styles.container}
    >
      <ScrollView style={styles.scroll}>
        {/* Product Image */}
        {item.product.image_url && (
          <Image
            source={{ uri: item.product.image_url }}
            style={styles.image}
            resizeMode="contain"
          />
        )}
        
        <View style={styles.content}>
          {/* Product Info */}
          <Card style={styles.card}>
            <Card.Content>
              <Text variant="titleLarge">{item.product.name}</Text>
              {item.variant && (
                <Text variant="bodyMedium" style={styles.variant}>
                  {item.variant.name}
                </Text>
              )}
              <View style={styles.infoRow}>
                <View>
                  <Text variant="labelSmall" style={styles.label}>SKU</Text>
                  <Text variant="bodyMedium">{item.product.sku}</Text>
                </View>
                {item.product.barcode && (
                  <View>
                    <Text variant="labelSmall" style={styles.label}>Barcode</Text>
                    <Text variant="bodyMedium">{item.product.barcode}</Text>
                  </View>
                )}
              </View>
            </Card.Content>
          </Card>
          
          {/* Location */}
          <Card style={styles.card}>
            <Card.Content>
              <Text variant="labelSmall" style={styles.label}>Location</Text>
              <Text variant="titleMedium">{item.location.code}</Text>
              <Text variant="bodySmall" style={styles.locationDetail}>
                {item.location.name} • {item.warehouse.name}
              </Text>
            </Card.Content>
          </Card>
          
          {/* Quantity Input */}
          <Card style={styles.card}>
            <Card.Content>
              <Text variant="titleMedium" style={styles.quantityTitle}>
                Enter Quantity Counted
              </Text>
              
              <View style={styles.quantityRow}>
                <IconButton
                  icon={() => <Minus size={24} />}
                  mode="outlined"
                  size={32}
                  onPress={handleDecrement}
                />
                
                <TextInput
                  value={quantity}
                  onChangeText={setQuantity}
                  keyboardType="decimal-pad"
                  mode="outlined"
                  style={styles.quantityInput}
                  placeholder="0"
                />
                
                <IconButton
                  icon={() => <Plus size={24} />}
                  mode="outlined"
                  size={32}
                  onPress={handleIncrement}
                />
              </View>
              
              <Text variant="bodySmall" style={styles.unit}>
                Unit: {item.unit_of_measure}
              </Text>
            </Card.Content>
          </Card>
          
          {/* Notes */}
          <TextInput
            label="Notes (optional)"
            value={notes}
            onChangeText={setNotes}
            mode="outlined"
            multiline
            numberOfLines={3}
            style={styles.notes}
            placeholder="Add any observations..."
          />
          
          {/* Submit Buttons */}
          <View style={styles.buttons}>
            <Button
              mode="contained"
              onPress={() => handleSubmit(false)}
              loading={submitCount.isPending}
              disabled={!quantity || submitCount.isPending}
              contentStyle={styles.buttonContent}
            >
              Save Count
            </Button>
            
            <Button
              mode="outlined"
              onPress={() => handleSubmit(true)}
              loading={submitCount.isPending}
              disabled={!quantity || submitCount.isPending}
              contentStyle={styles.buttonContent}
            >
              Save & Scan Next
            </Button>
          </View>
          
          {/* 
            SECURITY NOTE: 
            We intentionally do NOT display theoretical_qty here.
            This is blind counting - the counter must not know the expected value.
          */}
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  scroll: { flex: 1, backgroundColor: '#f3f4f6' },
  image: { width: '100%', height: 192, backgroundColor: '#e5e7eb' },
  content: { padding: 16 },
  card: { marginBottom: 16 },
  variant: { color: '#6b7280' },
  infoRow: { flexDirection: 'row', gap: 24, marginTop: 8 },
  label: { color: '#9ca3af' },
  locationDetail: { color: '#6b7280' },
  quantityTitle: { textAlign: 'center', marginBottom: 16 },
  quantityRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 16 },
  quantityInput: { width: 120, textAlign: 'center', fontSize: 24 },
  unit: { textAlign: 'center', marginTop: 8, color: '#6b7280' },
  notes: { marginBottom: 16 },
  buttons: { gap: 12 },
  buttonContent: { height: 48 },
});
```

### Task 5.5: Barcode Scanner Screen

```typescript
// app/(app)/counting/[id]/scan.tsx

import { useState, useEffect } from 'react';
import { View, StyleSheet, Alert } from 'react-native';
import { Text, Button, IconButton, ActivityIndicator } from 'react-native-paper';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { countingApi } from '@/features/counting/api/countingApi';
import { Flashlight, FlashlightOff, X, Keyboard } from 'lucide-react-native';

export default function BarcodeScannerScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const [permission, requestPermission] = useCameraPermissions();
  const [torch, setTorch] = useState(false);
  const [scanned, setScanned] = useState(false);
  const [isLooking, setIsLooking] = useState(false);
  
  const handleBarcodeScan = async ({ data }: { data: string }) => {
    if (scanned || isLooking) return;
    setScanned(true);
    setIsLooking(true);
    
    try {
      const result = await countingApi.lookupByBarcode(parseInt(id, 10), data);
      
      if (result.found && result.data) {
        router.push(`/counting/${id}/item/${result.data.id}`);
      } else {
        Alert.alert(
          'Not Found',
          'This product is not part of the current count.',
          [{ text: 'Scan Again', onPress: () => setScanned(false) }]
        );
      }
    } catch (error) {
      Alert.alert('Error', 'Failed to look up barcode', [
        { text: 'Try Again', onPress: () => setScanned(false) },
      ]);
    } finally {
      setIsLooking(false);
    }
  };
  
  const handleManualEntry = () => {
    Alert.prompt(
      'Enter Barcode',
      'Type the barcode number manually',
      async (barcode) => {
        if (barcode) {
          await handleBarcodeScan({ data: barcode });
        }
      },
      'plain-text'
    );
  };
  
  if (!permission) {
    return (
      <View style={styles.centered}>
        <ActivityIndicator size="large" />
      </View>
    );
  }
  
  if (!permission.granted) {
    return (
      <View style={styles.permissionContainer}>
        <Text variant="bodyLarge" style={styles.permissionText}>
          Camera permission is required to scan barcodes
        </Text>
        <Button mode="contained" onPress={requestPermission}>
          Grant Permission
        </Button>
      </View>
    );
  }
  
  return (
    <View style={styles.container}>
      <CameraView
        style={StyleSheet.absoluteFillObject}
        facing="back"
        enableTorch={torch}
        barcodeScannerSettings={{
          barcodeTypes: ['ean13', 'ean8', 'upc_a', 'upc_e', 'code128', 'code39', 'qr'],
        }}
        onBarcodeScanned={scanned ? undefined : handleBarcodeScan}
      />
      
      {/* Scan Frame */}
      <View style={styles.overlay}>
        <View style={styles.scanFrame} />
        <Text style={styles.instructions}>
          {isLooking ? 'Looking up...' : 'Position barcode in frame'}
        </Text>
      </View>
      
      {/* Top Controls */}
      <View style={styles.topControls}>
        <IconButton
          icon={() => <X size={24} color="white" />}
          onPress={() => router.back()}
        />
        <IconButton
          icon={() =>
            torch ? (
              <FlashlightOff size={24} color="white" />
            ) : (
              <Flashlight size={24} color="white" />
            )
          }
          onPress={() => setTorch(!torch)}
        />
      </View>
      
      {/* Bottom Controls */}
      <View style={styles.bottomControls}>
        <Button
          mode="contained"
          icon={() => <Keyboard size={20} color="white" />}
          onPress={handleManualEntry}
        >
          Enter Manually
        </Button>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: 'black' },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },
  permissionContainer: { flex: 1, justifyContent: 'center', alignItems: 'center', padding: 16 },
  permissionText: { textAlign: 'center', marginBottom: 16 },
  overlay: { ...StyleSheet.absoluteFillObject, justifyContent: 'center', alignItems: 'center' },
  scanFrame: { width: 288, height: 288, borderWidth: 2, borderColor: 'white', borderRadius: 8 },
  instructions: { color: 'white', marginTop: 16 },
  topControls: {
    position: 'absolute',
    top: 48,
    left: 0,
    right: 0,
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
  },
  bottomControls: { position: 'absolute', bottom: 48, left: 16, right: 16 },
});
```

---

## PHASE 6: Offline Indicator Component

```typescript
// src/components/OfflineIndicator.tsx

import { View, StyleSheet } from 'react-native';
import { Text, ActivityIndicator } from 'react-native-paper';
import { useNetInfo } from '@react-native-community/netinfo';
import { useCountingStore } from '@/features/counting/store/countingStore';
import { WifiOff, CloudUpload } from 'lucide-react-native';

export function OfflineIndicator() {
  const netInfo = useNetInfo();
  const pendingCounts = useCountingStore((s) =>
    s.pendingCounts.filter((c) => !c.synced)
  );
  
  // Online and no pending - show nothing
  if (netInfo.isConnected && pendingCounts.length === 0) {
    return null;
  }
  
  // Offline
  if (!netInfo.isConnected) {
    return (
      <View style={[styles.banner, styles.offlineBanner]}>
        <WifiOff size={20} color="#b45309" />
        <Text style={styles.offlineText}>
          You're offline. Counts will sync when connected.
        </Text>
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
```

---

## Verification Commands

```bash
cd apps/mobile

# Type check
npx tsc --noEmit

# Start dev server
npx expo start

# Run on iOS simulator
npx expo run:ios

# Run on Android emulator
npx expo run:android

# Run tests
npm test

# Build for development
eas build --profile development --platform all
```

---

## CRITICAL TEST: Blind Counting Security

```typescript
// __tests__/blind-counting.test.ts

import { countingApi } from '@/features/counting/api/countingApi';

describe('Blind Counting Security', () => {
  it('CountingItem type should NOT have theoretical_qty', () => {
    // TypeScript compile-time check
    const item: CountingItem = {} as CountingItem;
    
    // @ts-expect-error - theoretical_qty should not exist
    const qty = item.theoretical_qty;
    
    // @ts-expect-error - count_1_qty should not exist
    const c1 = item.count_1_qty;
    
    // @ts-expect-error - count_2_qty should not exist
    const c2 = item.count_2_qty;
  });
  
  it('API response should not include theoretical quantities', async () => {
    const session = await countingApi.getSession(1);
    
    session.items.forEach((item) => {
      expect(item).not.toHaveProperty('theoretical_qty');
      expect(item).not.toHaveProperty('theoreticalQty');
      expect(item).not.toHaveProperty('count_1_qty');
      expect(item).not.toHaveProperty('count_2_qty');
      expect(item).not.toHaveProperty('count_3_qty');
    });
  });
});
```

---

## FINAL CHECKLIST

- [ ] Expo project initialized correctly
- [ ] All dependencies installed
- [ ] TypeScript configured with paths
- [ ] API client with auth interceptor
- [ ] React Query provider set up
- [ ] Auth flow works (login, logout)
- [ ] Tasks screen shows assigned tasks
- [ ] Session screen shows progress
- [ ] Item count screen works (BLIND - no theoretical qty!)
- [ ] Barcode scanner works
- [ ] Manual barcode entry works
- [ ] Offline queue stores pending counts
- [ ] Sync service uploads when back online
- [ ] Offline indicator shows status
- [ ] No TypeScript errors
- [ ] Tests pass
- [ ] App runs on iOS simulator
- [ ] App runs on Android emulator
