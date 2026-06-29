/**
 * Task 9: expense permission gates
 *
 * Tests that the PERMISSIONS map contains the correct expense.*,
 * expense-categories.*, and documents.* keys with the role lists that
 * mirror the backend RolesAndPermissionsSeeder.
 *
 * Pattern mirrors usePermissions.ownerReports.test.ts — we test the
 * static PERMISSIONS map directly because the hook's hasPermission()
 * logic is a trivial set-membership check (`roles.some(r => allowed.includes(r))`).
 */
import { describe, expect, it } from 'vitest'
import { PERMISSIONS } from '../usePermissions'

describe('expenses permission keys', () => {
  it('expenses.view is granted to cashier', () => {
    expect(PERMISSIONS['expenses.view']).toContain('cashier')
  })

  it('expenses.view is granted to accountant', () => {
    expect(PERMISSIONS['expenses.view']).toContain('accountant')
  })

  it('expenses.create is granted to cashier', () => {
    expect(PERMISSIONS['expenses.create']).toContain('cashier')
  })

  it('expenses.create is NOT granted to viewer (viewer is read-only)', () => {
    expect(PERMISSIONS['expenses.create']).not.toContain('viewer')
  })

  it('expenses.update is granted to operator', () => {
    expect(PERMISSIONS['expenses.update']).toContain('operator')
  })

  it('expenses.delete is NOT granted to cashier', () => {
    expect(PERMISSIONS['expenses.delete']).not.toContain('cashier')
  })

  it('expenses.delete is granted to accountant', () => {
    expect(PERMISSIONS['expenses.delete']).toContain('accountant')
  })

  it('expenses.post is granted to accountant', () => {
    expect(PERMISSIONS['expenses.post']).toContain('accountant')
  })

  it('expenses.post is NOT granted to cashier', () => {
    expect(PERMISSIONS['expenses.post']).not.toContain('cashier')
  })
})

describe('expense-categories permission keys', () => {
  it('expense-categories.view is granted to cashier', () => {
    expect(PERMISSIONS['expense-categories.view']).toContain('cashier')
  })

  it('expense-categories.view is granted to viewer', () => {
    expect(PERMISSIONS['expense-categories.view']).toContain('viewer')
  })

  it('expense-categories.create is NOT granted to cashier', () => {
    expect(PERMISSIONS['expense-categories.create']).not.toContain('cashier')
  })

  it('expense-categories.create is granted to accountant', () => {
    expect(PERMISSIONS['expense-categories.create']).toContain('accountant')
  })

  it('expense-categories.delete is granted to manager', () => {
    expect(PERMISSIONS['expense-categories.delete']).toContain('manager')
  })
})

describe('documents permission keys', () => {
  it('documents.view is granted to cashier', () => {
    expect(PERMISSIONS['documents.view']).toContain('cashier')
  })

  it('documents.view is granted to viewer', () => {
    expect(PERMISSIONS['documents.view']).toContain('viewer')
  })

  it('documents.update is granted to operator', () => {
    expect(PERMISSIONS['documents.update']).toContain('operator')
  })

  it('documents.update is NOT granted to viewer', () => {
    expect(PERMISSIONS['documents.update']).not.toContain('viewer')
  })
})
