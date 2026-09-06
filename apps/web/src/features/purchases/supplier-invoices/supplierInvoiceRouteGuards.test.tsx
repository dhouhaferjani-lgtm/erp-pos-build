/**
 * Gate r2 finding 1 (MAJOR) — the ROUTE GUARD, not the bare page.
 *
 * The fix round's grantability test rendered `<SupplierInvoiceListPage />`
 * directly, so it passed while the real route — wrapped in
 * `RequirePermission moduleKey="purchases"` — redirected the very operator it
 * claimed to prove. These tests mount the guard the router actually uses, with
 * the REAL `usePermissions` hook and the REAL auth store, and additionally pin
 * the generated route manifest so the wiring itself cannot drift back.
 *
 * Backend twins (apps/api/app/Modules/Procurement/Presentation/routes.php):
 *   GET  /supplier-invoices        :84   can:documents.view
 *   GET  /supplier-invoices/{id}   :94   can:documents.view
 *   POST /supplier-invoices        :104  can:supplier-invoices.manage
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it } from 'vitest'

import { RequirePermission } from '../../auth/components'
import { useAuthStore } from '../../../stores/authStore'
import type { Permission } from '../../../hooks/usePermissions'

const baseUser = {
  id: 'user-1',
  name: 'Test User',
  email: 'test@example.com',
  tenant_id: 'tenant-1',
  email_verified_at: null,
}

/** The server permission payload a real login returns (User::getAllPermissions()). */
function signIn(roles: string[], permissions: string[]) {
  useAuthStore.setState({
    user: { ...baseUser, roles, permissions },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
}

function renderGuard(permission: Permission) {
  return render(
    <MemoryRouter initialEntries={['/purchases/supplier-invoices']}>
      <RequirePermission permission={permission}>
        <div>SUPPLIER INVOICE PAGE</div>
      </RequirePermission>
    </MemoryRouter>
  )
}

function canReach(permission: Permission): boolean {
  const { unmount } = renderGuard(permission)
  const reached = screen.queryByText('SUPPLIER INVOICE PAGE') !== null
  unmount()
  return reached
}

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
})

// The permission sets below are exactly what RolesAndPermissionsSeeder grants.
const CASHIER = ['documents.view', 'documents.update', 'payments.create', 'partners.view']
const OPERATOR_DEFAULT = ['documents.view', 'documents.update', 'purchase-orders.view']
const OPERATOR_GRANTED = [...OPERATOR_DEFAULT, 'supplier-invoices.manage']
const ACCOUNTANT = ['documents.view', 'documents.update', 'supplier-invoices.manage', 'payments.pay-supplier']

describe('supplier-invoice route guards — read (documents.view)', () => {
  it('lets an accountant through', () => {
    signIn(['accountant'], ACCOUNTANT)
    expect(canReach('documents.view')).toBe(true)
  })

  it('lets an operator granted supplier-invoices.manage through', () => {
    signIn(['operator'], OPERATOR_GRANTED)
    expect(canReach('documents.view')).toBe(true)
  })

  it('lets a DEFAULT operator through — read mirrors the API, which allows documents.view', () => {
    signIn(['operator'], OPERATOR_DEFAULT)
    expect(canReach('documents.view')).toBe(true)
  })

  it('denies a signed-out visitor', () => {
    expect(canReach('documents.view')).toBe(false)
  })
})

describe('supplier-invoice route guard — create (supplier-invoices.manage)', () => {
  it('lets an accountant through — the seeded grant this PR adds', () => {
    signIn(['accountant'], ACCOUNTANT)
    expect(canReach('supplier-invoices.manage')).toBe(true)
  })

  it('lets an operator GRANTED the permission through (owner ruling: grantable)', () => {
    signIn(['operator'], OPERATOR_GRANTED)
    expect(canReach('supplier-invoices.manage')).toBe(true)
  })

  it('denies a DEFAULT operator (owner ruling: secure default)', () => {
    signIn(['operator'], OPERATOR_DEFAULT)
    expect(canReach('supplier-invoices.manage')).toBe(false)
  })

  it('denies a cashier', () => {
    signIn(['cashier'], CASHIER)
    expect(canReach('supplier-invoices.manage')).toBe(false)
  })
})

describe('supplier-invoice route wiring (generated manifest)', () => {
  const manifest = readFileSync(
    resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../scripts/factory/manifests/routes-web.yaml'),
    'utf8'
  )

  function entryFor(path: string): string {
    const start = manifest.indexOf(`  - path: ${path}\n`)
    expect(start, `route ${path} missing from the generated manifest`).toBeGreaterThan(-1)
    return manifest.slice(start, manifest.indexOf('  - path:', start + 1))
  }

  it.each([
    ['/purchases/supplier-invoices', 'documents.view'],
    ['/purchases/supplier-invoices/:id', 'documents.view'],
    ['/purchases/supplier-invoices/new', 'supplier-invoices.manage'],
  ])('%s gates on %s with no module role-alias', (path, permission) => {
    const entry = entryFor(path)
    expect(entry).toContain(`permission: ${permission}`)
    expect(entry).toContain('module_gate: null')
  })
})
