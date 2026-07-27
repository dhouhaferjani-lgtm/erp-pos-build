import { describe, expect, it } from 'vitest'
import { MODULE_PERMISSIONS, PERMISSIONS } from '../usePermissions'

describe('treasury bank reconciliation permission alignment', () => {
  it('repositories.view follows the current seeder grants', () => {
    expect(PERMISSIONS['repositories.view']).toEqual(['accountant', 'admin', 'manager', 'viewer'])
  })

  it('does not preserve the frontend-only treasury role fallback', () => {
    expect(PERMISSIONS['repositories.view']).not.toContain('treasury')
  })

  it('registers treasury.adjust for the backend-authorized financial roles', () => {
    expect(PERMISSIONS['treasury.adjust']).toEqual(['accountant', 'admin', 'manager'])
  })

  it('gates statement navigation with the server-authoritative view permission', () => {
    expect(MODULE_PERMISSIONS['bank-statements.view']).toEqual(['bank-statements.view'])
  })
})
