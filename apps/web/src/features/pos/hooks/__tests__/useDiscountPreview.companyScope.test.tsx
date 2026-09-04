import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { resetAuth, seedAuth } from '@/test/seedAuth'

import type { DiscountBreakdownData } from '../../api/discountApi'
import type { CartItem } from '../../molecules/CartLineItem'
import { useDiscountPreview, type DiscountPreviewInput } from '../useDiscountPreview'

const mockPreviewDiscounts = vi.hoisted(() => vi.fn<() => Promise<DiscountBreakdownData>>())

vi.mock('../../api/discountApi', async () => {
  const actual = await vi.importActual<typeof import('../../api/discountApi')>('../../api/discountApi')
  return { ...actual, previewDiscounts: mockPreviewDiscounts }
})

// The preview's currency scale is irrelevant to scope leakage; pinning it keeps
// the test off CompanyConfigProvider's network path.
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
}))

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const cartItem: CartItem = {
  id: 'line-1',
  product: { id: 'product-1', name: 'Widget', sku: 'W-1', price: '10.000' },
  quantity: 1,
  unit_price: '10.000',
  line_total: '10.000',
}

const previewInput: DiscountPreviewInput = {
  cartItems: [cartItem],
  subtotal: '10.000',
}

function breakdown(amount: string, label: string): DiscountBreakdownData {
  return {
    lines: [
      {
        source: 'promotion',
        stacking_group: 'default',
        is_exclusive: false,
        priority: 1,
        discount_amount: amount,
        label,
        reference_id: null,
        applies_to: 'transaction',
      },
    ],
    total_transaction_discount: amount,
    line_discounts: {},
  }
}

describe('useDiscountPreview company scope', () => {
  beforeEach(() => {
    mockPreviewDiscounts.mockReset()
    seedAuth({ tenantId: 'tenant-1', companyId: 'company-1' })
  })

  afterEach(() => {
    // Unmount BEFORE clearing the stores: vitest runs this hook ahead of RTL's
    // auto-cleanup, so a bare `resetAuth()` would push a store update into a
    // still-mounted tree outside `act`.
    cleanup()
    resetAuth()
  })

  it('never hands back the previous company preview while the new company preview is in flight', async () => {
    mockPreviewDiscounts
      .mockResolvedValueOnce(breakdown('1.000', 'Company one promo'))
      // The second company's preview stays in flight for the whole assertion
      // window — exactly the gap in which a `keepPreviousData` placeholder
      // would surface company one's discount against company two's cart.
      .mockImplementationOnce(() => new Promise<DiscountBreakdownData>(() => { /* never settles */ }))

    const { result } = renderHook(() => useDiscountPreview(previewInput), { wrapper })

    await waitFor(
      () => { expect(result.current.breakdown).not.toBeNull() },
      { timeout: 3000 },
    )
    expect(result.current.totalSavings).toBe('1.000')

    act(() => {
      useCompanyStore.setState({ currentCompanyId: 'company-2' })
    })

    await waitFor(() => { expect(mockPreviewDiscounts).toHaveBeenCalledTimes(2) })

    expect(result.current.breakdown).toBeNull()
    expect(result.current.autoPromotions).toEqual([])
    expect(result.current.totalSavings).toBe('0.000')
  })

  it('never hands back the previous tenant preview while the new tenant preview is in flight', async () => {
    mockPreviewDiscounts
      .mockResolvedValueOnce(breakdown('2.000', 'Tenant one promo'))
      .mockImplementationOnce(() => new Promise<DiscountBreakdownData>(() => { /* never settles */ }))

    const { result } = renderHook(() => useDiscountPreview(previewInput), { wrapper })

    await waitFor(
      () => { expect(result.current.breakdown).not.toBeNull() },
      { timeout: 3000 },
    )

    act(() => {
      const user = useAuthStore.getState().user
      if (user === null) throw new Error('expected a seeded user')
      useAuthStore.setState({ user: { ...user, tenant_id: 'tenant-2' } })
    })

    await waitFor(() => { expect(mockPreviewDiscounts).toHaveBeenCalledTimes(2) })

    expect(result.current.breakdown).toBeNull()
    expect(result.current.totalSavings).toBe('0.000')
  })
})
