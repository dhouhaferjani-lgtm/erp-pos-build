# Claude Code Implementation Prompt: Web Frontend - Inventory Counting

## Project Context

You are implementing the web frontend for the Inventory Counting module in AutoERP. This is the admin/supervisor interface for creating counting operations, monitoring progress, reviewing results, and generating reports.

**Monorepo Structure:**
```
autoerp/
├── apps/
│   ├── api/                    # Laravel 12 backend (already implemented)
│   ├── web/                    # React frontend (Vite) ← YOU ARE HERE
│   └── mobile/                 # Expo React Native app
├── packages/
│   └── shared/                 # Shared TypeScript types
└── docs/
```

**Existing Stack (apps/web):**
- React 18+ with TypeScript
- Vite
- React Router v6
- React Query (TanStack Query)
- Zustand for local state
- React Hook Form + Zod
- Tailwind CSS
- shadcn/ui components (or similar)

**Reference Documents:**
- Web Frontend Spec: `/docs/features/inventory-counting-web-frontend.md`

---

## PHASE 1: Shared Types

### Task 1.1: Create TypeScript Types

Create in `packages/shared/src/inventory-counting/`:

```typescript
// packages/shared/src/inventory-counting/types.ts

export type CountingScopeType = 
  | 'product_location'
  | 'product'
  | 'location'
  | 'category'
  | 'warehouse'
  | 'full_inventory';

export type CountingStatus =
  | 'draft'
  | 'scheduled'
  | 'count_1_in_progress'
  | 'count_1_completed'
  | 'count_2_in_progress'
  | 'count_2_completed'
  | 'count_3_in_progress'
  | 'count_3_completed'
  | 'pending_review'
  | 'finalized'
  | 'cancelled';

export type CountingExecutionMode = 'parallel' | 'sequential';

export type ItemResolutionMethod =
  | 'pending'
  | 'auto_all_match'
  | 'auto_counters_agree'
  | 'third_count_decisive'
  | 'manual_override';

export type AssignmentStatus = 'pending' | 'in_progress' | 'completed' | 'overdue';

export interface CountingUser {
  id: number;
  name: string;
  email: string;
  avatar_url?: string;
}

export interface CountingProgress {
  count_1: { counted: number; total: number; percentage: number } | null;
  count_2: { counted: number; total: number; percentage: number } | null;
  count_3: { counted: number; total: number; percentage: number } | null;
  overall: number;
}

export interface CountingAssignment {
  id: number;
  user_id: number;
  user: CountingUser;
  count_number: 1 | 2 | 3;
  status: AssignmentStatus;
  assigned_at: string;
  started_at: string | null;
  completed_at: string | null;
  deadline: string | null;
  total_items: number;
  counted_items: number;
  progress_percentage: number;
}

export interface InventoryCounting {
  id: number;
  uuid: string;
  company_id: number;
  scope_type: CountingScopeType;
  scope_filters: Record<string, unknown>;
  execution_mode: CountingExecutionMode;
  status: CountingStatus;
  scheduled_start: string | null;
  scheduled_end: string | null;
  requires_count_2: boolean;
  requires_count_3: boolean;
  allow_unexpected_items: boolean;
  instructions: string | null;
  
  count_1_user: CountingUser | null;
  count_2_user: CountingUser | null;
  count_3_user: CountingUser | null;
  created_by: CountingUser;
  
  assignments: CountingAssignment[];
  progress: CountingProgress;
  
  items_count?: number;
  
  created_at: string;
  updated_at: string;
  activated_at: string | null;
  finalized_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
}

export interface CountingItemProduct {
  id: number;
  name: string;
  sku: string;
  barcode: string | null;
  image_url: string | null;
}

export interface CountingItemLocation {
  id: number;
  code: string;
  name: string;
}

export interface CountingItemCount {
  qty: number;
  at: string;
  notes: string | null;
}

export interface ReconciliationItem {
  id: number;
  product: CountingItemProduct;
  variant: { id: number; name: string } | null;
  location: CountingItemLocation;
  warehouse: { id: number; name: string };
  
  theoretical_qty: number;
  count_1: CountingItemCount | null;
  count_2: CountingItemCount | null;
  count_3: CountingItemCount | null;
  
  final_qty: number | null;
  variance: number | null;
  variance_percentage: number | null;
  
  resolution_method: ItemResolutionMethod;
  resolution_notes: string | null;
  
  is_flagged: boolean;
  flag_reason: string | null;
}

export interface CountingDashboard {
  summary: {
    active: number;
    pending_review: number;
    completed_this_month: number;
    overdue: number;
  };
  active_counts: InventoryCounting[];
  pending_review: InventoryCounting[];
}

export interface DiscrepancyReportSummary {
  total_items_counted: number;
  items_no_variance: number;
  items_with_variance: number;
  variance_breakdown: {
    auto_all_match: number;
    auto_counters_agree: number;
    third_count_decisive: number;
    manual_override: number;
  };
  total_variance_value: {
    positive: number;
    negative: number;
    net: number;
    currency: string;
  };
}

export interface DiscrepancyReportCounterPerformance {
  user: CountingUser;
  items_counted: number;
  matched_other_counter: number;
  matched_theoretical: number;
  times_proven_wrong_by_3rd: number;
  accuracy_rate: number;
}

export interface DiscrepancyReport {
  report_id: string;
  generated_at: string;
  generated_by: CountingUser;
  counting: InventoryCounting;
  summary: DiscrepancyReportSummary;
  flagged_items: ReconciliationItem[];
  counter_performance: DiscrepancyReportCounterPerformance[];
}

// Form types
export interface CreateCountingFormData {
  scope_type: CountingScopeType;
  scope_filters: {
    product_ids?: number[];
    category_ids?: number[];
    warehouse_ids?: number[];
    location_ids?: number[];
    location_id?: number;
  };
  execution_mode: CountingExecutionMode;
  requires_count_2: boolean;
  requires_count_3: boolean;
  allow_unexpected_items: boolean;
  count_1_user_id: number;
  count_2_user_id?: number;
  count_3_user_id?: number;
  scheduled_start?: string;
  scheduled_end?: string;
  instructions?: string;
}

export interface ManualOverrideFormData {
  quantity: number;
  notes: string;
}

// API Response types
export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}

export interface CountingFilters {
  status?: CountingStatus | 'all';
  warehouse_id?: number;
  search?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}
```

