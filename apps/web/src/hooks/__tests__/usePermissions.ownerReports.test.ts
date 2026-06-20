import { describe, expect, it } from 'vitest'
import { MODULE_PERMISSIONS } from '../usePermissions'

describe('ownerReports module permission', () => {
  it('maps to dashboard.owner', () => {
    expect(MODULE_PERMISSIONS['ownerReports']).toEqual(['dashboard.owner'])
  })
})
