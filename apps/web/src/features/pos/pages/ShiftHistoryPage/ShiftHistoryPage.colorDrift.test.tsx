import { describe, it, expect, vi } from 'vitest'
import { render } from '@testing-library/react'
import type { ShiftHistoryItem } from '../../api/shiftHistoryApi'
import { ShiftHistoryPage } from './ShiftHistoryPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

vi.mock('../../hooks/usePosTenantScope', () => ({
  usePosTenantScope: () => ({ hasTenantScope: true }),
}))

vi.mock('@/lib/tenantScopedKey', () => ({
  tenantScopedKey: (key: unknown[]) => key,
}))

const shift: ShiftHistoryItem = {
  id: 'shift-1',
  shift_number: 7,
  terminal_id: 'term-1',
  terminal_code: 'POS-01',
  terminal_name: 'Main',
  cashier_id: 'u-1',
  cashier_name: 'John Doe',
  status: 'OPEN',
  opening_cash: '500.000',
  expected_cash: '1250.000',
  actual_cash: null,
  variance: null,
  opened_at: '2026-01-09T08:00:00Z',
  closed_at: null,
  receipt_count: 3,
}

// useQuery is called twice: first for terminals, then for shift history.
let queryCall = 0
vi.mock('@tanstack/react-query', () => ({
  useQuery: () => {
    queryCall += 1
    // First call → terminals; second call → paginated shift data.
    if (queryCall % 2 === 1) {
      return { data: [], isLoading: false }
    }
    return {
      data: {
        data: [shift],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
      },
      isLoading: false,
    }
  },
}))

describe('ShiftHistoryPage — color-drift / tokenization', () => {
  it('renders the page title', () => {
    const { getByText } = render(<ShiftHistoryPage />)
    expect(getByText('pos:shiftHistory.title')).toBeInTheDocument()
  })

  it('renders the shift status via StatusBadge (rounded-full pill)', () => {
    const { getAllByText } = render(<ShiftHistoryPage />)
    // The label also appears in the status <option>; the badge is the <span> pill.
    const badge = getAllByText('pos:shiftHistory.open').find(
      (el) => el.tagName === 'SPAN',
    )
    expect(badge).toBeDefined()
    expect(badge?.className).toContain('rounded-full')
  })

  it('renders money/stat cells with tabular-nums', () => {
    const { getByText } = render(<ShiftHistoryPage />)
    const openingCash = getByText('500.000')
    expect(openingCash.className).toContain('tabular-nums')
    expect(openingCash.className).toContain('text-end')
  })
})
