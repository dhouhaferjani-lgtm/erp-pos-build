import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { formatDate } from '@/lib/format'

import { ReconciliationWorkspacePage } from './ReconciliationWorkspacePage'

const statement = {
  id: 'statement-1',
  payment_repository_id: 'repository-1',
  currency: 'TND',
  period_start: '2026-07-01',
  period_end: '2026-07-31',
  opening_balance: '100.000',
  closing_balance: '110.000',
  status: 'reconciled' as const,
  parser_profile_id: null,
  imported_at: '2026-07-31T12:00:00Z',
  lines_count: 1,
  lines: [{
    id: 'line-1',
    line_number: 1,
    value_date: '2026-07-31',
    booking_date: null,
    direction: 'out' as const,
    amount: '10.000',
    reference: null,
    label: 'Settled line',
    match_status: 'matched' as const,
    ignore_reason: null,
    ignore_text: null,
    location_id: null,
    allocations: [{ repository_movement_id: 'movement-1', matched_amount: '10.000', match_type: 'manual' as const, movement_direction: 'out' as const }],
    executions: [],
  }],
}

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('react-router-dom', () => ({
  Link: ({ children }: { children: React.ReactNode }) => <a href="/treasury/statements">{children}</a>,
  useParams: () => ({ id: 'statement-1' }),
  useSearchParams: () => [new URLSearchParams()],
}))
vi.mock('@/hooks/usePermissions', () => ({ usePermissions: () => ({ hasPermission: () => false }) }))
const { useAuthStore, useCompanyStore } = vi.hoisted(() => {
  const authState = { user: { tenant_id: 'tenant-1' } }
  const companyState = { currentCompanyId: 'company-1' }
  return {
    useAuthStore: Object.assign((selector: (state: typeof authState) => unknown) => selector(authState), { getState: () => authState }),
    useCompanyStore: Object.assign((selector: (state: typeof companyState) => unknown) => selector(companyState), { getState: () => companyState }),
  }
})
vi.mock('@/stores/authStore', () => ({ useAuthStore }))
vi.mock('@/stores/companyStore', () => ({ useCompanyStore }))
vi.mock('@tanstack/react-query', () => ({
  useQuery: ({ queryKey }: { queryKey: unknown[] }) => ({ data: String(queryKey[0]).includes('bank-statement-line') || String(queryKey[0]).includes('repository-movements') ? [] : statement, isLoading: false, error: null }),
  useMutation: () => ({ mutate: vi.fn(), isPending: false, error: null }),
  useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}))
vi.mock('./api', () => ({
  allocateStatementLine: vi.fn(), completeBankStatement: vi.fn(), executeStatementAction: vi.fn(), getBankStatement: vi.fn(), getStatementSuggestions: vi.fn(), ignoreStatementLine: vi.fn(), reopenBankStatement: vi.fn(), searchRepositoryMovements: vi.fn(), unallocateStatementLine: vi.fn(), unignoreStatementLine: vi.fn(),
}))
vi.mock('./LinePanel', () => ({ LinePanel: ({ mutable }: { mutable: boolean }) => <div data-testid="line-panel" data-mutable={String(mutable)} /> }))
vi.mock('./StatementCompletionDialog', () => ({ StatementCompletionDialog: () => null }))

describe('ReconciliationWorkspacePage rendering', () => {
  it('passes read-only mutability to the line panel for a reconciled statement', () => {
    render(<ReconciliationWorkspacePage />)

    expect(screen.getByTestId('line-panel')).toHaveAttribute('data-mutable', 'false')
    expect(screen.queryByRole('button', { name: 'treasury:statements.workspace.complete.action' })).not.toBeInTheDocument()
  })

  it('renders localized statement and line dates instead of raw ISO strings', () => {
    render(<ReconciliationWorkspacePage />)

    expect(screen.getByText(`${formatDate('2026-07-01')} → ${formatDate('2026-07-31')}`)).toBeInTheDocument()
    expect(screen.getByText(new RegExp(`${formatDate('2026-07-31')} · #1`))).toBeInTheDocument()
    expect(screen.queryByText(/2026-07-01|2026-07-31/)).not.toBeInTheDocument()
  })
})
