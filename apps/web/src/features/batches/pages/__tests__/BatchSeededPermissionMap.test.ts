import { describe, expect, it } from 'vitest'
import { PERMISSIONS, type Permission } from '@/hooks/permissionsMap.generated'

describe('W-LOT-A-1a generated seeded permission map', () => {
  it('matches the exact canonical batch and all-location role matrix', () => {
    const matrix: Partial<Record<Permission, string[]>> = {
      'batches.view': ['admin', 'cashier', 'general_manager', 'manager', 'operator', 'viewer'],
      'batches.create': ['admin', 'general_manager', 'manager'],
      'batches.update': ['admin', 'general_manager', 'manager'],
      'batches.delete': ['admin', 'general_manager', 'manager'],
      'batches.recall.request': ['admin', 'general_manager', 'manager'],
      'batches.recall': ['admin', 'general_manager'],
      'batches.write-off': ['admin', 'general_manager', 'manager'],
      'batches.traceability': ['admin', 'general_manager', 'manager'],
      'treasury.manage_all_locations': ['admin', 'general_manager'],
    }
    for (const [permission, roles] of Object.entries(matrix)) {
      expect(PERMISSIONS[permission as Permission]).toEqual(roles)
    }
  })
})
