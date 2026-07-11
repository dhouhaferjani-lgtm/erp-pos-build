import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CouponFormPage } from './CouponFormPage'

const mockCreateMutate = vi.hoisted(() => vi.fn())
const mockUpdateMutate = vi.hoisted(() => vi.fn())

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

vi.mock('@/hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

vi.mock('../hooks/useCoupons', () => ({
  useCoupon: () => ({ data: undefined, isLoading: false }),
  useCreateCoupon: () => ({ mutate: mockCreateMutate, isPending: false }),
  useUpdateCoupon: () => ({ mutate: mockUpdateMutate, isPending: false }),
}))

vi.mock('@/features/products/components/ProductSelector', () => ({
  ProductSelector: ({
    label,
    onChange,
  }: {
    label: string
    onChange: (value: string[]) => void
  }) => (
    <button type="button" onClick={() => { onChange(['prod-1', 'prod-2']) }}>
      {label}
    </button>
  ),
}))

vi.mock('@/features/categories/components/CategorySelector', () => ({
  CategorySelector: ({
    label,
    onChange,
  }: {
    label: string
    onChange: (value: number[]) => void
  }) => (
    <button type="button" onClick={() => { onChange([12, 34]) }}>
      {label}
    </button>
  ),
}))

function renderPage() {
  render(
    <MemoryRouter>
      <CouponFormPage />
    </MemoryRouter>,
  )
}

function controlInField(label: string, selector: string): HTMLElement {
  for (const labelNode of screen.getAllByText(label)) {
    if (labelNode.tagName !== 'LABEL') continue
    const container = labelNode.closest('div')
    if (!container) continue
    const control = container.querySelector(selector)
    if (control instanceof HTMLElement) return control
  }
  throw new Error(`No control found for ${label}`)
}

describe('CouponFormPage payload identity', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('submits the complete create payload with nullable fields and category ids normalized', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.type(controlInField('coupons:fields.name', 'input'), 'Launch coupon')
    await user.type(controlInField('coupons:fields.code', 'input'), 'launch-25')
    await user.selectOptions(controlInField('coupons:fields.type', 'select'), 'single_use')
    await user.selectOptions(controlInField('coupons:fields.discountType', 'select'), 'fixed')
    await user.type(controlInField('coupons:fields.discountValue', 'input'), '25.125')
    await user.type(controlInField('coupons:fields.maxDiscountAmount', 'input'), '40.500')
    await user.type(controlInField('coupons:fields.minimumOrderAmount', 'input'), '100.000')
    await user.type(controlInField('coupons:fields.maxUses', 'input'), '15')
    await user.type(controlInField('coupons:fields.maxUsesPerCustomer', 'input'), '2')
    await user.click(screen.getAllByRole('checkbox')[0])
    await user.click(screen.getByRole('button', { name: 'coupons:fields.qualifyingProducts' }))
    await user.click(screen.getByRole('button', { name: 'coupons:fields.qualifyingCategories' }))
    await user.type(controlInField('coupons:fields.startsAt', 'input'), '2026-07-12T08:00')
    await user.type(controlInField('coupons:fields.expiresAt', 'input'), '2026-08-01T23:30')
    await user.clear(controlInField('coupons:fields.stackingGroup', 'input'))
    await user.type(controlInField('coupons:fields.stackingGroup', 'input'), 'launch-stack')
    await user.click(screen.getAllByRole('checkbox')[1])

    await user.click(screen.getByRole('button', { name: 'coupons:createCoupon' }))

    await waitFor(() => {
      expect(mockCreateMutate).toHaveBeenCalledWith({
        name: 'Launch coupon',
        code: 'launch-25',
        type: 'single_use',
        discount_type: 'fixed',
        discount_value: '25.125',
        max_discount_amount: '40.5',
        minimum_order_amount: '100',
        is_single_use: true,
        max_uses: 15,
        max_uses_per_customer: 2,
        is_exclusive: true,
        stacking_group: 'launch-stack',
        starts_at: '2026-07-12T08:00',
        expires_at: '2026-08-01T23:30',
        qualifying_product_ids: ['prod-1', 'prod-2'],
        qualifying_category_ids: ['12', '34'],
      }, expect.any(Object))
    })
  })
})
