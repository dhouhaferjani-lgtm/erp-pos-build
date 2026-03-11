import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { RewardsTab } from '../RewardsTab'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const mockRewards = [
  {
    id: '1',
    program_id: 'p1',
    name: 'Free Coffee',
    description: null,
    reward_type: 'free_item' as const,
    points_cost: '100',
    reward_value: '5.00',
    qualifying_items: null,
    max_discount: null,
    min_order_value: null,
    tier_ids: null,
    is_active: true,
    quantity_available: null,
    quantity_per_member: null,
    start_date: null,
    end_date: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  },
]

let mockUseQueryReturn: { data: typeof mockRewards | undefined; isLoading: boolean } = {
  data: mockRewards,
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

describe('RewardsTab', () => {
  beforeEach(() => {
    mockUseQueryReturn = { data: mockRewards, isLoading: false }
  })

  it('renders rewards table', () => {
    render(<RewardsTab programId="p1" />)
    expect(screen.getByText('Free Coffee')).toBeInTheDocument()
    expect(screen.getByText('100')).toBeInTheDocument()
  })

  it('renders empty state when no rewards', () => {
    mockUseQueryReturn = { data: [], isLoading: false }
    render(<RewardsTab programId="p1" />)
    expect(screen.getByText('loyalty:rewards.noRewards')).toBeInTheDocument()
  })

  it('shows add button', () => {
    render(<RewardsTab programId="p1" />)
    expect(screen.getByText('loyalty:rewards.create')).toBeInTheDocument()
  })
})
