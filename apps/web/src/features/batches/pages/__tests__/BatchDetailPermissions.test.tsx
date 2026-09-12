import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Batch } from '../../types'
import { BatchDetailPage } from '../BatchDetailPage'

const state = vi.hoisted(() => ({
  permissions: [] as string[],
  batch: { product: { id: 'product-a', name: 'Product A', sku: 'SKU-A', quantity_decimals: 4 }, id: 1, product_id: 'product-a', variant_id: null, manufacturing_date: null,
    recall_reason: null, recalled_at: null, notes: null, can_be_sold: true,
    created_at: '2026-09-01T00:00:00Z', updated_at: '2026-09-01T00:00:00Z', total_quantity: '3.1234', uuid: 'lot-a', batch_number: 'LOT-A', is_active: true as boolean, is_recalled: false, is_expired: false,
    expiry_status: 'OK', expiry_date: null, days_until_expiry: null, available_quantity: '3.1234' } satisfies Batch,
}))
vi.mock('@/hooks/usePermissions', () => ({ usePermissions: () => ({ hasPermission: (permission: string) => state.permissions.includes(permission) }) }))
vi.mock('@/hooks/useCurrency', () => ({ useCurrency: () => ({ decimals: 3 }) }))
vi.mock('../../hooks/useBatches', () => ({
  useBatch: () => ({ data: state.batch }), useBatchStock: () => ({ data: [] }),
  useDeleteBatch: () => ({ mutate: vi.fn() }), useRecallBatch: () => ({ mutate: vi.fn() }),
}))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string, fallback?: unknown) => typeof fallback === 'string' ? fallback : key }) }))
vi.mock('@/components/ui/ConfirmDialog', () => ({ ConfirmDialog: () => null }))

function detail() { return render(<MemoryRouter><BatchDetailPage /></MemoryRouter>) }
beforeEach(() => {
  state.permissions = []
  Object.assign(state.batch, { is_active: true, is_recalled: false, is_expired: false })
})
afterEach(cleanup)

describe('W-LOT-A-1a batch detail permissions', () => {
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
