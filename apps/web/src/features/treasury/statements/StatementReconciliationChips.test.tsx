import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { StatementReconciliationChips } from './StatementReconciliationChips'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('@tanstack/react-query', () => ({
  useQuery: () => ({ data: [{ bank_statement_id: 'statement-1', bank_statement_line_id: 'line-1', action_type: 'outbound_clear', executed_at: '2026-07-18T10:00:00Z' }] }),
}))
vi.mock('@/hooks/usePermissions', () => ({ usePermissions: () => ({ hasPermission: () => true }) }))
vi.mock('@/lib/tenantScopedKey', () => ({ tenantScopedKey: (segments: unknown[]) => segments }))
vi.mock('@/stores/authStore', () => ({ useAuthStore: (selector: (state: { user: { tenant_id: string } }) => unknown) => selector({ user: { tenant_id: 'tenant-1' } }) }))
vi.mock('@/stores/companyStore', () => ({ useCompanyStore: (selector: (state: { currentCompanyId: string }) => unknown) => selector({ currentCompanyId: 'company-1' }) }))

describe('StatementReconciliationChips', () => {
  it('links a source record back to its statement line provenance', () => {
    render(<MemoryRouter><StatementReconciliationChips targetType="payment_instrument" targetId="instrument-1" /></MemoryRouter>)

    const link = screen.getByRole('link', { name: 'statements.workspace.reconciledBy' })
    expect(link).toHaveAttribute('href', '/treasury/statements/statement-1?line=line-1')
  })
})
