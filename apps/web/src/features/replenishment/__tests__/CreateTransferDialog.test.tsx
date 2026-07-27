import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CreateTransferDialog } from '../components/CreateTransferDialog'
import { makeReplenishmentLine } from './replenishmentTestFixtures'

const mutateAsync = vi.fn()
const getProductStock = vi.hoisted(() => vi.fn())
let permissionAllowed = true

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))
vi.mock('@/components/auth', () => ({
  RequirePermission: ({ children }: { children: React.ReactNode }) => permissionAllowed ? children : null,
}))
vi.mock('@/features/locations/hooks/useLocations', () => ({
  useLocations: () => ({ data: [
    { id: 'warehouse', name: 'Warehouse', type: 'warehouse', isActive: true },
    { id: 'shop-a', name: 'Shop A', type: 'shop', isActive: true },
    { id: 'shop-b', name: 'Shop B', type: 'shop', isActive: true },
  ] }),
}))
vi.mock('@/features/products/api/productStock', () => ({ getProductStock }))
vi.mock('../api/queries', () => ({
  useCreateTransferAction: () => ({ mutateAsync, isPending: false }),
}))

const selected = [
  makeReplenishmentLine({ id: 'request-a', location_id: 'shop-a', location_name: 'Shop A', requested_qty: null }),
  makeReplenishmentLine({ id: 'request-b', location_id: 'shop-b', location_name: 'Shop B', requested_qty: '2.5000' }),
]

function renderDialog(lines = selected) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <CreateTransferDialog selected={lines} isOpen onClose={vi.fn()} />
    </QueryClientProvider>,
  )
}

describe('CreateTransferDialog', () => {
  beforeEach(() => {
    permissionAllowed = true
    mutateAsync.mockReset().mockResolvedValue({ transfer_ids: ['transfer-a', 'transfer-b'] })
    getProductStock.mockReset().mockImplementation((productId: string) => Promise.resolve({
      locations: productId === 'product-1'
        ? [{ location_id: 'shop-a', available: '4.0000', min_quantity: '5.0000', max_quantity: '20.0000' }]
        : [],
      totals: {},
    }))
  })

  it('gates required fields and submits string quantities grouped by destination', async () => {
    const user = userEvent.setup()
    renderDialog()

    const submit = screen.getByRole('button', { name: 'dialog.submit' })
    expect(submit).toBeDisabled()
    expect(screen.getByText('dialog.transfer_preview')).toBeInTheDocument()
    expect(await screen.findByDisplayValue('16.0000')).toBeInTheDocument()
    await user.selectOptions(screen.getByRole('combobox', { name: 'dialog.source' }), 'warehouse')
    expect(submit).toBeEnabled()
    await user.click(submit)

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledWith({
        source_location_id: 'warehouse',
        lines: [
          { request_id: 'request-a', quantity: '16.0000' },
          { request_id: 'request-b', quantity: '2.5000' },
        ],
      })
    })
  })

  it('falls back to one when a quantity-less line has no min/max stock', async () => {
    const noLevels = makeReplenishmentLine({
      id: 'request-no-levels',
      product_id: 'product-no-levels',
      requested_qty: null,
    })

    renderDialog([noLevels])

    expect(await screen.findByDisplayValue('1')).toBeInTheDocument()
  })

  it('derives QuantityInput precision and min from quantity_decimals (piece → 0)', async () => {
    const pieceSelected = [makeReplenishmentLine({ id: 'request-a', location_id: 'shop-a', location_name: 'Shop A', requested_qty: '3.0000', quantity_decimals: 0 })]
    renderDialog(pieceSelected)
    const input = await screen.findByRole('spinbutton')
    expect(input).toHaveAttribute('min', '1')
    expect(input).toHaveAttribute('step', '1')
  })

  it('derives QuantityInput precision and min from quantity_decimals (3 decimals)', async () => {
    const kgSelected = [makeReplenishmentLine({ id: 'request-a', location_id: 'shop-a', location_name: 'Shop A', requested_qty: '3.0000', quantity_decimals: 3 })]
    renderDialog(kgSelected)
    const input = await screen.findByRole('spinbutton')
    expect(input).toHaveAttribute('min', '0.001')
    expect(input).toHaveAttribute('step', '0.001')
  })

  it('does not render without replenishment.process permission', () => {
    permissionAllowed = false
    renderDialog()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })
})
