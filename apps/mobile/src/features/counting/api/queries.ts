import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { countingApi } from './countingApi';

export const countingKeys = {
  all: ['counting'] as const,
  tasks: () => [...countingKeys.all, 'tasks'] as const,
  session: (id: number) => [...countingKeys.all, 'session', id] as const,
  items: (id: number) => [...countingKeys.all, 'items', id] as const,
  drafts: () => [...countingKeys.all, 'drafts'] as const,
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
  const { data: session, isLoading } = useCountingSession(countingId);

  return {
    data: session?.items.find((item) => item.id === itemId),
    isLoading,
  };
}

// ==========================================
// Draft Counting Hooks (Mobile-Initiated)
// ==========================================

/**
 * Fetch user's draft counting operations
 */
export function useDraftCountings() {
  return useQuery({
    queryKey: countingKeys.drafts(),
    queryFn: countingApi.getMyDrafts,
  });
}

/**
 * Create a new draft counting operation
 */
export function useCreateDraftMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: {
      title?: string;
      scopeType: string;
      instructions?: string;
      createdOnMobile?: boolean;
    }) => countingApi.createDraft(data),
    onSuccess: () => {
      // Invalidate drafts list to refetch
      queryClient.invalidateQueries({ queryKey: countingKeys.drafts() });
    },
  });
}

/**
 * Add product to draft (via barcode scan or manual selection)
 */
export function useAddProductToDraftMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      countingId,
      barcode,
      productId,
      locationId,
    }: {
      countingId: number;
      barcode?: string;
      productId?: string;
      locationId?: string;
    }) => countingApi.addProductToDraft(countingId, { barcode, productId, locationId }),
    onSuccess: () => {
      // Invalidate drafts to update product count
      queryClient.invalidateQueries({ queryKey: countingKeys.drafts() });
    },
  });
}

/**
 * Remove product from draft
 */
export function useRemoveProductFromDraftMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      countingId,
      productId,
    }: {
      countingId: number;
      productId: string;
    }) => countingApi.removeProductFromDraft(countingId, productId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: countingKeys.drafts() });
    },
  });
}

/**
 * Update draft counting fields (title, instructions, assignments, settings)
 */
export function useUpdateDraftMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      countingId,
      data,
    }: {
      countingId: number;
      data: {
        title?: string;
        instructions?: string;
        count1UserId?: string;
        count2UserId?: string;
        count3UserId?: string;
        requiresCount2?: boolean;
        requiresCount3?: boolean;
        allowUnexpectedItems?: boolean;
        executionMode?: 'parallel' | 'sequential';
      };
    }) => countingApi.updateDraft(countingId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: countingKeys.drafts() });
    },
  });
}

/**
 * Activate draft counting (transition to active counting)
 */
export function useActivateDraftMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      countingId,
      activateImmediately = true,
    }: {
      countingId: number;
      activateImmediately?: boolean;
    }) => countingApi.activateDraft(countingId, activateImmediately),
    onSuccess: () => {
      // Invalidate both drafts and tasks
      queryClient.invalidateQueries({ queryKey: countingKeys.drafts() });
      queryClient.invalidateQueries({ queryKey: countingKeys.tasks() });
    },
  });
}
