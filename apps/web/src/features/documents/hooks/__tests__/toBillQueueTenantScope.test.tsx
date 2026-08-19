import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useLocationStore, type Location } from '@/stores/locationStore'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { getToBillQueue } from '../../api/deliveryNotes'
import { useToBillQueue } from '../useDeliveryNotes'

vi.mock('../../api/deliveryNotes', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../api/deliveryNotes')>()
  return { ...actual, getToBillQueue: vi.fn() }
})

const location = (id: string): Location => ({
  id,
  companyId: 'company-1',
  name: id,
  code: id,
  type: 'shop',
  phone: null,
  email: null,
  addressStreet: null,
  addressCity: null,
  addressPostalCode: null,
  addressCountry: null,
  isDefault: id === 'location-1',
  isActive: true,
  posEnabled: false,
  createdAt: '2026-08-01T00:00:00Z',
  updatedAt: '2026-08-01T00:00:00Z',
})

describe('useToBillQueue location and tenant scope', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth({ tenantId: 'tenant-1', companyId: 'company-1' })
    useLocationStore.setState({
      locations: [location('location-1'), location('location-2')],
      currentLocationId: 'location-1',
      isLoading: false,
    })
    vi.mocked(getToBillQueue).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 },
      summary: { buckets: [], grand_total: '0.000', grand_count: 0, currency: 'TND' },
    })
  })

  afterEach(() => {
    act(() => {
      resetAuth()
      useLocationStore.getState().reset()
    })
  })

  it('keys by tenant, company, and active location and refetches on location change', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    )
    const params = { partnerSearch: '', dateFrom: '', dateTo: '', periodicOnly: false, page: 1, perPage: 25 }

    renderHook(() => useToBillQueue({ ...params, locationId: useLocationStore((state) => state.currentLocationId) }), { wrapper })

    await waitFor(() => {
      expect(getToBillQueue).toHaveBeenCalledWith(expect.objectContaining({ locationId: 'location-1' }))
    })
    expect(client.getQueryCache().getAll().some((query) => (
      query.queryKey.includes('tenant-1') &&
      query.queryKey.includes('company-1') &&
      query.queryKey.some((part) => typeof part === 'object' && part !== null && 'locScope' in part)
    ))).toBe(true)

    act(() => { useLocationStore.getState().setCurrentLocation('location-2') })

    await waitFor(() => {
      expect(getToBillQueue).toHaveBeenLastCalledWith(expect.objectContaining({ locationId: 'location-2' }))
    })
  })
})
