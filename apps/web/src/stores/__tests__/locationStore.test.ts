import { describe, it, expect, beforeEach, afterEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useLocationStore, type Location } from '../locationStore'

/**
 * Tests for location selection stability and cross-tab reconciliation.
 *
 * These lock the behavior behind the "location scope switches on its own" fix:
 * setLocations must honor an already-selected/persisted location instead of
 * auto-picking the default, and a location switch in another tab must be
 * adopted in this one.
 */

function makeLocation(overrides: Partial<Location> & { id: string }): Location {
  return {
    companyId: 'company-1',
    name: `Location ${overrides.id}`,
    code: overrides.id.toUpperCase(),
    type: 'shop',
    phone: null,
    email: null,
    addressStreet: null,
    addressCity: null,
    addressPostalCode: null,
    addressCountry: null,
    isDefault: false,
    isActive: true,
    posEnabled: true,
    createdAt: '2026-01-01T00:00:00Z',
    updatedAt: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

const locations: Location[] = [
  makeLocation({ id: 'loc-warehouse', isDefault: true, type: 'warehouse' }),
  makeLocation({ id: 'loc-shop', type: 'shop' }),
  makeLocation({ id: 'loc-office', type: 'office' }),
]

describe('locationStore', () => {
  beforeEach(() => {
    localStorage.clear()
    useLocationStore.getState().reset()
  })

  afterEach(() => {
    localStorage.clear()
  })

  describe('setLocations', () => {
    it('auto-selects the default location when nothing is selected', () => {
      const { result } = renderHook(() => useLocationStore())

      act(() => {
        result.current.setLocations(locations)
      })

      expect(result.current.currentLocationId).toBe('loc-warehouse')
      expect(result.current.isLoading).toBe(false)
    })

    it('honors an already-selected location that still exists', () => {
      const { result } = renderHook(() => useLocationStore())

      act(() => {
        result.current.setLocations(locations)
        result.current.setCurrentLocation('loc-shop')
      })

      expect(result.current.currentLocationId).toBe('loc-shop')

      // A refetch (e.g. remount) must NOT reset back to the default location.
      act(() => {
        result.current.setLocations(locations)
      })

      expect(result.current.currentLocationId).toBe('loc-shop')
    })

    it('re-selects the default when the current location disappears', () => {
      const { result } = renderHook(() => useLocationStore())

      act(() => {
        result.current.setLocations(locations)
        result.current.setCurrentLocation('loc-shop')
      })

      act(() => {
        result.current.setLocations(locations.filter((l) => l.id !== 'loc-shop'))
      })

      expect(result.current.currentLocationId).toBe('loc-warehouse')
    })
  })

  describe('resetForCompanyChange', () => {
    it('clears the current location and marks loading', () => {
      const { result } = renderHook(() => useLocationStore())

      act(() => {
        result.current.setLocations(locations)
        result.current.setCurrentLocation('loc-shop')
      })

      act(() => {
        result.current.resetForCompanyChange()
      })

      expect(result.current.currentLocationId).toBeNull()
      expect(result.current.locations).toEqual([])
      expect(result.current.isLoading).toBe(true)
    })
  })

  describe('Cross-tab reconciliation (storage event)', () => {
    it('adopts a location changed in another tab', () => {
      const { result } = renderHook(() => useLocationStore())

      act(() => {
        result.current.setLocations(locations)
        result.current.setCurrentLocation('loc-warehouse')
      })

      act(() => {
        window.dispatchEvent(
          new StorageEvent('storage', {
            key: 'autoerp-location',
            newValue: JSON.stringify({ state: { currentLocationId: 'loc-office' } }),
          }),
        )
      })

      expect(result.current.currentLocationId).toBe('loc-office')
    })

    it('ignores an unknown location id from another tab', () => {
      const { result } = renderHook(() => useLocationStore())

      act(() => {
        result.current.setLocations(locations)
        result.current.setCurrentLocation('loc-warehouse')
      })

      act(() => {
        window.dispatchEvent(
          new StorageEvent('storage', {
            key: 'autoerp-location',
            newValue: JSON.stringify({ state: { currentLocationId: 'loc-unknown' } }),
          }),
        )
      })

      expect(result.current.currentLocationId).toBe('loc-warehouse')
    })

    it('tolerates malformed persisted payloads', () => {
      const { result } = renderHook(() => useLocationStore())

      act(() => {
        result.current.setLocations(locations)
        result.current.setCurrentLocation('loc-warehouse')
      })

      act(() => {
        window.dispatchEvent(
          new StorageEvent('storage', {
            key: 'autoerp-location',
            newValue: 'not-json{',
          }),
        )
      })

      expect(result.current.currentLocationId).toBe('loc-warehouse')
    })
  })
})
