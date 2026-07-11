import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ReplenishmentCapturePage } from '../pages/ReplenishmentCapturePage'

const mutateAsync = vi.fn()
let mutationError: Error | null = null

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn() },
}))

vi.mock('@/features/locations/hooks/useLocations', () => ({
  useLocations: () => ({
    data: [
      { id: 'location-active', name: 'Shop A', isActive: true },
      { id: 'location-inactive', name: 'Closed shop', isActive: false },
    ],
  }),
}))

vi.mock('../api/queries', () => ({
  useCaptureReplenishment: () => ({
    mutateAsync,
    error: mutationError,
    isPending: false,
  }),
}))

vi.mock('@/components/molecules/line-items/LineItemEntryBar', () => ({
  LineItemEntryBar: ({ onAddProduct, onBeforeAdd }: {
    onAddProduct: (product: { id: string; name: string }, meta: { variantId: string }) => void
    onBeforeAdd?: (source: 'search') => boolean
  }) => (
    <button
      type="button"
      onClick={() => {
        if (onBeforeAdd?.('search') !== false) {
          onAddProduct({ id: 'product-1', name: 'Serum' }, { variantId: 'variant-1' })
        }
      }}
    >
      add-product
    </button>
  ),
}))

vi.mock('../components/ReplenishmentStatusBadge', () => ({
  ReplenishmentStatusBadge: ({ status }: { status: string }) => (
    <span data-testid={`feature-status-${status}`}>{status}</span>
  ),
}))

describe('ReplenishmentCapturePage', () => {
  beforeEach(() => {
    mutateAsync.mockReset()
    mutationError = null
  })

  it('vetoes adding a product until an active location is selected', async () => {
    const user = userEvent.setup()
    render(<ReplenishmentCapturePage />)

    expect(screen.queryByRole('option', { name: 'Closed shop' })).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'add-product' }))

    expect(mutateAsync).not.toHaveBeenCalled()
  })

  it('captures the selected product and variant with the quantity string unchanged', async () => {
    mutateAsync.mockResolvedValue({
      id: 'request-1',
      product_name: 'Serum',
      status: 'pending',
    })
    const user = userEvent.setup()
    render(<ReplenishmentCapturePage />)

    await user.selectOptions(screen.getByRole('combobox', { name: 'capture.location' }), 'location-active')
    await user.type(screen.getByRole('spinbutton', { name: 'capture.quantity' }), '2.5')
    await user.type(screen.getByRole('textbox', { name: 'capture.note' }), 'Shelf empty')
    await user.click(screen.getByRole('button', { name: 'add-product' }))

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        location_id: 'location-active',
        product_id: 'product-1',
        variant_id: 'variant-1',
        requested_qty: '2.5',
        note: 'Shelf empty',
      })
    })
    expect(screen.getByText('Serum')).toBeInTheDocument()
    expect(screen.getByTestId('feature-status-pending')).toBeInTheDocument()
  })

  it('surfaces a forbidden response through QueryError', () => {
    mutationError = Object.assign(new Error('Forbidden'), {
      response: { status: 403 },
    })

    render(<ReplenishmentCapturePage />)

    expect(screen.getByText('Forbidden')).toBeInTheDocument()
  })
})
