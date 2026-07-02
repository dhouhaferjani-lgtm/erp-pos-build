import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useCompanyStore, type Company } from '../companyStore'

/**
 * Regression tests for company selection and persistence
 *
 * Critical functionality:
 * 1. Company selection persists across page refreshes
 * 2. Currency display matches selected company
 * 3. Manual localStorage persistence avoids Zustand middleware conflicts
 *
 * Bug History:
 * - Initial issue: Currency displayed USD regardless of selected company
 * - Second issue: Hard refresh reverted to first company instead of maintaining selection
 * - Root cause: Race condition between Zustand persist middleware and CompanyProvider
 * - Solution: Separate localStorage key 'autoerp-company-selection' for manual persistence
 */

// Mock companies for testing
const mockCompanies: Company[] = [
  {
    id: 'company-france-id',
    name: 'French Company',
    legalName: 'French Company SARL',
    taxId: 'FR12345678901',
    countryCode: 'FR',
    currency: 'EUR',
    locale: 'fr',
    timezone: 'Europe/Paris',
  },
  {
    id: 'company-tunisia-id',
    name: 'Tunisia Company',
    legalName: 'Tunisia Company SARL',
    taxId: 'TN987654321',
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
  },
  {
    id: 'company-usa-id',
    name: 'US Company',
    legalName: 'US Company LLC',
    taxId: 'US123456789',
    countryCode: 'US',
    currency: 'USD',
    locale: 'en',
    timezone: 'America/New_York',
  },
]

