import { render, screen, fireEvent } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { WithholdingRulesPage } from './WithholdingRulesPage'
import type { WithholdingRule } from '../types'

// i18n: echo interpolation strings, otherwise return the key
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// Form modal: render a marker only when open so we can assert the Add control
// wired the create flow (modal opens).
vi.mock('../components/WithholdingRuleFormModal', () => ({
  WithholdingRuleFormModal: ({ isOpen }: { isOpen: boolean }) =>
    isOpen ? <div data-testid="rule-modal" /> : null,
}))

// Data + mutation hooks
interface RulesQueryResult {
  data: WithholdingRule[] | undefined
  isLoading: boolean
  isError: boolean
}
const useWithholdingRules = vi.fn<() => RulesQueryResult>()
vi.mock('../hooks/useWithholding', () => ({
  useWithholdingRules: () => useWithholdingRules(),
  useDeleteWithholdingRule: () => ({ mutateAsync: vi.fn() }),
}))

const rule: WithholdingRule = {
  id: 'r1',
  country_code: 'TN',
  company_id: null,
  is_global: true,
  code: 'WH-SVC',
  name: 'Services',
  description: null,
  display_name: 'Services',
  transaction_type: 'services',
  transaction_type_label: 'Services',
  partner_tax_status: null,
  partner_tax_status_label: null,
  min_amount: '1000',
  rate: '0.015',
  rate_percentage: '1.5',
  effective_from: '2026-01-01',
  effective_to: null,
  is_active: true,
  is_effective_now: true,
  created_at: '2026-01-01',
  updated_at: '2026-01-01',
}

beforeEach(() => {
  vi.clearAllMocks()
  useWithholdingRules.mockReturnValue({
    data: [rule],
    isLoading: false,
    isError: false,
  })
})

describe('WithholdingRulesPage', () => {
  it('renders exactly one h1', () => {
    const { container } = render(<WithholdingRulesPage />)
    expect(container.querySelectorAll('h1')).toHaveLength(1)
  })

  it('renders the active status as a StatusBadge pill', () => {
    const { container } = render(<WithholdingRulesPage />)
    const pill = Array.from(container.querySelectorAll('span')).find((el) =>
      el.className.includes('rounded-full'),
    )
    expect(pill).toBeTruthy()
  })

  it('Add control opens the create modal', () => {
    render(<WithholdingRulesPage />)
    expect(screen.queryByTestId('rule-modal')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /rules.addRule/ }))
    expect(screen.getByTestId('rule-modal')).toBeInTheDocument()
  })
})
