import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BatchListPage } from '../BatchListPage'
import { BatchDetailPage } from '../BatchDetailPage'

const state = vi.hoisted(() => ({
  permissions: [] as string[], populated: true,
  batch: { uuid: 'lot-a', batch_number: 'LOT-A', is_active: true, is_recalled: false, is_expired: false,
    expiry_status: 'OK', expiry_date: null, days_until_expiry: null, available_quantity: '2.0000' },
}))
vi.mock('@/hooks/usePermissions', () => ({ usePermissions: () => ({ hasPermission: (permission: string) => state.permissions.includes(permission) }) }))
vi.mock('@/hooks/useCurrency', () => ({ useCurrency: () => ({ decimals: 3 }) }))
vi.mock('../../hooks/useBatches', () => ({
  useBatches: () => ({ data: { data: state.populated ? [state.batch] : [] } }),
  useBatch: () => ({ data: state.batch }), useBatchStock: () => ({ data: [] }),
  useDeleteBatch: () => ({ mutate: vi.fn() }), useRecallBatch: () => ({ mutate: vi.fn() }),
}))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string, fallback?: unknown) => typeof fallback === 'string' ? fallback : key }) }))
vi.mock('@/components/ui/ConfirmDialog', () => ({ ConfirmDialog: () => null }))

function list() { return render(<MemoryRouter><BatchListPage /></MemoryRouter>) }
function detail() { return render(<MemoryRouter><BatchDetailPage /></MemoryRouter>) }
beforeEach(() => {
  state.permissions = []
  state.populated = true
  Object.assign(state.batch, { is_active: true, is_recalled: false, is_expired: false })
})
afterEach(cleanup)

describe('W-LOT-A-1a batch action permissions', () => {
  it('hides the populated-list header create link without batches.create', () => {
    list()
    expect(screen.queryAllByRole('link', { name: /add batch/i })).toHaveLength(0)
  })
  it('hides the empty-state create link without batches.create', () => {
    state.populated = false
    list()
    expect(screen.queryAllByRole('link', { name: /add batch/i })).toHaveLength(0)
  })
  it('shows both create affordances only with batches.create', () => {
    state.populated = false
    state.permissions = ['batches.create']
    list()
    expect(screen.getAllByRole('link', { name: /add batch/i })).toHaveLength(2)
  })
  it('hides edit without batches.update', () => {
    detail()
    expect(screen.queryByRole('link', { name: /edit/i })).not.toBeInTheDocument()
  })
  it('hides delete without batches.delete', () => {
    detail()
    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument()
  })
  it('hides recall from the revised manager payload', () => {
    state.permissions = ['batches.view', 'batches.recall.request']
    detail()
    expect(screen.queryByRole('button', { name: /recall/i })).not.toBeInTheDocument()
  })
  it('shows recall to an eligible general manager with batches.recall', () => {
    state.permissions = ['batches.recall']
    detail()
    expect(screen.getByRole('button', { name: /recall/i })).toBeInTheDocument()
  })
  it('retains state-based action suppression when permission exists', () => {
    state.permissions = ['batches.recall', 'batches.update', 'batches.delete']
    state.batch.is_active = false
    detail()
    expect(screen.queryByRole('button', { name: /recall|delete/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /edit/i })).not.toBeInTheDocument()
  })
})