---

## PHASE 2: API Integration

### Task 2.1: Create API Client

```typescript
// apps/web/src/features/inventory-counting/api/countingApi.ts

import { api } from '@/lib/api';
import type {
  InventoryCounting,
  CountingDashboard,
  ReconciliationItem,
  DiscrepancyReport,
  CreateCountingFormData,
  CountingFilters,
  PaginatedResponse,
} from '@autoerp/shared/inventory-counting';

const BASE_URL = '/api/v1/inventory/countings';

export const countingApi = {
  // Dashboard
  getDashboard: async (): Promise<CountingDashboard> => {
    const response = await api.get(`${BASE_URL}/dashboard`);
    return response.data.data;
  },

  // List
  list: async (filters: CountingFilters): Promise<PaginatedResponse<InventoryCounting>> => {
    const response = await api.get(BASE_URL, { params: filters });
    return response.data;
  },

  // Detail
  getDetail: async (id: number): Promise<InventoryCounting> => {
    const response = await api.get(`${BASE_URL}/${id}`);
    return response.data.data;
  },

  // Create
  create: async (data: CreateCountingFormData): Promise<InventoryCounting> => {
    const response = await api.post(BASE_URL, data);
    return response.data.data;
  },

  // Activate
  activate: async (id: number): Promise<void> => {
    await api.post(`${BASE_URL}/${id}/activate`);
  },

  // Cancel
  cancel: async (id: number, reason: string): Promise<void> => {
    await api.post(`${BASE_URL}/${id}/cancel`, { reason });
  },

  // Finalize
  finalize: async (id: number): Promise<void> => {
    await api.post(`${BASE_URL}/${id}/finalize`);
  },

  // Reconciliation
  getReconciliation: async (id: number): Promise<{
    summary: {
      total: number;
      auto_resolved: number;
      needs_attention: number;
      manually_overridden: number;
    };
    items: ReconciliationItem[];
  }> => {
    const response = await api.get(`${BASE_URL}/${id}/reconciliation`);
    return response.data.data;
  },

  // Trigger third count
  triggerThirdCount: async (countingId: number, itemIds: number[]): Promise<void> => {
    await api.post(`${BASE_URL}/${countingId}/trigger-third-count`, { item_ids: itemIds });
  },

  // Manual override
  manualOverride: async (
    itemId: number,
    quantity: number,
    notes: string
  ): Promise<void> => {
    await api.post(`${BASE_URL}/items/${itemId}/override`, {
      quantity,
      notes,
    });
  },

  // Report
  getReport: async (id: number): Promise<DiscrepancyReport> => {
    const response = await api.get(`${BASE_URL}/${id}/report`);
    return response.data.data;
  },

  // Export report
  exportReport: async (id: number, format: 'pdf' | 'xlsx'): Promise<Blob> => {
    const response = await api.get(`${BASE_URL}/${id}/report/export`, {
      params: { format },
      responseType: 'blob',
    });
    return response.data;
  },
};
```

### Task 2.2: Create React Query Hooks

