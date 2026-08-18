import { describe, expect, it } from 'vitest'
import { PERMISSIONS } from '../usePermissions'

// Promoted permission-map generation lane (Phase 3.0.16): generated role arrays are canonical and sorted.
const fullRoles = ['accountant', 'admin', 'manager']
const viewOnlyRoles = ['cashier', 'operator', 'viewer']

describe('expense recurrence frontend permission alignment', () => {
  it('grants full recurrence CRUD and export only to the normative financial roles', () => {
    expect(PERMISSIONS['expense-recurrences.view']).toEqual([
      'accountant',
      'admin',
      'cashier',
      'manager',
      'operator',
      'viewer',
    ])

    for (const permission of [
      'expense-recurrences.create',
      'expense-recurrences.update',
      'expense-recurrences.delete',
      'expenses.export',
    ] as const) {
      expect(PERMISSIONS[permission]).toEqual(fullRoles)
      for (const role of viewOnlyRoles) {
        expect(PERMISSIONS[permission]).not.toContain(role)
      }
    }
  })
})
