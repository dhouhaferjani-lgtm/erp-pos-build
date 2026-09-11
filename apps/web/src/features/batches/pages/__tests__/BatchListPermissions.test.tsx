import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Batch } from '../../types'
import { BatchListPage } from '../BatchListPage'

const state = vi.hoisted(() => ({
  permissions: [] as string[], populated: true,
  batch: { product: { id: 'product-a', name: 'Product A', sku: 'SKU-A', quantity_decimals: 4 }, id: 1, product_id: 'product-a', variant_id: null, manufacturing_date: null,
    recall_reason: null, recalled_at: null, notes: null, can_be_sold: true,
    created_at: '2026-09-01T00:00:00Z', updated_at: '2026-09-01T00:00:00Z', total_quantity: '3.1234', uuid: 'lot-a', batch_number: 'LOT-A', is_active: true as boolean, is_recalled: false, is_expired: false,
    expiry_status: 'OK', expiry_date: null, days_until_expiry: null, available_quantity: '3.1234' } satisfies Batch,
}))
vi.mock('@/hooks/usePermissions', () => ({ usePermissions: () => ({ hasPermission: (permission: string) => state.permissions.includes(permission) }) }))
vi.mock('@/hooks/useCurrency', () => ({ useCurrency: () => ({ decimals: 3 }) }))
vi.mock('../../hooks/useBatches', () => ({
  useBatches: () => ({ data: { data: state.populated ? [state.batch] : [] } }),
}))
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string, fallback?: unknown) => typeof fallback === 'string' ? fallback : key }) }))

function list() { return render(<MemoryRouter><BatchListPage /></MemoryRouter>) }
beforeEach(() => {
  state.permissions = []
  state.populated = true
  Object.assign(state.batch, { is_active: true, is_recalled: false, is_expired: false })
})
afterEach(cleanup)

describe('W-LOT-A-1a batch list permissions', () => {
  it('renders API quantity strings at product unit precision instead of currency precision', () => {
    list()
    expect(screen.getByText('3.1234')).toBeInTheDocument()
  })
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
})