```typescript
// apps/web/src/features/inventory-counting/api/queries.ts

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { countingApi } from './countingApi';
import type { CountingFilters, CreateCountingFormData } from '@autoerp/shared/inventory-counting';
import { toast } from 'sonner';

// Query Keys
export const countingKeys = {
  all: ['counting'] as const,
  lists: () => [...countingKeys.all, 'list'] as const,
  list: (filters: CountingFilters) => [...countingKeys.lists(), filters] as const,
  details: () => [...countingKeys.all, 'detail'] as const,
  detail: (id: number) => [...countingKeys.details(), id] as const,
  reconciliation: (id: number) => [...countingKeys.all, 'reconciliation', id] as const,
  report: (id: number) => [...countingKeys.all, 'report', id] as const,
  dashboard: () => [...countingKeys.all, 'dashboard'] as const,
};

// Queries
export function useCountingDashboard() {
  return useQuery({
    queryKey: countingKeys.dashboard(),
    queryFn: countingApi.getDashboard,
    refetchInterval: 30000, // Refresh every 30s
  });
}

export function useCountingList(filters: CountingFilters) {
  return useQuery({
    queryKey: countingKeys.list(filters),
    queryFn: () => countingApi.list(filters),
  });
}

export function useCountingDetail(id: number) {
  return useQuery({
    queryKey: countingKeys.detail(id),
    queryFn: () => countingApi.getDetail(id),
    enabled: !!id,
  });
}

export function useReconciliation(countingId: number) {
  return useQuery({
    queryKey: countingKeys.reconciliation(countingId),
    queryFn: () => countingApi.getReconciliation(countingId),
    enabled: !!countingId,
  });
}

export function useDiscrepancyReport(countingId: number) {
  return useQuery({
    queryKey: countingKeys.report(countingId),
    queryFn: () => countingApi.getReport(countingId),
    enabled: !!countingId,
  });
}

// Mutations
export function useCreateCounting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: CreateCountingFormData) => countingApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: countingKeys.lists() });
      queryClient.invalidateQueries({ queryKey: countingKeys.dashboard() });
      toast.success('Counting operation created successfully');
    },
    onError: (error: Error) => {
      toast.error(`Failed to create counting: ${error.message}`);
    },
  });
}

export function useActivateCounting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => countingApi.activate(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: countingKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: countingKeys.dashboard() });
      toast.success('Counting activated');
    },
    onError: (error: Error) => {
      toast.error(`Failed to activate: ${error.message}`);
    },
  });
}

export function useCancelCounting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      countingApi.cancel(id, reason),
    onSuccess: (_, { id }) => {
      queryClient.invalidateQueries({ queryKey: countingKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: countingKeys.dashboard() });
      toast.success('Counting cancelled');
    },
    onError: (error: Error) => {
      toast.error(`Failed to cancel: ${error.message}`);
    },
  });
}

export function useFinalizeCounting() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => countingApi.finalize(id),
    onSuccess: (_, id) => {
      queryClient.invalidateQueries({ queryKey: countingKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: countingKeys.dashboard() });
      toast.success('Counting finalized successfully');
    },
    onError: (error: Error) => {
      toast.error(`Failed to finalize: ${error.message}`);
    },
  });
}

export function useTriggerThirdCount() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ countingId, itemIds }: { countingId: number; itemIds: number[] }) =>
      countingApi.triggerThirdCount(countingId, itemIds),
    onSuccess: (_, { countingId }) => {
      queryClient.invalidateQueries({ queryKey: countingKeys.reconciliation(countingId) });
      queryClient.invalidateQueries({ queryKey: countingKeys.detail(countingId) });
      toast.success('Third count triggered');
    },
    onError: (error: Error) => {
      toast.error(`Failed to trigger third count: ${error.message}`);
    },
  });
}

export function useManualOverride() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      itemId,
      quantity,
      notes,
    }: {
      itemId: number;
      quantity: number;
      notes: string;
    }) => countingApi.manualOverride(itemId, quantity, notes),
    onSuccess: () => {
      // Invalidate all reconciliation queries
      queryClient.invalidateQueries({ queryKey: countingKeys.all });
      toast.success('Override applied');
    },
    onError: (error: Error) => {
      toast.error(`Failed to apply override: ${error.message}`);
    },
  });
}

export function useExportReport() {
  return useMutation({
    mutationFn: ({ id, format }: { id: number; format: 'pdf' | 'xlsx' }) =>
      countingApi.exportReport(id, format),
    onSuccess: (blob, { format }) => {
      // Download the file
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `discrepancy-report.${format}`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      a.remove();
      toast.success('Report downloaded');
    },
    onError: (error: Error) => {
      toast.error(`Failed to export report: ${error.message}`);
    },
  });
}
```

---

## PHASE 3: UI Components

### Task 3.1: Create Status Badge Component