describe('companyStore', () => {
  // Clear all mocks and localStorage before each test
  beforeEach(() => {
    vi.clearAllMocks()
    localStorage.clear()
    // Reset store to initial state
    useCompanyStore.getState().reset()
  })

  afterEach(() => {
    localStorage.clear()
  })

  describe('Initial State', () => {
    it('starts with null currentCompanyId', () => {
      const { result } = renderHook(() => useCompanyStore())

      expect(result.current.currentCompanyId).toBeNull()
      expect(result.current.companies).toEqual([])
      expect(result.current.isLoading).toBe(true)
    })

    it('getCurrentCompany returns null when no company selected', () => {
      const { result } = renderHook(() => useCompanyStore())

      expect(result.current.getCurrentCompany()).toBeNull()
    })
  })

  describe('setCompanies', () => {
    it('sets companies and updates loading state', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      expect(result.current.companies).toEqual(mockCompanies)
      expect(result.current.isLoading).toBe(false)
    })

    it('preserves currentCompanyId if company still exists', () => {
      const { result } = renderHook(() => useCompanyStore())

      // Set initial company
      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-tunisia-id')
      })

      expect(result.current.currentCompanyId).toBe('company-tunisia-id')

      // Simulate refetch of companies (e.g., after refresh)
      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Should preserve Tunisia selection
      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
    })

    it('deterministically re-selects when previously selected company no longer exists', () => {
      const { result } = renderHook(() => useCompanyStore())

      // Set initial companies and select one
      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-tunisia-id')
      })

      expect(result.current.currentCompanyId).toBe('company-tunisia-id')

      // Simulate company list change (Tunisia removed)
      const updatedCompanies = mockCompanies.filter((c) => c.id !== 'company-tunisia-id')

      act(() => {
        result.current.setCompanies(updatedCompanies)
      })

      // Should NOT silently clear then let the provider pick an arbitrary company.
      // The centralized rule resolves deterministically to the first remaining
      // company (no is_primary flag / valid persisted value here).
      expect(result.current.currentCompanyId).toBe('company-france-id')
    })

    it('does not clear a valid selection on an empty (transient) fetch', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-tunisia-id')
      })

      // A transient/partial fetch returns an empty list — must NOT switch scope.
      act(() => {
        result.current.setCompanies([])
      })

      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
    })

    it('restores currentCompanyId from localStorage on first load', () => {
      // Simulate previous session - user selected Tunisia
      localStorage.setItem('autoerp-company-selection', 'company-tunisia-id')

      const { result } = renderHook(() => useCompanyStore())

      // Simulate CompanyProvider fetching companies
      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Should restore Tunisia from localStorage
      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
    })

    it('falls back to a deterministic default when localStorage holds an invalid id', () => {
      // Simulate corrupted or invalid localStorage
      localStorage.setItem('autoerp-company-selection', 'invalid-company-id')

      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Invalid persisted id is ignored; deterministic rule picks the first company.
      expect(result.current.currentCompanyId).toBe('company-france-id')
    })

    it('handles localStorage read errors gracefully', () => {
      // Mock localStorage.getItem to throw error
      const originalGetItem = localStorage.getItem
      vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
        throw new Error('localStorage quota exceeded')
      })

      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Should handle the error, ignore the unreadable persisted value, and still
      // resolve deterministically (first company) rather than crashing.
      expect(result.current.currentCompanyId).toBe('company-france-id')
      expect(result.current.companies).toEqual(mockCompanies)

      // Restore original
      vi.spyOn(Storage.prototype, 'getItem').mockImplementation(originalGetItem)
    })

    it('prefers the is_primary company on first load (no persisted selection)', () => {
      const withPrimary: Company[] = [
        { ...mockCompanies[0] }, // France, first by name, not primary
        { ...mockCompanies[1], isPrimary: true }, // Tunisia is primary
        { ...mockCompanies[2] },
      ]

      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(withPrimary)
      })

      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
    })

    it('honors a persisted selection over the is_primary default', () => {
      // User previously chose USA; Tunisia is the primary membership.
      localStorage.setItem('autoerp-company-selection', 'company-usa-id')
      const withPrimary: Company[] = [
        { ...mockCompanies[0] },
        { ...mockCompanies[1], isPrimary: true },
        { ...mockCompanies[2] },
      ]

      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(withPrimary)
      })

      // Explicit prior choice must win so the scope never jumps to primary on refresh.
      expect(result.current.currentCompanyId).toBe('company-usa-id')
    })

    it('persists the auto-selected company so a refresh restores it', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Auto-selected first company is written to the manual persistence key.
      expect(result.current.currentCompanyId).toBe('company-france-id')
      expect(localStorage.getItem('autoerp-company-selection')).toBe('company-france-id')
    })
  })

  describe('setCurrentCompany', () => {
    beforeEach(() => {
      const { result } = renderHook(() => useCompanyStore())
      act(() => {
        result.current.setCompanies(mockCompanies)
      })
    })

    it('updates currentCompanyId when company exists', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCurrentCompany('company-tunisia-id')
      })

      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
    })

    it('persists selection to localStorage', () => {
      const { result } = renderHook(() => useCompanyStore())

      // First set companies (required for validation)
      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-tunisia-id')
      })

      // Check localStorage
      expect(localStorage.getItem('autoerp-company-selection')).toBe('company-tunisia-id')
    })

    it('does not update if company does not exist', () => {
      const { result } = renderHook(() => useCompanyStore())

      // First set companies, then set a valid company
      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-france-id')
      })

      expect(result.current.currentCompanyId).toBe('company-france-id')

      // Try to set invalid company
      act(() => {
        result.current.setCurrentCompany('non-existent-company')
      })

      // Should keep France selection
      expect(result.current.currentCompanyId).toBe('company-france-id')
      expect(localStorage.getItem('autoerp-company-selection')).toBe('company-france-id')
    })

    it('handles localStorage write errors gracefully', () => {
      // Mock localStorage.setItem to throw error
      vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('localStorage quota exceeded')
      })

      const { result } = renderHook(() => useCompanyStore())

      // Should not throw, but log error
      act(() => {
        result.current.setCurrentCompany('company-tunisia-id')
      })

      // State should still update (only persistence failed)
      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
    })
  })

  describe('getCurrentCompany', () => {
    beforeEach(() => {
      const { result } = renderHook(() => useCompanyStore())
      act(() => {
        result.current.setCompanies(mockCompanies)
      })
    })

    it('returns null when no company selected', () => {
      const { result } = renderHook(() => useCompanyStore())

      // setCompanies now auto-selects deterministically, so explicitly clear the
      // selection to exercise the "nothing selected" branch.
      act(() => {
        useCompanyStore.setState({ currentCompanyId: null })
      })

      expect(result.current.getCurrentCompany()).toBeNull()
    })

    it('returns the selected company object', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCurrentCompany('company-tunisia-id')
      })

      const currentCompany = result.current.getCurrentCompany()

      expect(currentCompany).toEqual(mockCompanies[1]) // Tunisia
      expect(currentCompany?.currency).toBe('TND')
    })

    it('returns null if selected company ID does not exist in companies array', () => {
      const { result } = renderHook(() => useCompanyStore())

      // Manually set invalid company ID (bypassing validation)
      act(() => {
        useCompanyStore.setState({ currentCompanyId: 'invalid-id' })
      })

      expect(result.current.getCurrentCompany()).toBeNull()
    })
  })

  describe('reset', () => {
    it('resets store to initial state', () => {
      const { result } = renderHook(() => useCompanyStore())

      // Setup some state
      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-tunisia-id')
        result.current.setLoading(false)
      })

      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
      expect(result.current.companies).toHaveLength(3)

      // Reset
      act(() => {
        result.current.reset()
      })

      expect(result.current.currentCompanyId).toBeNull()
      expect(result.current.companies).toEqual([])
      expect(result.current.isLoading).toBe(true)
    })
  })

  describe('Regression Tests - Critical User Journeys', () => {
    it('REGRESSION: Currency displays correctly for selected company', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Select France
      act(() => {
        result.current.setCurrentCompany('company-france-id')
      })

      let company = result.current.getCurrentCompany()
      expect(company?.currency).toBe('EUR')

      // Switch to Tunisia
      act(() => {
        result.current.setCurrentCompany('company-tunisia-id')
      })

      company = result.current.getCurrentCompany()
      expect(company?.currency).toBe('TND')

      // Switch to USA
      act(() => {
        result.current.setCurrentCompany('company-usa-id')
      })

      company = result.current.getCurrentCompany()
      expect(company?.currency).toBe('USD')
    })

    it('REGRESSION: Company selection persists across simulated page refresh', () => {
      // Simulate initial session
      const { result: firstSession, unmount: unmountFirst } = renderHook(() => useCompanyStore())

      act(() => {
        firstSession.current.setCompanies(mockCompanies)
        firstSession.current.setCurrentCompany('company-tunisia-id')
      })

      expect(firstSession.current.currentCompanyId).toBe('company-tunisia-id')
      expect(localStorage.getItem('autoerp-company-selection')).toBe('company-tunisia-id')

      // Cleanup first hook
      unmountFirst()

      // Simulate page refresh: clear only the in-memory Zustand state
      // (companies + currentCompanyId) — do NOT call `reset()`, which
      // also wipes the persisted localStorage key. A real refresh keeps
      // localStorage and starts with an empty in-memory store.
      act(() => {
        useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: true })
      })
      const { result: secondSession } = renderHook(() => useCompanyStore())

      // Simulate CompanyProvider refetching companies
      act(() => {
        secondSession.current.setCompanies(mockCompanies)
      })

      // Should restore Tunisia selection from localStorage
      expect(secondSession.current.currentCompanyId).toBe('company-tunisia-id')
      expect(secondSession.current.getCurrentCompany()?.currency).toBe('TND')
    })

    it('REGRESSION: Switching companies updates immediately without refresh', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-france-id')
      })

      expect(result.current.getCurrentCompany()?.currency).toBe('EUR')

      // User switches to Tunisia via company selector
      act(() => {
        result.current.setCurrentCompany('company-tunisia-id')
      })

      // Currency should update immediately (no refresh needed)
      expect(result.current.getCurrentCompany()?.currency).toBe('TND')
    })

    it('REGRESSION: First-time user deterministically defaults to the first company (centralized in store)', () => {
      // Auto-selection is now centralized in the store's setCompanies via the
      // deterministic rule — no longer a separate provider step that could pick
      // an arbitrary company.
      const { result } = renderHook(() => useCompanyStore())

      // Fresh user - no localStorage
      expect(localStorage.getItem('autoerp-company-selection')).toBeNull()

      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Store auto-selects the first company (no is_primary / persisted value).
      expect(result.current.currentCompanyId).toBe('company-france-id')
      expect(localStorage.getItem('autoerp-company-selection')).toBe('company-france-id')
    })

    it('REGRESSION: No interference from Zustand persist middleware', () => {
      // Set selection
      const { result, unmount } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-tunisia-id')
      })

      // Verify our manual key is used
      expect(localStorage.getItem('autoerp-company-selection')).toBe('company-tunisia-id')

      // Check that Zustand persist key exists but is empty (partialize returns {})
      const zustandKey = localStorage.getItem('autoerp-company')
      if (zustandKey) {
        const parsed = JSON.parse(zustandKey)
        // Should be empty object or not contain currentCompanyId
        expect(parsed.state).toBeDefined()
        // Since we use partialize: () => ({}), the state should be empty or minimal
      }

      // Cleanup
      unmount()

      // Simulate page refresh: clear in-memory state only (same reason
      // as the previous test — `reset()` would wipe
      // `autoerp-company-selection`, and we want to prove the manual
      // key is consulted on rehydrate).
      act(() => {
        useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: true })
      })
      const { result: newSession } = renderHook(() => useCompanyStore())

      act(() => {
        newSession.current.setCompanies(mockCompanies)
      })

      // Should restore from our manual key, not Zustand persist key
      expect(newSession.current.currentCompanyId).toBe('company-tunisia-id')
    })
  })

  describe('Edge Cases', () => {
    it('handles empty companies array', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies([])
      })

      expect(result.current.companies).toEqual([])
      expect(result.current.isLoading).toBe(false)
      expect(result.current.getCurrentCompany()).toBeNull()
    })

    it('handles rapid company switching', () => {
      const { result } = renderHook(() => useCompanyStore())

      // First set companies
      act(() => {
        result.current.setCompanies(mockCompanies)
      })

      // Rapid switches
      act(() => {
        result.current.setCurrentCompany('company-france-id')
        result.current.setCurrentCompany('company-tunisia-id')
        result.current.setCurrentCompany('company-usa-id')
        result.current.setCurrentCompany('company-france-id')
      })

      // Should end on France
      expect(result.current.currentCompanyId).toBe('company-france-id')
      expect(localStorage.getItem('autoerp-company-selection')).toBe('company-france-id')
    })

    it('handles companies with same name but different IDs', () => {
      const duplicateNameCompanies: Company[] = [
        { ...mockCompanies[0], id: 'company-1' },
        { ...mockCompanies[0], id: 'company-2', name: 'French Company' },
      ]

      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(duplicateNameCompanies)
        result.current.setCurrentCompany('company-2')
      })

      expect(result.current.currentCompanyId).toBe('company-2')
      expect(result.current.getCurrentCompany()?.id).toBe('company-2')
    })
  })

  describe('Cross-tab reconciliation (storage event)', () => {
    it('adopts a company selection changed in another tab', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-france-id')
      })

      expect(result.current.currentCompanyId).toBe('company-france-id')

      // Another tab switches to Tunisia -> storage event fires in this tab.
      act(() => {
        window.dispatchEvent(
          new StorageEvent('storage', {
            key: 'autoerp-company-selection',
            newValue: 'company-tunisia-id',
          }),
        )
      })

      expect(result.current.currentCompanyId).toBe('company-tunisia-id')
    })

    it('ignores an unknown company id from another tab', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-france-id')
      })

      act(() => {
        window.dispatchEvent(
          new StorageEvent('storage', {
            key: 'autoerp-company-selection',
            newValue: 'not-a-real-company',
          }),
        )
      })

      // Unknown id (companies are loaded) must not switch scope.
      expect(result.current.currentCompanyId).toBe('company-france-id')
    })

    it('clears the selection when another tab logs out (key removed)', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-france-id')
      })

      act(() => {
        window.dispatchEvent(
          new StorageEvent('storage', {
            key: 'autoerp-company-selection',
            newValue: null,
          }),
        )
      })

      expect(result.current.currentCompanyId).toBeNull()
    })

    it('ignores storage events for unrelated keys', () => {
      const { result } = renderHook(() => useCompanyStore())

      act(() => {
        result.current.setCompanies(mockCompanies)
        result.current.setCurrentCompany('company-france-id')
      })

      act(() => {
        window.dispatchEvent(
          new StorageEvent('storage', {
            key: 'some-other-key',
            newValue: 'company-tunisia-id',
          }),
        )
      })

      expect(result.current.currentCompanyId).toBe('company-france-id')
    })
  })
})
