import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { MemberListPage } from '../MemberListPage'

const mockNavigate = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}))

const mockMembersData = {
  data: [
    {
      id: '1',
      customer_id: null,
      phone: '+33612345678',
      email: 'john@example.com',
      first_name: 'John',
      last_name: 'Doe',
      date_of_birth: null,
      status: 'active' as const,
      enrollment_date: '2026-01-01T00:00:00Z',
      external_id: null,
      created_at: '2026-01-01T00:00:00Z',
      updated_at: null,
    },
  ],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
}

let mockUseQueryReturn: { data: typeof mockMembersData | undefined; isLoading: boolean } = {
  data: mockMembersData,
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

describe('MemberListPage', () => {
  beforeEach(() => {
    mockUseQueryReturn = { data: mockMembersData, isLoading: false }
    mockNavigate.mockClear()
  })

  it('renders members table with data', () => {
    render(<MemberListPage />)
    expect(screen.getByText('John Doe')).toBeInTheDocument()
    expect(screen.getByText('+33612345678')).toBeInTheDocument()
  })

  it('renders empty state when no members', () => {
    mockUseQueryReturn = {
      data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } },
      isLoading: false,
    }
    render(<MemberListPage />)
    expect(screen.getByText('loyalty:members.noMembers')).toBeInTheDocument()
  })

  it('navigates to create page on button click', () => {
    render(<MemberListPage />)
    fireEvent.click(screen.getByText('loyalty:members.create'))
    expect(mockNavigate).toHaveBeenCalledWith('/pos/loyalty/members/new')
  })

  it('navigates to detail page on row click', () => {
    render(<MemberListPage />)
    fireEvent.click(screen.getByText('John Doe'))
    expect(mockNavigate).toHaveBeenCalledWith('/pos/loyalty/members/1')
  })
})