```typescript
// apps/web/src/features/inventory-counting/components/CountingStatusBadge.tsx

import { Badge } from '@/components/ui/badge';
import type { CountingStatus } from '@autoerp/shared/inventory-counting';
import { cn } from '@/lib/utils';

interface Props {
  status: CountingStatus;
  className?: string;
}

const statusConfig: Record<CountingStatus, { label: string; variant: string; className: string }> = {
  draft: {
    label: 'Draft',
    variant: 'secondary',
    className: 'bg-gray-100 text-gray-700',
  },
  scheduled: {
    label: 'Scheduled',
    variant: 'secondary',
    className: 'bg-blue-100 text-blue-700',
  },
  count_1_in_progress: {
    label: 'Count 1 In Progress',
    variant: 'default',
    className: 'bg-yellow-100 text-yellow-800',
  },
  count_1_completed: {
    label: 'Count 1 Complete',
    variant: 'default',
    className: 'bg-yellow-200 text-yellow-900',
  },
  count_2_in_progress: {
    label: 'Count 2 In Progress',
    variant: 'default',
    className: 'bg-orange-100 text-orange-800',
  },
  count_2_completed: {
    label: 'Count 2 Complete',
    variant: 'default',
    className: 'bg-orange-200 text-orange-900',
  },
  count_3_in_progress: {
    label: 'Count 3 In Progress',
    variant: 'default',
    className: 'bg-purple-100 text-purple-800',
  },
  count_3_completed: {
    label: 'Count 3 Complete',
    variant: 'default',
    className: 'bg-purple-200 text-purple-900',
  },
  pending_review: {
    label: 'Pending Review',
    variant: 'warning',
    className: 'bg-amber-100 text-amber-800',
  },
  finalized: {
    label: 'Finalized',
    variant: 'success',
    className: 'bg-green-100 text-green-800',
  },
  cancelled: {
    label: 'Cancelled',
    variant: 'destructive',
    className: 'bg-red-100 text-red-700',
  },
};

export function CountingStatusBadge({ status, className }: Props) {
  const config = statusConfig[status];
  
  return (
    <Badge className={cn(config.className, className)}>
      {config.label}
    </Badge>
  );
}
```

### Task 3.2: Create Counting Card Component

```typescript
// apps/web/src/features/inventory-counting/components/CountingCard.tsx

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { CountingStatusBadge } from './CountingStatusBadge';
import type { InventoryCounting } from '@autoerp/shared/inventory-counting';
import { formatDistanceToNow, isPast, format } from 'date-fns';
import { Clock, AlertTriangle, Eye, Bell } from 'lucide-react';
import { Link } from 'react-router-dom';
import { cn } from '@/lib/utils';

interface Props {
  counting: InventoryCounting;
  onSendReminder?: (id: number) => void;
}

export function CountingCard({ counting, onSendReminder }: Props) {
  const isOverdue = counting.scheduled_end && isPast(new Date(counting.scheduled_end));
  
  const getScopeLabel = () => {
    switch (counting.scope_type) {
      case 'full_inventory': return 'Full Inventory';
      case 'warehouse': return 'Warehouse';
      case 'category': return 'Category';
      case 'location': return 'Location';
      case 'product': return 'Product';
      case 'product_location': return 'Product + Location';
    }
  };
  
  return (
    <Card className={cn(
      'transition-shadow hover:shadow-md',
      isOverdue && 'border-red-300 bg-red-50/50'
    )}>
      <CardHeader className="pb-2">
        <div className="flex items-start justify-between">
          <div className="space-y-1">
            <CardTitle className="text-lg flex items-center gap-2">
              {getScopeLabel()} Count
              {isOverdue && (
                <span className="inline-flex items-center text-sm font-medium text-red-600">
                  <AlertTriangle className="w-4 h-4 mr-1" />
                  OVERDUE
                </span>
              )}
            </CardTitle>
            <p className="text-sm text-muted-foreground">
              #{counting.uuid.slice(0, 8)}
            </p>
          </div>
          <CountingStatusBadge status={counting.status} />
        </div>
      </CardHeader>
      
      <CardContent className="space-y-4">
        {/* Overall Progress */}
        <div>
          <div className="flex justify-between text-sm mb-1">
            <span>Overall Progress</span>
            <span className="font-medium">{counting.progress.overall}%</span>
          </div>
          <Progress value={counting.progress.overall} className="h-2" />
        </div>
        
        {/* Counter Progress */}
        <div className="space-y-2">
          {counting.progress.count_1 && (
            <CounterProgress
              label="Counter 1"
              user={counting.count_1_user}
              progress={counting.progress.count_1}
            />
          )}
          {counting.progress.count_2 && (
            <CounterProgress
              label="Counter 2"
              user={counting.count_2_user}
              progress={counting.progress.count_2}
            />
          )}
          {counting.progress.count_3 && (
            <CounterProgress
              label="Counter 3"
              user={counting.count_3_user}
              progress={counting.progress.count_3}
            />
          )}
        </div>
        
        {/* Deadline */}
        {counting.scheduled_end && (
          <div className="flex items-center text-sm text-muted-foreground">
            <Clock className="w-4 h-4 mr-2" />
            <span>
              Deadline: {format(new Date(counting.scheduled_end), 'MMM d, yyyy h:mm a')}
              {!isOverdue && (
                <span className="ml-1">
                  ({formatDistanceToNow(new Date(counting.scheduled_end), { addSuffix: true })})
                </span>
              )}
            </span>
          </div>
        )}
        
        {/* Actions */}
        <div className="flex gap-2 pt-2">
          <Button asChild variant="outline" size="sm" className="flex-1">
            <Link to={`/inventory/counting/${counting.id}`}>
              <Eye className="w-4 h-4 mr-2" />
              View Details
            </Link>
          </Button>
          {onSendReminder && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() => onSendReminder(counting.id)}
            >
              <Bell className="w-4 h-4" />
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function CounterProgress({
  label,
  user,
  progress,
}: {
  label: string;
  user: { name: string; avatar_url?: string } | null;
  progress: { counted: number; total: number; percentage: number };
}) {
  const isComplete = progress.percentage === 100;
  
  return (
    <div className="flex items-center gap-3">
      <Avatar className="w-6 h-6">
        <AvatarImage src={user?.avatar_url} />
        <AvatarFallback className="text-xs">
          {user?.name?.charAt(0) || '?'}
        </AvatarFallback>
      </Avatar>
      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between text-xs">
          <span className="truncate">{user?.name || 'Unassigned'}</span>
          <span className={cn(
            'font-medium',
            isComplete && 'text-green-600'
          )}>
            {progress.percentage}%
            {isComplete && ' ✓'}
          </span>
        </div>
        <Progress value={progress.percentage} className="h-1.5 mt-1" />
      </div>
    </div>
  );
}
```

