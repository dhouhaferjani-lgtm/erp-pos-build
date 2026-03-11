import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ProgramListPage } from '../ProgramListPage'

const mockNavigate = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}))

const mockPrograms = [
  {
    id: '1',
    name: 'VIP Points',
    program_type: 'points' as const,
    status: 'active' as const,
    currency: 'EUR',
    start_date: '2026-01-01',
    end_date: null,
    terms_and_conditions: null,
    metadata: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  },
  {
    id: '2',
    name: 'Coffee Stamps',
    program_type: 'stamps' as const,
    status: 'draft' as const,
    currency: null,
    start_date: null,
    end_date: null,
    terms_and_conditions: null,
    metadata: null,
    created_at: '2026-02-01T00:00:00Z',
    updated_at: null,
  },
]

let mockUseQueryReturn: { data: typeof mockPrograms | undefined; isLoading: boolean } = {
  data: mockPrograms,
  isLoading: false,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => mockUseQueryReturn,
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

describe('ProgramListPage', () => {
  beforeEach(() => {
    mockUseQueryReturn = { data: mockPrograms, isLoading: false }
    mockNavigate.mockClear()
  })

  it('renders programs table with data', () => {
    render(<ProgramListPage />)
    expect(screen.getByText('VIP Points')).toBeInTheDocument()
    expect(screen.getByText('Coffee Stamps')).toBeInTheDocument()
  })

  it('renders loading state', () => {
    mockUseQueryReturn = { data: undefined, isLoading: true }
    render(<ProgramListPage />)
    expect(screen.queryByText('VIP Points')).not.toBeInTheDocument()
  })

  it('renders empty state when no programs', () => {
    mockUseQueryReturn = { data: [], isLoading: false }
    render(<ProgramListPage />)
    expect(screen.getByText('loyalty:programs.noPrograms')).toBeInTheDocument()
  })

  it('navigates to create page on button click', () => {
    render(<ProgramListPage />)
    fireEvent.click(screen.getByText('loyalty:programs.create'))
    expect(mockNavigate).toHaveBeenCalledWith('/pos/loyalty/programs/new')
  })

  it('navigates to detail page on row click', () => {
    render(<ProgramListPage />)
    fireEvent.click(screen.getByText('VIP Points'))
    expect(mockNavigate).toHaveBeenCalledWith('/pos/loyalty/programs/1')
  })
})
