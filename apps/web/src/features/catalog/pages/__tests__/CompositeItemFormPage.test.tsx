import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CompositeItemFormPage } from '../CompositeItemFormPage'

const mockNavigate = vi.fn()

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

let mockParams: { id?: string } = { id: 'composite-1' }
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => mockParams,
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

let mockHasPermission = vi.fn().mockReturnValue(true)
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

const mockItem = {
  id: 'composite-1',
  code: 'ESP',
  name: 'Espresso',
  category_id: null,
  vertical_type: 'fnb',
  base_price: '3.50',
  manual_cost: null,
  production_type: 'made_to_order',
  pricing_mode: 'standard',
  tax_rate: '7.00',
  is_active: true,
  is_available: true,
  image_url: null,
  active_recipe: null,
  variants: [],
  modifier_groups: null,
  recipe_cost: null,
  margin_percentage: null,
}

const mockDeleteMutate = vi.fn().mockResolvedValue(undefined)
const mockUpdateMutate = vi.fn()

vi.mock('../../hooks/useCompositeItems', () => ({
  useCompositeItem: () => ({ data: mockItem, isLoading: false }),
  useCreateCompositeItem: () => ({ mutate: vi.fn(), isPending: false }),
  useUpdateCompositeItem: () => ({ mutate: mockUpdateMutate, isPending: false }),
  useDeleteCompositeItem: () => ({ mutateAsync: mockDeleteMutate, isPending: false }),
  useCompositeItemAvailability: () => ({ data: undefined, isLoading: false }),
}))

vi.mock('../../hooks/useRecipes', () => ({
  useCreateRecipe: () => ({ mutate: vi.fn(), isPending: false }),
}))

vi.mock('../../hooks/useVerticalLabels', () => ({
  useVerticalLabels: () => (key: string) => key,
  companyVerticalToCatalog: () => 'fnb',
}))

vi.mock('@/contexts', () => ({
  useCompanyConfig: () => ({ config: { vertical: 'fnb', currency: 'TND' }, hasModule: () => false }),
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return { ...actual, useQuery: () => ({ data: [], isLoading: false }) }
})

vi.mock('@/components/molecules/TaxConfigurationField', () => ({
  TaxConfigurationField: () => null,
}))

describe('CompositeItemFormPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockParams = { id: 'composite-1' }
    mockHasPermission = vi.fn().mockReturnValue(true)
    mockUpdateMutate.mockReset()
  })

  it('shows the delete button when editing an existing item', () => {
    render(<CompositeItemFormPage />)
    expect(screen.getByRole('button', { name: /common:delete/i })).toBeInTheDocument()
  })

  it('does not show the delete button when creating a new item', () => {
    mockParams = { id: 'new' }
    render(<CompositeItemFormPage />)
    expect(screen.queryByRole('button', { name: /common:delete/i })).not.toBeInTheDocument()
  })

  it('calls the delete mutation on confirm and navigates back to the list', async () => {
    render(<CompositeItemFormPage />)
    fireEvent.click(screen.getByRole('button', { name: /common:delete/i }))

    const confirmButton = await screen.findByRole('button', { name: /common:confirm/i })
    fireEvent.click(confirmButton)

    await waitFor(() => {
      expect(mockDeleteMutate).toHaveBeenCalledWith('composite-1')
      expect(mockNavigate).toHaveBeenCalledWith('/catalog/composite-items')
    })
  })

  it('shows an error toast and keeps the dialog open when delete fails', async () => {
    mockDeleteMutate.mockRejectedValueOnce(new Error('Boom'))
    const { toast } = await import('sonner')

    render(<CompositeItemFormPage />)
    fireEvent.click(screen.getByRole('button', { name: /common:delete/i }))

    const confirmButton = await screen.findByRole('button', { name: /common:confirm/i })
    fireEvent.click(confirmButton)

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalled()
      expect(mockNavigate).not.toHaveBeenCalled()
    })
  })

  it('hides the delete button when user lacks composite-items.delete permission', () => {
    mockHasPermission = vi.fn().mockReturnValue(false)
    render(<CompositeItemFormPage />)
    expect(screen.queryByRole('button', { name: /common:delete/i })).not.toBeInTheDocument()
  })

  it('sends base_price as a string (not a JS number) in the update payload', async () => {
    const user = userEvent.setup()
    render(<CompositeItemFormPage />)

    // The price field is rendered as a MoneyInput (type=number); find it by label
    const priceInput = screen.getByLabelText(/catalog:basePrice/i)
    await user.clear(priceInput)
    await user.type(priceInput, '12.5')

    const saveButton = screen.getByRole('button', { name: /common:save/i })
    await user.click(saveButton)

    await waitFor(() => {
      expect(mockUpdateMutate).toHaveBeenCalled()
      const [{ data }] = mockUpdateMutate.mock.calls[0] as [{ id: string; data: Record<string, unknown> }]
      // Precision contract: monetary values MUST be strings, never JS numbers —
      // coercing to Number() would silently truncate TND's 3rd decimal place.
      expect(typeof data['base_price']).toBe('string')
      expect(data['base_price']).toBe('12.5')
    })
  })
})