### Task 3.3: Create Reconciliation Table Component

```typescript
// apps/web/src/features/inventory-counting/components/ReconciliationTable.tsx

import { useState } from 'react';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import {
  useReconciliation,
  useTriggerThirdCount,
  useManualOverride,
} from '../api/queries';
import { ManualOverrideDialog } from './ManualOverrideDialog';
import type { ReconciliationItem } from '@autoerp/shared/inventory-counting';
import { cn } from '@/lib/utils';
import { format } from 'date-fns';
import { Check, AlertTriangle, X, MoreHorizontal } from 'lucide-react';

interface Props {
  countingId: number;
}

export function ReconciliationTable({ countingId }: Props) {
  const { data, isLoading } = useReconciliation(countingId);
  const triggerThirdCount = useTriggerThirdCount();
  const manualOverride = useManualOverride();
  
  const [selectedItems, setSelectedItems] = useState<number[]>([]);
  const [overrideItem, setOverrideItem] = useState<ReconciliationItem | null>(null);
  
  if (isLoading) {
    return <div className="p-8 text-center">Loading...</div>;
  }
  
  if (!data) {
    return <div className="p-8 text-center">No data available</div>;
  }
  
  const { summary, items } = data;
  
  const handleSelectAll = (checked: boolean) => {
    if (checked) {
      setSelectedItems(items.filter(i => i.is_flagged && !i.final_qty).map(i => i.id));
    } else {
      setSelectedItems([]);
    }
  };
  
  const handleSelect = (id: number, checked: boolean) => {
    if (checked) {
      setSelectedItems([...selectedItems, id]);
    } else {
      setSelectedItems(selectedItems.filter(i => i !== id));
    }
  };
  
  const handleBulkThirdCount = () => {
    triggerThirdCount.mutate({
      countingId,
      itemIds: selectedItems,
    });
    setSelectedItems([]);
  };
  
  const handleOverride = (quantity: number, notes: string) => {
    if (!overrideItem) return;
    
    manualOverride.mutate({
      itemId: overrideItem.id,
      quantity,
      notes,
    });
    setOverrideItem(null);
  };
  
  const getResolutionBadge = (item: ReconciliationItem) => {
    switch (item.resolution_method) {
      case 'auto_all_match':
        return (
          <Badge className="bg-green-100 text-green-800">
            <Check className="w-3 h-3 mr-1" />
            All Match
          </Badge>
        );
      case 'auto_counters_agree':
        return (
          <Badge className="bg-yellow-100 text-yellow-800">
            <AlertTriangle className="w-3 h-3 mr-1" />
            Variance
          </Badge>
        );
      case 'third_count_decisive':
        return (
          <Badge className="bg-blue-100 text-blue-800">
            3rd Decisive
          </Badge>
        );
      case 'manual_override':
        return (
          <Badge className="bg-purple-100 text-purple-800">
            Override
          </Badge>
        );
      case 'pending':
        if (item.is_flagged) {
          return (
            <Badge className="bg-red-100 text-red-800">
              <X className="w-3 h-3 mr-1" />
              Needs Action
            </Badge>
          );
        }
        return <Badge variant="secondary">Pending</Badge>;
    }
  };
  
  const getRowClassName = (item: ReconciliationItem) => {
    if (item.resolution_method === 'auto_all_match') {
      return 'bg-green-50/50';
    }
    if (item.resolution_method === 'auto_counters_agree') {
      return 'bg-yellow-50/50';
    }
    if (item.is_flagged && !item.final_qty) {
      return 'bg-red-50/50';
    }
    return '';
  };
  
  return (
    <div className="space-y-4">
      {/* Summary */}
      <div className="grid grid-cols-4 gap-4">
        <SummaryCard label="Total Items" value={summary.total} />
        <SummaryCard label="Auto Resolved" value={summary.auto_resolved} variant="success" />
        <SummaryCard label="Needs Attention" value={summary.needs_attention} variant="warning" />
        <SummaryCard label="Manual Override" value={summary.manually_overridden} />
      </div>
      
      {/* Bulk Actions */}
      {selectedItems.length > 0 && (
        <div className="flex items-center gap-4 p-3 bg-blue-50 rounded-lg">
          <span className="text-sm font-medium">
            {selectedItems.length} items selected
          </span>
          <Button
            size="sm"
            onClick={handleBulkThirdCount}
            disabled={triggerThirdCount.isPending}
          >
            Add to 3rd Count
          </Button>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setSelectedItems([])}
          >
            Clear Selection
          </Button>
        </div>
      )}
      
      {/* Table */}
      <div className="border rounded-lg">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-12">
                <Checkbox
                  checked={selectedItems.length === items.filter(i => i.is_flagged && !i.final_qty).length}
                  onCheckedChange={handleSelectAll}
                />
              </TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Product</TableHead>
              <TableHead>Location</TableHead>
              <TableHead className="text-center">Theoretical</TableHead>
              <TableHead className="text-center">Count 1</TableHead>
              <TableHead className="text-center">Count 2</TableHead>
              <TableHead className="text-center">Count 3</TableHead>
              <TableHead className="text-center">Final</TableHead>
              <TableHead className="text-center">Variance</TableHead>
              <TableHead>Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {items.map((item) => (
              <TableRow key={item.id} className={getRowClassName(item)}>
                <TableCell>
                  {item.is_flagged && !item.final_qty && (
                    <Checkbox
                      checked={selectedItems.includes(item.id)}
                      onCheckedChange={(checked) => handleSelect(item.id, checked as boolean)}
                    />
                  )}
                </TableCell>
                <TableCell>{getResolutionBadge(item)}</TableCell>
                <TableCell>
                  <div>
                    <div className="font-medium">{item.product.name}</div>
                    <div className="text-sm text-muted-foreground">{item.product.sku}</div>
                  </div>
                </TableCell>
                <TableCell>
                  <div className="text-sm">{item.location.code}</div>
                </TableCell>
                <TableCell className="text-center font-mono">
                  {item.theoretical_qty}
                </TableCell>
                <TableCell className="text-center">
                  <CountCell
                    count={item.count_1}
                    matchesTheoretical={item.count_1?.qty === item.theoretical_qty}
                  />
                </TableCell>
                <TableCell className="text-center">
                  <CountCell
                    count={item.count_2}
                    matchesTheoretical={item.count_2?.qty === item.theoretical_qty}
                  />
                </TableCell>
                <TableCell className="text-center">
                  <CountCell
                    count={item.count_3}
                    matchesTheoretical={item.count_3?.qty === item.theoretical_qty}
                  />
                </TableCell>
                <TableCell className="text-center font-mono font-medium">
                  {item.final_qty ?? '—'}
                </TableCell>
                <TableCell className="text-center">
                  {item.variance !== null && (
                    <span className={cn(
                      'font-mono',
                      item.variance > 0 && 'text-green-600',
                      item.variance < 0 && 'text-red-600'
                    )}>
                      {item.variance > 0 ? '+' : ''}{item.variance}
                    </span>
                  )}
                </TableCell>
                <TableCell>
                  {item.is_flagged && !item.final_qty && (
                    <div className="flex gap-1">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => triggerThirdCount.mutate({
                          countingId,
                          itemIds: [item.id],
                        })}
                      >
                        3rd Count
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setOverrideItem(item)}
                      >
                        Override
                      </Button>
                    </div>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      
      {/* Manual Override Dialog */}
      <ManualOverrideDialog
        open={!!overrideItem}
        item={overrideItem}
        onClose={() => setOverrideItem(null)}
        onSubmit={handleOverride}
        isLoading={manualOverride.isPending}
      />
    </div>
  );
}

function SummaryCard({
  label,
  value,
  variant = 'default',
}: {
  label: string;
  value: number;
  variant?: 'default' | 'success' | 'warning';
}) {
  return (
    <div className={cn(
      'p-4 rounded-lg border',
      variant === 'success' && 'bg-green-50 border-green-200',
      variant === 'warning' && 'bg-yellow-50 border-yellow-200'
    )}>
      <div className="text-2xl font-bold">{value}</div>
      <div className="text-sm text-muted-foreground">{label}</div>
    </div>
  );
}

function CountCell({
  count,
  matchesTheoretical,
}: {
  count: { qty: number; at: string; notes: string | null } | null;
  matchesTheoretical: boolean;
}) {
  if (!count) {
    return <span className="text-muted-foreground">—</span>;
  }
  
  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger>
          <span className={cn(
            'font-mono',
            matchesTheoretical && 'text-green-600'
          )}>
            {count.qty}
          </span>
        </TooltipTrigger>
        <TooltipContent>
          <div className="text-xs">
            <div>{format(new Date(count.at), 'MMM d, h:mm a')}</div>
            {count.notes && <div className="mt-1 italic">{count.notes}</div>}
          </div>
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
```

