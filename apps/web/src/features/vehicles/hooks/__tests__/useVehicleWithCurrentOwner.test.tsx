import { describe, it, expect, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { useVehicleWithCurrentOwner } from '../useVehicleWithCurrentOwner'

const apiPayload = {
  data: {
    vehicle: {
      id: 'veh-1',
      tenant_id: 'tenant-1',
      company_id: 'company-1',
      license_plate: 'ABC-123',
      brand: 'Toyota',
      model: 'Corolla',
      year: 2020,
      color: null,
      mileage: null,
      vin: null,
      engine_code: null,
      fuel_type: null,
      transmission: null,
      body_type: null,
      notes: null,
      current_owner_partner_id: 'partner-1',
      current_owner_display_name: 'Alice Current',
      partner_id: null,
      created_at: '2026-01-01T00:00:00Z',
      updated_at: null,
    },
    current_ownership: null,
    recent_mileage_readings: [],
  },
}

vi.mock('../../../../lib/api', () => ({
  api: {
    get: vi.fn(() => Promise.resolve({ data: apiPayload })),
  },
}))

function wrap({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useVehicleWithCurrentOwner', () => {
  it('returns the vehicle-with-current-owner payload shape', async () => {
    const { result } = renderHook(() => useVehicleWithCurrentOwner('veh-1'), {
      wrapper: wrap,
    })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(result.current.data?.vehicle.id).toBe('veh-1')
    expect(result.current.data?.vehicle.current_owner_partner_id).toBe('partner-1')
    expect(result.current.data?.vehicle.current_owner_display_name).toBe('Alice Current')
    expect(result.current.data?.current_ownership).toBeNull()
    expect(result.current.data?.recent_mileage_readings).toEqual([])
  })

  it('is disabled when vehicleId is empty', () => {
    const { result } = renderHook(() => useVehicleWithCurrentOwner(''), {
      wrapper: wrap,
    })

    expect(result.current.isFetching).toBe(false)
    expect(result.current.data).toBeUndefined()
  })
})
