import { describe, expect, it } from 'vitest'
import { PERMISSIONS } from '../usePermissions'

const fullRoles = ['admin', 'manager', 'accountant']
const viewOnlyRoles = ['cashier', 'operator', 'viewer']

describe('expense recurrence frontend permission alignment', () => {
  it('grants full recurrence CRUD and export only to the normative financial roles', () => {
    expect(PERMISSIONS['expense-recurrences.view']).toEqual([
      ...fullRoles,
      ...viewOnlyRoles,
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