---

## PHASE 4: Pages

### Task 4.1: Create Dashboard Page

```typescript
// apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx

import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CountingCard } from '../components/CountingCard';
import { useCountingDashboard } from '../api/queries';
import { Plus, Activity, Clock, CheckCircle, AlertTriangle } from 'lucide-react';

export function CountingDashboardPage() {
  const { data, isLoading, error } = useCountingDashboard();
  
  if (isLoading) {
    return <div className="p-8 text-center">Loading dashboard...</div>;
  }
  
  if (error) {
    return <div className="p-8 text-center text-red-600">Error loading dashboard</div>;
  }
  
  if (!data) {
    return null;
  }
  
  const { summary, active_counts, pending_review } = data;
  
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Inventory Counting</h1>
          <p className="text-muted-foreground">
            Manage physical inventory counts and reconciliation
          </p>
        </div>
        <Button asChild>
          <Link to="/inventory/counting/create">
            <Plus className="w-4 h-4 mr-2" />
            New Count
          </Link>
        </Button>
      </div>
      
      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
        <SummaryCard
          icon={Activity}
          label="Active"
          value={summary.active}
          href="/inventory/counting/list?status=active"
          iconClassName="text-blue-600"
        />
        <SummaryCard
          icon={Clock}
          label="Pending Review"
          value={summary.pending_review}
          href="/inventory/counting/list?status=pending_review"
          iconClassName="text-amber-600"
        />
        <SummaryCard
          icon={CheckCircle}
          label="Completed This Month"
          value={summary.completed_this_month}
          href="/inventory/counting/list?status=finalized"
          iconClassName="text-green-600"
        />
        <SummaryCard
          icon={AlertTriangle}
          label="Overdue"
          value={summary.overdue}
          href="/inventory/counting/list?overdue=true"
          iconClassName="text-red-600"
          highlight={summary.overdue > 0}
        />
      </div>
      
      {/* Active Counts */}
      <section>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">Active Counting Operations</h2>
          <Button variant="ghost" size="sm" asChild>
            <Link to="/inventory/counting/list?status=active">View All</Link>
          </Button>
        </div>
        
        {active_counts.length === 0 ? (
          <Card>
            <CardContent className="py-8 text-center text-muted-foreground">
              No active counting operations
            </CardContent>
          </Card>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {active_counts.map((counting) => (
              <CountingCard key={counting.id} counting={counting} />
            ))}
          </div>
        )}
      </section>
      
      {/* Pending Review */}
      <section>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-semibold">Pending Review</h2>
          <Button variant="ghost" size="sm" asChild>
            <Link to="/inventory/counting/list?status=pending_review">View All</Link>
          </Button>
        </div>
        
        {pending_review.length === 0 ? (
          <Card>
            <CardContent className="py-8 text-center text-muted-foreground">
              No counts pending review
            </CardContent>
          </Card>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {pending_review.map((counting) => (
              <CountingCard key={counting.id} counting={counting} />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}

function SummaryCard({
  icon: Icon,
  label,
  value,
  href,
  iconClassName,
  highlight = false,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: number;
  href: string;
  iconClassName?: string;
  highlight?: boolean;
}) {
  return (
    <Card className={highlight ? 'border-red-300 bg-red-50' : ''}>
      <CardContent className="pt-6">
        <Link to={href} className="block">
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm text-muted-foreground">{label}</p>
              <p className="text-3xl font-bold">{value}</p>
            </div>
            <Icon className={`w-8 h-8 ${iconClassName}`} />
          </div>
        </Link>
      </CardContent>
    </Card>
  );
}
```

