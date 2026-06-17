import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { TableManagementPage } from './TableManagementPage'
import type { FloorData, TableData } from '../../api/tableApi'

// i18n: return interpolation default when provided as a string, else the key.
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

const mockFloor: FloorData = {
  id: 'floor-1',
  name: 'Ground Floor',
  position: 0,
  is_active: true,
  tables: [],
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

const mockTable: TableData = {
  id: 'table-1',
  floor_id: 'floor-1',
  table_number: 'T1',
  label: 'Window',
  seats: 4,
  status: 'available',
  shape: null,
  position_x: null,
  position_y: null,
  width: null,
  height: null,
  current_order_id: null,
  floor: mockFloor,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
}

const idleMutation = { mutate: vi.fn(), isPending: false }

vi.mock('../../hooks/useTables', () => ({
  useFloors: () => ({ data: [mockFloor], isLoading: false }),
  useTables: () => ({ data: [mockTable], isLoading: false }),
  useCreateFloor: () => idleMutation,
  useDeleteFloor: () => idleMutation,
  useCreateTable: () => idleMutation,
  useDeleteTable: () => idleMutation,
}))

describe('TableManagementPage', () => {
  it('renders the page title and a table-state pill', () => {
    render(<TableManagementPage />)

    expect(screen.getByText('tables.title')).toBeInTheDocument()
    // TableStatusBadge renders the translated status label for the seeded table.
    expect(screen.getByText('tables.status.available')).toBeInTheDocument()
    expect(screen.getByText('T1')).toBeInTheDocument()
  })
})
