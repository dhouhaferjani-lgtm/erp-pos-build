import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PromotionFormPage } from './PromotionFormPage'

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

vi.mock('../hooks/usePromotions', () => ({
  usePromotion: () => ({ data: undefined, isLoading: false }),
  useCreatePromotion: () => ({ mutate: mockCreateMutate, isPending: false }),
  useUpdatePromotion: () => ({ mutate: mockUpdateMutate, isPending: false }),
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
      <PromotionFormPage />
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

describe('PromotionFormPage payload identity', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('submits the complete create payload without coercing decimal strings', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.type(controlInField('promotions:fields.name', 'input'), 'Category weekend')
    await user.type(controlInField('promotions:fields.description', 'textarea'), 'Applies to selected categories')
    await user.selectOptions(controlInField('promotions:fields.type', 'select'), 'category_discount')
    await user.clear(controlInField('promotions:fields.priority', 'input'))
    await user.type(controlInField('promotions:fields.priority', 'input'), '4')
    await user.selectOptions(controlInField('promotions:fields.discountType', 'select'), 'fixed')
    await user.type(controlInField('promotions:fields.discountValue', 'input'), '12.345')
    await user.type(controlInField('promotions:fields.maxDiscountAmount', 'input'), '20.500')
    await user.selectOptions(controlInField('promotions:fields.appliesTo', 'select'), 'qualifying_items')
    await user.click(screen.getByRole('button', { name: 'promotions:fields.categoryIds' }))
    await user.type(controlInField('promotions:fields.startsAt', 'input'), '2026-07-12T09:00')
    await user.type(controlInField('promotions:fields.endsAt', 'input'), '2026-07-19T22:30')
    await user.type(controlInField('promotions:fields.timeFrom', 'input'), '09:15')
    await user.type(controlInField('promotions:fields.timeUntil', 'input'), '21:45')
    await user.click(screen.getByRole('button', { name: 'promotions:days.2' }))
    await user.click(screen.getByRole('button', { name: 'promotions:days.5' }))
    await user.clear(controlInField('promotions:fields.stackingGroup', 'input'))
    await user.type(controlInField('promotions:fields.stackingGroup', 'input'), 'weekend-category')
    await user.type(controlInField('promotions:fields.usageLimit', 'input'), '25')
    await user.click(screen.getByRole('checkbox'))

    await user.click(screen.getByRole('button', { name: 'common:create' }))

    await waitFor(() => {
      expect(mockCreateMutate).toHaveBeenCalledWith({
        name: 'Category weekend',
        description: 'Applies to selected categories',
        type: 'category_discount',
        priority: 4,
        is_exclusive: true,
        stacking_group: 'weekend-category',
        starts_at: '2026-07-12T09:00',
        ends_at: '2026-07-19T22:30',
        days_of_week: [2, 5],
        time_from: '09:15',
        time_until: '21:45',
        discount_type: 'fixed',
        discount_value: '12.345',
        max_discount_amount: '20.5',
        applies_to: 'qualifying_items',
        usage_limit: 25,
        conditions: {
          category_ids: [12, 34],
        },
      }, expect.any(Object))
    })
  })
})
