import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { RecipeLineEditor } from './RecipeLineEditor'
import type { RecipeData } from '../types/compositeItem'

const idleMutation = { mutate: vi.fn(), isPending: false }

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn() },
}))

vi.mock('../hooks/useVerticalLabels', () => ({
  useVerticalLabels: () => (key: string) => key,
}))

vi.mock('@/components/molecules/line-items', () => ({
  ProductLineSelect: () => null,
}))

vi.mock('./CompositeItemSearchSelect', () => ({
  CompositeItemSearchSelect: () => null,
}))

vi.mock('../hooks/useRecipes', () => ({
  useCreateRecipeLine: () => idleMutation,
  useUpdateRecipeLine: () => idleMutation,
  useDeleteRecipeLine: () => idleMutation,
  useActivateRecipe: () => idleMutation,
  useUpdateRecipe: () => idleMutation,
  useCalculateRecipeCost: () => ({
    isPending: false,
    mutate: (_recipeId: string, options: { onSuccess: (data: unknown) => void }) => {
      options.onSuccess({
        total_cost: '2.5000',
        lines: [{
          component_name: 'Precision ingredient',
          quantity: '1.25',
          quantity_decimals: 3,
          unit_cost: '2.0000',
          line_cost: '2.5000',
          percent_of_total: '100.00',
        }],
      })
    },
  }),
}))

const recipe: RecipeData = {
  id: 'recipe-1',
  composite_item_id: 'item-1',
  version: 1,
  version_name: null,
  is_active: true,
  yield_quantity: '1.0000',
  yield_unit_id: null,
  calculated_cost: null,
  prep_time_minutes: null,
  cook_time_minutes: null,
  total_time_minutes: null,
  instructions: null,
  lines: [],
  created_at: '2026-07-01T00:00:00Z',
  updated_at: null,
}

describe('RecipeLineEditor', () => {
  it('formats cost-breakdown quantities with the served recipe-line scale', () => {
    render(<RecipeLineEditor recipe={recipe} compositeItemId="item-1" />)

    fireEvent.click(screen.getByRole('button', { name: 'catalog:calculateCost' }))

    expect(screen.getByText('1.250')).toBeInTheDocument()
  })
})
