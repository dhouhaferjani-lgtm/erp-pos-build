import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { EarningRulesTab } from '../EarningRulesTab'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const mockRules = [
  {
    id: '1',
    program_id: 'p1',
    name: 'Spend Rule',
    rule_type: 'spend' as const,
    priority: 1,
    is_active: true,
    conditions: {},
    reward_value: '10',
    reward_type: 'points',
    start_date: null,
    end_date: null,
    max_earn_per_transaction: null,
    max_earn_per_day: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: null,
  },
]

let mockUseQueryReturn: { data: typeof mockRules | undefined; isLoading: boolean } = {
  data: mockRules,
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

describe('EarningRulesTab', () => {
  beforeEach(() => {
    mockUseQueryReturn = { data: mockRules, isLoading: false }
  })

  it('renders earning rules table', () => {
    render(<EarningRulesTab programId="p1" />)
    expect(screen.getByText('Spend Rule')).toBeInTheDocument()
    expect(screen.getByText('10')).toBeInTheDocument()
  })

  it('renders empty state when no rules', () => {
    mockUseQueryReturn = { data: [], isLoading: false }
    render(<EarningRulesTab programId="p1" />)
    expect(screen.getByText('loyalty:earningRules.noRules')).toBeInTheDocument()
  })

  it('shows add button', () => {
    render(<EarningRulesTab programId="p1" />)
    expect(screen.getByText('loyalty:earningRules.create')).toBeInTheDocument()
  })
})
