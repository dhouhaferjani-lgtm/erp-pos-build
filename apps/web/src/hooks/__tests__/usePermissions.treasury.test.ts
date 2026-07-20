import { describe, expect, it } from 'vitest'

import { MODULE_PERMISSIONS, PERMISSIONS } from '../usePermissions'

describe('treasury permission alignment', () => {
  it('repositories.view grants the same roles as treasury.view navigation visibility', () => {
    expect(PERMISSIONS['repositories.view']).toEqual(PERMISSIONS['treasury.view'])
  })

  it('repositories.view includes treasury and manager roles that can see the repository link', () => {
    expect(PERMISSIONS['repositories.view']).toEqual(
      expect.arrayContaining(['admin', 'treasury', 'accountant', 'manager']),
    )
  })

  it('registers treasury.adjust for the backend-authorized financial roles', () => {
    expect(PERMISSIONS['treasury.adjust']).toEqual(['admin', 'manager', 'accountant'])
  })

  it('gates statement navigation with the server-authoritative view permission', () => {
    expect(MODULE_PERMISSIONS['bank-statements.view']).toEqual(['bank-statements.view'])
  })
})
