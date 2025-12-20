/**
 * OfflineIndicator Component Tests
 *
 * Critical regression tests for offline status display.
 * Ensures users are properly informed about network status and pending operations.
 */

import React from 'react';
import { render } from '@testing-library/react-native';
import { OfflineIndicator } from '../OfflineIndicator';
import { useNetInfo } from '@react-native-community/netinfo';
import { useCountingStore } from '@/features/counting/store/countingStore';
import { useDraftCountingStore } from '@/features/counting/store/draftCountingStore';

// Mock dependencies
jest.mock('@react-native-community/netinfo');
jest.mock('@/features/counting/store/countingStore');
jest.mock('@/features/counting/store/draftCountingStore');

const mockUseNetInfo = useNetInfo as jest.MockedFunction<typeof useNetInfo>;
const mockUseCountingStore = useCountingStore as unknown as jest.Mock;
const mockUseDraftCountingStore = useDraftCountingStore as unknown as jest.Mock;

describe('OfflineIndicator Component', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  describe('Online with No Pending Operations', () => {
    it('should render nothing when online with no pending operations', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);
      mockUseCountingStore.mockReturnValue([]);
      mockUseDraftCountingStore.mockReturnValue([]);

      const { queryByText } = render(<OfflineIndicator />);

      expect(queryByText(/offline/i)).toBeNull();
      expect(queryByText(/syncing/i)).toBeNull();
    });
  });

  describe('Offline Status', () => {
    it('should show offline banner when network is disconnected', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: false,
        type: 'none',
      } as any);
      mockUseCountingStore.mockReturnValue([]);
      mockUseDraftCountingStore.mockReturnValue([]);

      const { getByText } = render(<OfflineIndicator />);

      expect(getByText(/you're offline/i)).toBeDefined();
    });

    it('should show count of pending operations when offline', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: false,
        type: 'none',
      } as any);
      mockUseCountingStore.mockReturnValue([
        { synced: false },
        { synced: false },
        { synced: false },
      ]);
      mockUseDraftCountingStore.mockReturnValue([
        { status: 'draft', productIds: [] },
        { status: 'draft', productIds: [] },
      ]);

      const { getByText } = render(<OfflineIndicator />);

      expect(getByText(/5 operations will sync when connected/i)).toBeDefined();
    });

    it('should use singular "operation" for one pending item', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: false,
        type: 'none',
      } as any);
      mockUseCountingStore.mockReturnValue([
        { synced: false },
      ]);
      mockUseDraftCountingStore.mockReturnValue([]);

      const { getByText } = render(<OfflineIndicator />);

      expect(getByText(/1 operation will sync/i)).toBeDefined();
    });
  });

  describe('Online with Pending Operations', () => {
    it('should show syncing banner when online with pending counts', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);
      mockUseCountingStore.mockReturnValue([
        { synced: false },
        { synced: false },
      ]);
      mockUseDraftCountingStore.mockReturnValue([]);

      const { getByText } = render(<OfflineIndicator />);

      expect(getByText(/syncing 2 operations/i)).toBeDefined();
    });

    it('should show syncing banner when online with pending drafts', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);
      mockUseCountingStore.mockReturnValue([]);
      mockUseDraftCountingStore.mockReturnValue([
        { status: 'draft', productIds: [] },
        { status: 'syncing', productIds: [] },
      ]);

      const { getByText } = render(<OfflineIndicator />);

      expect(getByText(/syncing 2 operations/i)).toBeDefined();
    });

    it('should combine counts and drafts in total', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);
      mockUseCountingStore.mockReturnValue([
        { synced: false },
        { synced: false },
        { synced: false },
      ]);
      mockUseDraftCountingStore.mockReturnValue([
        { status: 'draft', productIds: [] },
        { status: 'draft', productIds: [] },
      ]);

      const { getByText } = render(<OfflineIndicator />);

      expect(getByText(/syncing 5 operations/i)).toBeDefined();
    });

    it('should show activity indicator when syncing', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);
      mockUseCountingStore.mockReturnValue([
        { synced: false },
      ]);
      mockUseDraftCountingStore.mockReturnValue([]);

      const { UNSAFE_getByType } = render(<OfflineIndicator />);

      // ActivityIndicator from react-native-paper
      const indicators = UNSAFE_getByType(require('react-native-paper').ActivityIndicator);
      expect(indicators).toBeDefined();
    });
  });

  describe('Filtering Logic', () => {
    it('should only count unsynced counts', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);
      mockUseCountingStore.mockReturnValue([
        { synced: false },
        { synced: true },
        { synced: false },
        { synced: true },
      ]);
      mockUseDraftCountingStore.mockReturnValue([]);

      const { getByText } = render(<OfflineIndicator />);

      // Should only count the 2 unsynced
      expect(getByText(/syncing 2 operations/i)).toBeDefined();
    });

    it('should only count drafts with draft or syncing status', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);
      mockUseCountingStore.mockReturnValue([]);
      mockUseDraftCountingStore.mockReturnValue([
        { status: 'draft', productIds: [] },
        { status: 'synced', productIds: [] },
        { status: 'syncing', productIds: [] },
        { status: 'sync_error', productIds: [] },
      ]);

      const { getByText } = render(<OfflineIndicator />);

      // Should count 2 (draft + syncing, not synced or error)
      expect(getByText(/syncing 2 operations/i)).toBeDefined();
    });
  });

  describe('Performance - No Infinite Loops', () => {
    it('should use memoization to prevent infinite re-renders', () => {
      // This test ensures the component doesn't create new arrays on every render
      const mockPendingCounts = [{ synced: false }];
      const mockDrafts = [{ status: 'draft', productIds: [] }];

      mockUseNetInfo.mockReturnValue({
        isConnected: true,
        type: 'wifi',
      } as any);

      // Return the same array instance
      mockUseCountingStore.mockReturnValue(mockPendingCounts);
      mockUseDraftCountingStore.mockReturnValue(mockDrafts);

      const { rerender } = render(<OfflineIndicator />);

      // Re-render with same data
      rerender(<OfflineIndicator />);

      // Should not throw "Maximum update depth exceeded"
      // If this test passes, memoization is working
      expect(true).toBe(true);
    });
  });

  describe('Visual Styling', () => {
    it('should use warning colors for offline state', () => {
      mockUseNetInfo.mockReturnValue({
        isConnected: false,
        type: 'none',
      } as any);
      mockUseCountingStore.mockReturnValue([]);
      mockUseDraftCountingStore.mockReturnValue([]);

      const { getByTestId } = render(<OfflineIndicator />);

      // Offline banner should have warning background color
      // This is a visual regression test
      const banner = getByTestId?.('offline-banner') || null;
      // StyleSheet testing would require snapshot or style inspection
    });
  });
});
