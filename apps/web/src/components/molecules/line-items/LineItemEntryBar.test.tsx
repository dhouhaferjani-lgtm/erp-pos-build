import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { LineItemEntryBar, type LineEntryAddRequest } from './LineItemEntryBar'

const mockApiGet = vi.hoisted(() => vi.fn<(...args: unknown[]) => unknown>())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: (...args: unknown[]): unknown => mockApiGet(...args),
  }
})

function renderEntryBar(onAddProduct: (request: LineEntryAddRequest) => void = vi.fn()) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <LineItemEntryBar
        labels={{
          search: 'Search or scan a product',
          browseCatalog: 'Browse catalog',
          loading: 'Loading products',
          empty: 'No products found',
        }}
        onAddProduct={onAddProduct}
        onBrowseCatalog={() => undefined}
      />
    </QueryClientProvider>,
  )
}

describe('LineItemEntryBar', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
  })

  it('adds the highlighted search result with Enter and keeps the entry field ready', async () => {
    const user = userEvent.setup()
    const onAddProduct = vi.fn<(request: LineEntryAddRequest) => void>()
    mockApiGet.mockResolvedValue({
      data: [
        {
          id: 'product-1',
          sku: 'PARA-1',
          name: 'Paracetamol 500',
          quantity_decimals: 0,
          requires_batch_tracking: false,
        },
      ],
    })

    renderEntryBar(onAddProduct)

    const input = screen.getByRole('combobox', { name: 'Search or scan a product' })
    await user.type(input, 'para')

    expect(await screen.findByRole('option', { name: /PARA-1 Paracetamol 500/i })).toHaveAttribute('aria-selected', 'true')

    await user.keyboard('{Enter}')

    expect(onAddProduct).toHaveBeenCalledTimes(1)
    const request = onAddProduct.mock.calls[0][0]
    expect(request).toMatchObject({
      source: 'search',
      variantId: null,
    })
    expect(request.product).toMatchObject({ id: 'product-1', sku: 'PARA-1' })
    expect(input).toHaveValue('')
    expect(input).toHaveFocus()
  })

  it('resolves a variant scanner code and emits a direct variant add request', async () => {
    const onAddProduct = vi.fn<(request: LineEntryAddRequest) => void>()
    mockApiGet.mockResolvedValue({
      kind: 'variant',
      matched_code_type: 'variant_barcode',
      product: {
        id: 'product-1',
        sku: 'TSHIRT',
        name: 'T-Shirt',
        quantity_decimals: 0,
        requires_batch_tracking: false,
      },
      variant: {
        id: 'variant-red',
        sku: 'TSHIRT-RED',
        name_suffix: 'Red',
      },
    })

    renderEntryBar(onAddProduct)

    window.dispatchEvent(new KeyboardEvent('keydown', { key: '9' }))
    window.dispatchEvent(new KeyboardEvent('keydown', { key: '9' }))
    window.dispatchEvent(new KeyboardEvent('keydown', { key: '9' }))
    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))

    await waitFor(() => {
      expect(onAddProduct).toHaveBeenCalledTimes(1)
      const request = onAddProduct.mock.calls[0][0]
      expect(request).toMatchObject({
        source: 'scan',
        code: '999',
        matchedCodeType: 'variant_barcode',
        variantId: 'variant-red',
      })
      expect(request.product).toMatchObject({ id: 'product-1' })
    })
    expect(mockApiGet).toHaveBeenCalledWith('/line-entry/resolve-code', expect.objectContaining({ code: '999' }))
  })
})
