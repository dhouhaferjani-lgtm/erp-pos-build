import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter, Outlet } from 'react-router-dom'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { AppRoutes } from '../index'

const state = vi.hoisted(() => ({ enabled: true }))
vi.mock('@/components/layout/Layout', () => ({ Layout: () => <Outlet /> }))
vi.mock('@/features/auth/AuthProvider', () => ({ RequireAuth: ({ children }: { children: ReactNode }) => <>{children}</> }))
vi.mock('@/contexts', () => ({ useCompanyConfig: () => ({ config: {}, isLoading: false, error: null, hasModule: () => state.enabled }) }))
vi.mock('@/features/dashboard/Dashboard', () => ({ Dashboard: () => <div>Denied destination</div> }))
vi.mock('@/features/batches/pages/BatchListPage', () => ({ BatchListPage: () => <div>Product batches</div> }))
vi.mock('@/features/batches/pages/BatchDetailPage', () => ({ BatchDetailPage: () => <div>Product batches</div> }))
vi.mock('@/features/batches/pages/CreateBatchPage', () => ({ CreateBatchPage: () => <div>Create batch</div> }))
vi.mock('@/features/batches/pages/EditBatchPage', () => ({ EditBatchPage: () => <div>Edit batch</div> }))

async function check(path: string, permissions: string[], allowed: boolean, label: string) {
  seedAuth({ roles: [], permissions: [...permissions, 'dashboard.view'] })
  render(<MemoryRouter initialEntries={[path]}><AppRoutes /></MemoryRouter>)
  expect(await screen.findByText(allowed ? label : 'Denied destination')).toBeInTheDocument()
  if (!allowed) expect(screen.queryByText(label)).not.toBeInTheDocument()
  cleanup()
}
afterEach(() => { cleanup(); resetAuth(); state.enabled = true })

describe('W-LOT-A-1a batch route permissions', () => {
  it('denies list and detail with inventory.view but without batches.view', async () => {
    await check('/inventory/batches', ['inventory.view'], false, 'Product batches')
    await check('/inventory/batches/lot-a', ['inventory.view'], false, 'Product batches')
  })
  it('allows list and detail with batches.view', async () => {
    await check('/inventory/batches', ['batches.view'], true, 'Product batches')
    await check('/inventory/batches/lot-a', ['batches.view'], true, 'Product batches')
  })
  it('denies create with batches.view alone', async () => { await check('/inventory/batches/new', ['batches.view'], false, 'Create batch') })
  it('allows create with batches.create', async () => { await check('/inventory/batches/new', ['batches.create'], true, 'Create batch') })
  it('denies edit with batches.view alone', async () => { await check('/inventory/batches/lot-a/edit', ['batches.view'], false, 'Edit batch') })
  it('allows edit with batches.update', async () => { await check('/inventory/batches/lot-a/edit', ['batches.update'], true, 'Edit batch') })
  it('denies every batch route when BatchExpiry is disabled', async () => {
    state.enabled = false
    for (const path of ['/inventory/batches', '/inventory/batches/lot-a', '/inventory/batches/new', '/inventory/batches/lot-a/edit']) {
      await check(path, ['batches.view', 'batches.create', 'batches.update'], false, 'Product batches')
    }
  })
})
