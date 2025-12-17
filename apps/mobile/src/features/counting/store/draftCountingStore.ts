import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * Draft counting operation (mobile-initiated).
 *
 * Allows incremental building of product list over multiple days.
 */
export interface DraftCounting {
  // Server ID (null if not yet synced)
  id: number | null;

  // Local UUID for offline identification
  localId: string;

  // Basic info
  title: string;
  instructions: string;
  scopeType: 'product' | 'product_location' | 'location' | 'category' | 'full_inventory' | 'warehouse';

  // Product list (built incrementally)
  productIds: string[];

  // Counter assignments
  assignedCounters: {
    count1UserId?: string;
    count2UserId?: string;
    count3UserId?: string;
  };

  // Settings
  settings: {
    requiresCount2: boolean;
    requiresCount3: boolean;
    allowUnexpectedItems: boolean;
    executionMode: 'parallel' | 'sequential';
  };

  // Sync status
  status: 'draft' | 'syncing' | 'synced' | 'sync_error';
  syncError?: string;

  // Timestamps
  createdAt: string;
  lastModifiedAt: string;
}

interface DraftCountingState {
  // State
  drafts: DraftCounting[];
  activeDraftId: string | null; // localId of currently editing draft

  // Actions
  createDraft: (draft: {
    title: string;
    scopeType: DraftCounting['scopeType'];
    instructions?: string;
    settings?: Partial<DraftCounting['settings']>;
    assignedCounters?: Partial<DraftCounting['assignedCounters']>;
  }) => string;
  setActiveDraft: (localId: string | null) => void;
  getActiveDraft: () => DraftCounting | null;
  getDraft: (localId: string) => DraftCounting | undefined;

  // Product management
  addProduct: (localId: string, productId: string) => void;
  removeProduct: (localId: string, productId: string) => void;
  hasProduct: (localId: string, productId: string) => boolean;

  // Draft updates
  updateDraft: (localId: string, updates: Partial<Omit<DraftCounting, 'id' | 'localId' | 'status'>>) => void;
  updateTitle: (localId: string, title: string) => void;
  updateInstructions: (localId: string, instructions: string) => void;
  updateSettings: (localId: string, settings: Partial<DraftCounting['settings']>) => void;
  assignCounter: (localId: string, countNumber: 1 | 2 | 3, userId: string | undefined) => void;

  // Sync management
  markSyncing: (localId: string) => void;
  markSynced: (localId: string, serverId: number) => void;
  markSyncError: (localId: string, error: string) => void;
  retrySync: (localId: string) => void;

  // Draft lifecycle
  deleteDraft: (localId: string) => void;
  clearAll: () => void;
}

export const useDraftCountingStore = create<DraftCountingState>()(
  persist(
    (set, get) => ({
      drafts: [],
      activeDraftId: null,

      createDraft: (draft) => {
        const localId = `draft_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
        const now = new Date().toISOString();

        const newDraft: DraftCounting = {
          id: null,
          localId,
          title: draft.title,
          instructions: draft.instructions || '',
          scopeType: draft.scopeType,
          productIds: [],
          assignedCounters: draft.assignedCounters || {},
          settings: {
            requiresCount2: draft.settings?.requiresCount2 ?? false,
            requiresCount3: draft.settings?.requiresCount3 ?? false,
            allowUnexpectedItems: draft.settings?.allowUnexpectedItems ?? true,
            executionMode: draft.settings?.executionMode ?? 'sequential',
          },
          status: 'draft',
          createdAt: now,
          lastModifiedAt: now,
        };

        set((state) => ({
          drafts: [...state.drafts, newDraft],
          activeDraftId: localId,
        }));

        return localId;
      },

      setActiveDraft: (localId) => {
        set({ activeDraftId: localId });
      },

      getActiveDraft: () => {
        const state = get();
        if (!state.activeDraftId) return null;
        return state.drafts.find((d) => d.localId === state.activeDraftId) || null;
      },

      getDraft: (localId) => {
        return get().drafts.find((d) => d.localId === localId);
      },

      addProduct: (localId, productId) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId
              ? {
                  ...draft,
                  productIds: [...draft.productIds, productId],
                  lastModifiedAt: new Date().toISOString(),
                  status: 'draft' as const, // Mark as needing sync
                }
              : draft
          ),
        }));
      },

      removeProduct: (localId, productId) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId
              ? {
                  ...draft,
                  productIds: draft.productIds.filter((id) => id !== productId),
                  lastModifiedAt: new Date().toISOString(),
                  status: 'draft' as const,
                }
              : draft
          ),
        }));
      },

      hasProduct: (localId, productId) => {
        const draft = get().getDraft(localId);
        return draft?.productIds.includes(productId) ?? false;
      },

      updateDraft: (localId, updates) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId
              ? {
                  ...draft,
                  ...updates,
                  lastModifiedAt: new Date().toISOString(),
                  status: 'draft' as const,
                }
              : draft
          ),
        }));
      },

      updateTitle: (localId, title) => {
        get().updateDraft(localId, { title });
      },

      updateInstructions: (localId, instructions) => {
        get().updateDraft(localId, { instructions });
      },

      updateSettings: (localId, settings) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId
              ? {
                  ...draft,
                  settings: { ...draft.settings, ...settings },
                  lastModifiedAt: new Date().toISOString(),
                  status: 'draft' as const,
                }
              : draft
          ),
        }));
      },

      assignCounter: (localId, countNumber, userId) => {
        set((state) => ({
          drafts: state.drafts.map((draft) => {
            if (draft.localId !== localId) return draft;

            const key = `count${countNumber}UserId` as keyof DraftCounting['assignedCounters'];
            return {
              ...draft,
              assignedCounters: {
                ...draft.assignedCounters,
                [key]: userId,
              },
              lastModifiedAt: new Date().toISOString(),
              status: 'draft' as const,
            };
          }),
        }));
      },

      markSyncing: (localId) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId ? { ...draft, status: 'syncing' as const, syncError: undefined } : draft
          ),
        }));
      },

      markSynced: (localId, serverId) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId ? { ...draft, id: serverId, status: 'synced' as const, syncError: undefined } : draft
          ),
        }));
      },

      markSyncError: (localId, error) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId ? { ...draft, status: 'sync_error' as const, syncError: error } : draft
          ),
        }));
      },

      retrySync: (localId) => {
        set((state) => ({
          drafts: state.drafts.map((draft) =>
            draft.localId === localId ? { ...draft, status: 'draft' as const, syncError: undefined } : draft
          ),
        }));
      },

      deleteDraft: (localId) => {
        set((state) => ({
          drafts: state.drafts.filter((d) => d.localId !== localId),
          activeDraftId: state.activeDraftId === localId ? null : state.activeDraftId,
        }));
      },

      clearAll: () => {
        set({ drafts: [], activeDraftId: null });
      },
    }),
    {
      name: 'draft-counting-storage',
      storage: createJSONStorage(() => AsyncStorage),
    }
  )
);