### Task 4.2: Create Review Page

```typescript
// apps/web/src/features/inventory-counting/pages/CountingReviewPage.tsx

import { useParams, Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { ReconciliationTable } from '../components/ReconciliationTable';
import { CountingStatusBadge } from '../components/CountingStatusBadge';
import {
  useCountingDetail,
  useFinalizeCounting,
  useReconciliation,
} from '../api/queries';
import { ArrowLeft, CheckCircle, FileText } from 'lucide-react';

export function CountingReviewPage() {
  const { id } = useParams<{ id: string }>();
  const countingId = parseInt(id!, 10);
  
  const { data: counting, isLoading } = useCountingDetail(countingId);
  const { data: reconciliation } = useReconciliation(countingId);
  const finalize = useFinalizeCounting();
  
  if (isLoading || !counting) {
    return <div className="p-8 text-center">Loading...</div>;
  }
  
  const canFinalize = 
    counting.status === 'pending_review' &&
    reconciliation?.summary.needs_attention === 0;
  
  const handleFinalize = () => {
    finalize.mutate(countingId);
  };
  
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" asChild>
            <Link to={`/inventory/counting/${countingId}`}>
              <ArrowLeft className="w-4 h-4" />
            </Link>
          </Button>
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-3">
              Review: Counting #{counting.uuid.slice(0, 8)}
              <CountingStatusBadge status={counting.status} />
            </h1>
            <p className="text-muted-foreground">
              Review count results and resolve discrepancies
            </p>
          </div>
        </div>
        
        <div className="flex gap-2">
          <Button variant="outline" asChild>
            <Link to={`/inventory/counting/${countingId}/report`}>
              <FileText className="w-4 h-4 mr-2" />
              View Report
            </Link>
          </Button>
          
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button disabled={!canFinalize || finalize.isPending}>
                <CheckCircle className="w-4 h-4 mr-2" />
                Finalize Counting
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Finalize Counting?</AlertDialogTitle>
                <AlertDialogDescription>
                  This will create stock adjustments based on the final quantities.
                  This action cannot be undone.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction onClick={handleFinalize}>
                  Finalize
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </div>
      
      {/* Warning if items need attention */}
      {reconciliation && reconciliation.summary.needs_attention > 0 && (
        <Card className="border-amber-300 bg-amber-50">
          <CardContent className="py-4">
            <p className="text-amber-800">
              <strong>{reconciliation.summary.needs_attention} items</strong> require
              resolution before finalizing. Use 3rd count or manual override.
            </p>
          </CardContent>
        </Card>
      )}
      
      {/* Reconciliation Table */}
      <ReconciliationTable countingId={countingId} />
    </div>
  );
}
```

