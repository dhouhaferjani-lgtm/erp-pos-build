import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { OwnershipTimelineEntry } from '../OwnershipTimelineEntry'
import type { VehicleOwnershipData } from '../../../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

const buildOwnership = (overrides: Partial<VehicleOwnershipData> = {}): VehicleOwnershipData => ({
  id: 'own-1',
  vehicle_id: 'veh-1',
  owner_partner_id: 'p-1',
  owner_display_name: 'John Doe',
  acquired_at: '2026-01-15T10:00:00Z',
  released_at: null,
  reason_code: 'purchase',
  notes: null,
  recorded_by_user_id: null,
  ...overrides,
})

describe('OwnershipTimelineEntry', () => {
  it('renders owner display name', () => {
    render(<OwnershipTimelineEntry ownership={buildOwnership()} />)
    expect(screen.getByText('John Doe')).toBeInTheDocument()
  })

  it('marks open ownership row as current', () => {
    render(<OwnershipTimelineEntry ownership={buildOwnership({ released_at: null })} />)
    expect(screen.getByText('ownership.current')).toBeInTheDocument()
  })

  it('marks closed ownership row as closed', () => {
    render(
      <OwnershipTimelineEntry
        ownership={buildOwnership({ released_at: '2026-02-15T10:00:00Z' })}
      />,
    )
    expect(screen.getByText('ownership.closed')).toBeInTheDocument()
  })

  it('shows notes when present', () => {
    render(<OwnershipTimelineEntry ownership={buildOwnership({ notes: 'Fleet return' })} />)
    expect(screen.getByText('Fleet return')).toBeInTheDocument()
  })
})
