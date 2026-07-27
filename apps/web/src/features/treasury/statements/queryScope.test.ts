import { describe, expect, it } from 'vitest'

import { shouldRefreshWorkspaceQuery } from './queryScope'

describe('statement workspace query invalidation', () => {
  const tenantId = 'tenant-a'
  const companyId = 'company-a'

  it.each([
    ['bank-statement', 'statement-id', tenantId, companyId],
    ['bank-statements', { page: 1 }, tenantId, companyId],
    ['bank-statement-line-suggestions', 'line-id', tenantId, companyId],
    ['repository-movements', 'repository-id', { search: '' }, tenantId, companyId],
    ['payment-repositories', tenantId, companyId],
    ['bank-statement-target-provenance', 'expense_document', 'expense-id', tenantId, companyId],
  ])('refreshes %s in the active tenant and company', (...queryKey) => {
    expect(shouldRefreshWorkspaceQuery(queryKey, tenantId, companyId)).toBe(true)
  })

  it('does not invalidate another company cache entry', () => {
    expect(shouldRefreshWorkspaceQuery(
      ['bank-statements', { page: 1 }, tenantId, 'company-b'],
      tenantId,
      companyId,
    )).toBe(false)
  })

  it('does not invalidate unrelated active-company data', () => {
    expect(shouldRefreshWorkspaceQuery(
      ['products', tenantId, companyId],
      tenantId,
      companyId,
    )).toBe(false)
  })
})