---

## PHASE 5: Routes

### Task 5.1: Configure Routes

```typescript
// apps/web/src/features/inventory-counting/routes.tsx

import { RouteObject } from 'react-router-dom';
import { CountingLayout } from './layouts/CountingLayout';
import { CountingDashboardPage } from './pages/CountingDashboardPage';
import { CountingListPage } from './pages/CountingListPage';
import { CreateCountingPage } from './pages/CreateCountingPage';
import { CountingDetailPage } from './pages/CountingDetailPage';
import { CountingReviewPage } from './pages/CountingReviewPage';
import { DiscrepancyReportPage } from './pages/DiscrepancyReportPage';

export const inventoryCountingRoutes: RouteObject[] = [
  {
    path: '/inventory/counting',
    element: <CountingLayout />,
    children: [
      { index: true, element: <CountingDashboardPage /> },
      { path: 'list', element: <CountingListPage /> },
      { path: 'create', element: <CreateCountingPage /> },
      { path: ':id', element: <CountingDetailPage /> },
      { path: ':id/review', element: <CountingReviewPage /> },
      { path: ':id/report', element: <DiscrepancyReportPage /> },
    ],
  },
];
```

---

## Verification Commands

```bash
cd apps/web

# Type checking
pnpm tsc --noEmit

# Linting
pnpm lint

# Build test
pnpm build

# Run dev server
pnpm dev
```

---

## FINAL CHECKLIST

- [x] All TypeScript types are properly defined in shared package
- [x] API client matches backend endpoints
- [x] React Query hooks have proper cache invalidation
- [x] All components render correctly
- [x] Dashboard shows live data with auto-refresh
- [x] Create form wizard works end-to-end
- [x] Reconciliation table shows all count data (admin view)
- [x] Manual override dialog works
- [x] Finalization flow works
- [x] No TypeScript errors
- [x] Routes are registered correctly
- [x] Sidebar navigation menu item added for easy access
