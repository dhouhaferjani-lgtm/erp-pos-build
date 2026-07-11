import { describe, expect, it } from 'vitest'
import { MODULE_PERMISSIONS, PERMISSIONS } from '../usePermissions'

describe('replenishment frontend permission alignment', () => {
  it('grants operator inventory view and maps direct child route permissions', () => {
    expect(PERMISSIONS['inventory.view']).toContain('operator')
    expect(MODULE_PERMISSIONS['replenishment.view']).toEqual(['replenishment.view'])
    expect(MODULE_PERMISSIONS['inventory.transfers.view']).toEqual(['inventory.transfers.view'])
  })
})
