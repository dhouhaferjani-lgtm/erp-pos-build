/**
 * Draft Counting Store Tests
 *
 * Critical regression tests for offline-first draft counting functionality.
 * These tests ensure drafts can be created, updated, and synced without network.
 */

import { renderHook, act } from '@testing-library/react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useDraftCountingStore } from '../store/draftCountingStore';

// Mock AsyncStorage
jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
}));

describe('Draft Counting Store - Offline Functionality', () => {
  beforeEach(() => {
    // Clear store state before each test
    const { result } = renderHook(() => useDraftCountingStore());
    act(() => {
      result.current.clearAll();
    });
    jest.clearAllMocks();
  });

  describe('Creating Drafts Offline', () => {
    it('should create a draft with a local UUID without network', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test Draft',
          scopeType: 'product',
          instructions: 'Test instructions',
        });
      });

      expect(localId!).toBeDefined();
      expect(localId!).toMatch(/^draft_/);

      const draft = result.current.drafts[0];
      expect(draft).toBeDefined();
      expect(draft.localId).toBe(localId!);
      expect(draft.id).toBeNull(); // Server ID should be null
      expect(draft.status).toBe('draft');
      expect(draft.title).toBe('Test Draft');
    });

    it('should create draft with default settings if not provided', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'full_inventory',
        });
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.settings).toEqual({
        requiresCount2: false,
        requiresCount3: false,
        allowUnexpectedItems: true,
        executionMode: 'sequential',
      });
    });

    it('should set created draft as active draft', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      expect(result.current.activeDraftId).toBe(localId!);
    });
  });

  describe('Adding Products Offline', () => {
    it('should add products to draft without network', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.addProduct(localId!, 'product-uuid-1');
        result.current.addProduct(localId!, 'product-uuid-2');
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.productIds).toEqual(['product-uuid-1', 'product-uuid-2']);
      expect(draft?.status).toBe('draft'); // Should mark as needing sync
    });

    it('should update lastModifiedAt when adding products', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      const originalTime = result.current.drafts[0].lastModifiedAt;

      // Wait a bit to ensure timestamp changes
      act(() => {
        jest.advanceTimersByTime(100);
        result.current.addProduct(localId!, 'product-1');
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.lastModifiedAt).not.toBe(originalTime);
    });

    it('should remove products from draft', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
        result.current.addProduct(localId!, 'product-1');
        result.current.addProduct(localId!, 'product-2');
      });

      act(() => {
        result.current.removeProduct(localId!, 'product-1');
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.productIds).toEqual(['product-2']);
    });

    it('should check if product exists in draft', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
        result.current.addProduct(localId!, 'product-1');
      });

      expect(result.current.hasProduct(localId!, 'product-1')).toBe(true);
      expect(result.current.hasProduct(localId!, 'product-2')).toBe(false);
    });
  });

  describe('Updating Drafts Offline', () => {
    it('should update draft title', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Original',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.updateTitle(localId!, 'Updated Title');
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.title).toBe('Updated Title');
      expect(draft?.status).toBe('draft');
    });

    it('should update draft instructions', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.updateInstructions(localId!, 'New instructions');
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.instructions).toBe('New instructions');
    });

    it('should update draft settings', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.updateSettings(localId!, {
          requiresCount2: true,
          executionMode: 'parallel',
        });
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.settings.requiresCount2).toBe(true);
      expect(draft?.settings.executionMode).toBe('parallel');
      expect(draft?.settings.requiresCount3).toBe(false); // Should preserve other settings
    });

    it('should assign counters', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.assignCounter(localId!, 1, 'user-123');
        result.current.assignCounter(localId!, 2, 'user-456');
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.assignedCounters.count1UserId).toBe('user-123');
      expect(draft?.assignedCounters.count2UserId).toBe('user-456');
    });
  });

  describe('Sync Status Management', () => {
    it('should mark draft as syncing', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.markSyncing(localId!);
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.status).toBe('syncing');
      expect(draft?.syncError).toBeUndefined();
    });

    it('should mark draft as synced with server ID', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.markSynced(localId!, 42);
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.status).toBe('synced');
      expect(draft?.id).toBe(42);
      expect(draft?.syncError).toBeUndefined();
    });

    it('should mark draft with sync error', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      act(() => {
        result.current.markSyncError(localId!, 'Network timeout');
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.status).toBe('sync_error');
      expect(draft?.syncError).toBe('Network timeout');
    });

    it('should retry sync by resetting status to draft', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
        result.current.markSyncError(localId!, 'Error');
      });

      act(() => {
        result.current.retrySync(localId!);
      });

      const draft = result.current.drafts.find(d => d.localId === localId!);
      expect(draft?.status).toBe('draft');
      expect(draft?.syncError).toBeUndefined();
    });
  });

  describe('Draft Lifecycle', () => {
    it('should delete draft by localId', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      expect(result.current.drafts.length).toBe(1);

      act(() => {
        result.current.deleteDraft(localId!);
      });

      expect(result.current.drafts.length).toBe(0);
    });

    it('should clear active draft when deleting active draft', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Test',
          scopeType: 'product',
        });
      });

      expect(result.current.activeDraftId).toBe(localId!);

      act(() => {
        result.current.deleteDraft(localId!);
      });

      expect(result.current.activeDraftId).toBeNull();
    });

    it('should clear all drafts', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      act(() => {
        result.current.createDraft({ title: 'Draft 1', scopeType: 'product' });
        result.current.createDraft({ title: 'Draft 2', scopeType: 'location' });
        result.current.createDraft({ title: 'Draft 3', scopeType: 'warehouse' });
      });

      expect(result.current.drafts.length).toBe(3);

      act(() => {
        result.current.clearAll();
      });

      expect(result.current.drafts.length).toBe(0);
      expect(result.current.activeDraftId).toBeNull();
    });
  });

  describe('Active Draft Management', () => {
    it('should get active draft', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Active Draft',
          scopeType: 'product',
        });
      });

      const activeDraft = result.current.getActiveDraft();
      expect(activeDraft).toBeDefined();
      expect(activeDraft?.localId).toBe(localId!);
    });

    it('should set active draft', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId1: string, localId2: string;
      act(() => {
        localId1 = result.current.createDraft({ title: 'Draft 1', scopeType: 'product' });
        localId2 = result.current.createDraft({ title: 'Draft 2', scopeType: 'location' });
      });

      expect(result.current.activeDraftId).toBe(localId2!);

      act(() => {
        result.current.setActiveDraft(localId1!);
      });

      expect(result.current.activeDraftId).toBe(localId1!);
    });

    it('should get draft by localId', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      let localId: string;
      act(() => {
        localId = result.current.createDraft({
          title: 'Specific Draft',
          scopeType: 'category',
        });
      });

      const draft = result.current.getDraft(localId!);
      expect(draft).toBeDefined();
      expect(draft?.title).toBe('Specific Draft');
    });
  });

  describe('Persistence (AsyncStorage)', () => {
    it('should persist drafts to AsyncStorage', () => {
      const { result } = renderHook(() => useDraftCountingStore());

      act(() => {
        result.current.createDraft({
          title: 'Persistent Draft',
          scopeType: 'product',
        });
      });

      // Zustand persist middleware should call setItem
      expect(AsyncStorage.setItem).toHaveBeenCalled();
    });
  });
});
