import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { OwnershipHistoryTimeline } from '../OwnershipHistoryTimeline'
import type { VehicleOwnershipData } from '../../../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

let mockData: VehicleOwnershipData[] | undefined
let mockIsLoading = false
let mockError: Error | null = null

vi.mock('../../../hooks/useVehicleOwnershipHistory', () => ({
  useVehicleOwnershipHistory: () => ({
    data: mockData,
    isLoading: mockIsLoading,
    error: mockError,
  }),
}))

const buildOwnership = (id: string, name: string, released: string | null = null): VehicleOwnershipData => ({
  id,
  vehicle_id: 'veh-1',
  owner_partner_id: `p-${id}`,
  owner_display_name: name,
  acquired_at: '2026-01-15T10:00:00Z',
  released_at: released,
  reason_code: 'purchase',
  notes: null,
  recorded_by_user_id: null,
})

describe('OwnershipHistoryTimeline', () => {
  it('renders empty state when no ownerships', () => {
    mockData = []
    mockIsLoading = false
    mockError = null
    render(<OwnershipHistoryTimeline vehicleId="veh-1" />)
    expect(screen.getByText('ownership.noOwner')).toBeInTheDocument()
  })

  it('renders both current and closed rows', () => {
    mockData = [
      buildOwnership('1', 'Current Owner', null),
      buildOwnership('2', 'Past Owner', '2026-02-15T10:00:00Z'),
    ]
    mockIsLoading = false
    mockError = null
    render(<OwnershipHistoryTimeline vehicleId="veh-1" />)
    expect(screen.getByText('Current Owner')).toBeInTheDocument()
    expect(screen.getByText('Past Owner')).toBeInTheDocument()
  })

  it('shows loading state', () => {
    mockData = undefined
    mockIsLoading = true
    mockError = null
    render(<OwnershipHistoryTimeline vehicleId="veh-1" />)
    expect(screen.getByText('common:status.loading')).toBeInTheDocument()
  })
})
