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
 * This is BLIND COUNTING - counters must not know expected values.
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
    const response = await api.get(
      `/inventory/countings/${countingId}/counter-view`
    );
    return response.data.data;
  },

  // Get items to count (BLIND - no theoretical qty!)
  getItems: async (
    countingId: number,
    uncountedOnly = false
  ): Promise<CountingItem[]> => {
    const response = await api.get(
      `/inventory/countings/${countingId}/items/to-count`,
      {
        params: { uncounted_only: uncountedOnly },
      }
    );
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
    const response = await api.get(
      `/inventory/countings/${countingId}/lookup`,
      {
        params: { barcode },
      }
    );
    return response.data;
  },

  // ==========================================
  // Draft Counting Operations (Mobile-Initiated)
  // ==========================================

  // Create draft counting
  createDraft: async (data: {
    title?: string;
    scopeType: string;
    instructions?: string;
    createdOnMobile?: boolean;
  }): Promise<{ id: number; uuid: string }> => {
    const response = await api.post('/inventory/countings/drafts', {
      title: data.title,
      scope_type: data.scopeType,
      instructions: data.instructions,
      created_on_mobile: data.createdOnMobile ?? true,
      scope_filters: { product_ids: [] }, // Start empty, add incrementally
    });
    return response.data.data;
  },

  // Get my draft countings
  getMyDrafts: async (): Promise<Array<{
    id: number;
    uuid: string;
    title: string | null;
    status: string;
    scopeType: string;
    productCount: number;
    createdAt: string;
    lastModifiedAt: string | null;
  }>> => {
    const response = await api.get('/inventory/countings/my-drafts');
    return response.data.data.map((draft: any) => ({
      id: draft.id,
      uuid: draft.uuid,
      title: draft.title,
      status: draft.status,
      scopeType: draft.scope_type,
      productCount: draft.product_count,
      createdAt: draft.created_at,
      lastModifiedAt: draft.last_modified_at,
    }));
  },

  // Add product to draft (by barcode or product_id)
  addProductToDraft: async (
    countingId: number,
    data: { barcode?: string; productId?: string; locationId?: string }
  ): Promise<{
    productId: string;
    product: {
      id: string;
      name: string;
      sku: string;
      barcode: string | null;
    };
    addedAt: string;
  }> => {
    const response = await api.post(
      `/inventory/countings/${countingId}/add-product`,
      {
        barcode: data.barcode,
        product_id: data.productId,
        location_id: data.locationId,
      }
    );
    return {
      productId: response.data.data.product_id,
      product: response.data.data.product,
      addedAt: response.data.data.added_at,
    };
  },

  // Remove product from draft
  removeProductFromDraft: async (
    countingId: number,
    productId: string
  ): Promise<void> => {
    await api.delete(`/inventory/countings/${countingId}/products/${productId}`);
  },

  // Update draft counting
  updateDraft: async (
    countingId: number,
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
    }
  ): Promise<void> => {
    await api.patch(`/inventory/countings/${countingId}/draft`, {
      title: data.title,
      instructions: data.instructions,
      count_1_user_id: data.count1UserId,
      count_2_user_id: data.count2UserId,
      count_3_user_id: data.count3UserId,
      requires_count_2: data.requiresCount2,
      requires_count_3: data.requiresCount3,
      allow_unexpected_items: data.allowUnexpectedItems,
      execution_mode: data.executionMode,
    });
  },

  // Activate draft (transition to active counting)
  activateDraft: async (
    countingId: number,
    activateImmediately = true
  ): Promise<void> => {
    await api.post(`/inventory/countings/${countingId}/activate-draft`, {
      activate_immediately: activateImmediately,
    });
  },

  // ==========================================
  // Batch Operations (Offline Sync)
  // ==========================================

  // Batch create drafts
  batchCreateDrafts: async (drafts: Array<{
    localId: string;
    title?: string;
    scopeType: string;
    instructions?: string;
    executionMode?: 'parallel' | 'sequential';
    requiresCount2?: boolean;
    requiresCount3?: boolean;
    allowUnexpectedItems?: boolean;
    scopeFilters?: Record<string, any>;
    count1UserId?: string;
    count2UserId?: string;
    count3UserId?: string;
    scheduledStart?: string;
    scheduledEnd?: string;
  }>): Promise<{
    success: Array<{ localId: string; serverId: string; status: 'success' }>;
    errors: Array<{ localId: string; error: string }>;
  }> => {
    const response = await api.post('/inventory/countings/drafts/batch', {
      drafts: drafts.map(draft => ({
        localId: draft.localId,
        title: draft.title,
        scopeType: draft.scopeType,
        instructions: draft.instructions,
        executionMode: draft.executionMode,
        requiresCount2: draft.requiresCount2,
        requiresCount3: draft.requiresCount3,
        allowUnexpectedItems: draft.allowUnexpectedItems,
        scopeFilters: draft.scopeFilters,
        count1UserId: draft.count1UserId,
        count2UserId: draft.count2UserId,
        count3UserId: draft.count3UserId,
        scheduledStart: draft.scheduledStart,
        scheduledEnd: draft.scheduledEnd,
      })),
    });
    return response.data.data;
  },

  // Batch add products to a draft
  batchAddProducts: async (
    countingId: string,
    products: Array<{ barcode?: string; productId?: string }>
  ): Promise<{
    success: Array<{ productId: string; status: 'success' }>;
    errors: Array<{ data: any; error: string }>;
    total_products: number;
  }> => {
    const response = await api.post(
      `/inventory/countings/${countingId}/add-products/batch`,
      {
        products: products.map(p => ({
          barcode: p.barcode,
          productId: p.productId,
        })),
      }
    );
    return response.data.data;
  },

  // Batch update drafts
  batchUpdateDrafts: async (updates: Array<{
    id: string;
    localId?: string;
    data: {
      title?: string;
      instructions?: string;
      executionMode?: 'parallel' | 'sequential';
      requiresCount2?: boolean;
      requiresCount3?: boolean;
      allowUnexpectedItems?: boolean;
      count1UserId?: string;
      count2UserId?: string;
      count3UserId?: string;
      scheduledStart?: string;
      scheduledEnd?: string;
    };
  }>): Promise<{
    success: Array<{ id: string; localId?: string; status: 'success' }>;
    errors: Array<{ id: string; localId?: string; error: string }>;
  }> => {
    const response = await api.patch('/inventory/countings/drafts/batch', {
      updates: updates.map(update => ({
        id: update.id,
        localId: update.localId,
        data: {
          title: update.data.title,
          instructions: update.data.instructions,
          executionMode: update.data.executionMode,
          requiresCount2: update.data.requiresCount2,
          requiresCount3: update.data.requiresCount3,
          allowUnexpectedItems: update.data.allowUnexpectedItems,
          count1UserId: update.data.count1UserId,
          count2UserId: update.data.count2UserId,
          count3UserId: update.data.count3UserId,
          scheduledStart: update.data.scheduledStart,
          scheduledEnd: update.data.scheduledEnd,
        },
      })),
    });
    return response.data.data;
  },
};
