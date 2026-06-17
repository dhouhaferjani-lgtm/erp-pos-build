import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { CompositeItemListPage } from '../CompositeItemListPage'

vi.mock('react-i18next', () => ({
  // Mirror i18next: a string 2nd arg is a default value; an object 2nd arg is
  // interpolation options (OffsetPagination passes `{ from, to, total }`). A
  // naive `(key) => key` mock crashes when handed the object.
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; [key: string]: unknown }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('../../hooks/useVerticalLabels', () => ({
  useCompanyVerticalLabels: () => (key: string) => key,
}))

const mockPaginatedData = {
  data: [
    {
      id: '1', code: 'ESP', name: 'Espresso', vertical_type: 'fnb', base_price: '3.5000',
      production_type: 'made_to_order', tax_rate: '7.00', is_active: true, is_available: true,
      category_id: null, category_name: null, image_url: null, display_order: 0,
      active_recipe: null, variants: [], modifier_groups: null,
      created_at: '2026-01-01T00:00:00Z', updated_at: null,
    },
    {
      id: '2', code: 'LAT', name: 'Latte', vertical_type: 'fnb', base_price: '5.5000',
      production_type: 'made_to_order', tax_rate: '7.00', is_active: true, is_available: true,
      category_id: null, category_name: null, image_url: null, display_order: 1,
      active_recipe: null, variants: [], modifier_groups: null,
      created_at: '2026-01-01T00:00:00Z', updated_at: null,
    },
  ],
  meta: { current_page: 1, last_page: 2, per_page: 25, total: 30, from: 1, to: 25 },
}

let mockUseQueryReturn: { data: typeof mockPaginatedData | undefined; isLoading: boolean } = {
  data: mockPaginatedData,
  isLoading: false,
}

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return { ...actual, useQuery: () => mockUseQueryReturn }
})

const mockDeleteMutate = vi.fn().mockResolvedValue(undefined)

vi.mock('../../hooks/useCompositeItems', () => ({
  useCompositeItems: () => mockUseQueryReturn,
  useDeleteCompositeItem: () => ({
    mutateAsync: mockDeleteMutate,
    isPending: false,
  }),
}))

vi.mock('sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

let mockHasPermission = vi.fn().mockReturnValue(true)
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: mockHasPermission }),
}))

describe('CompositeItemListPage', () => {
  beforeEach(() => {
    mockUseQueryReturn = { data: mockPaginatedData, isLoading: false }
    mockHasPermission = vi.fn().mockReturnValue(true)
    mockNavigate.mockReset()
  })

  it('renders composite items table with data', () => {
    render(<CompositeItemListPage />)
    expect(screen.getByText('Espresso')).toBeInTheDocument()
    expect(screen.getByText('Latte')).toBeInTheDocument()
    expect(screen.getByText('ESP')).toBeInTheDocument()
  })

  it('renders empty state when no items', () => {
    mockUseQueryReturn = {
      data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: 0, to: 0 } },
      isLoading: false,
    }
    render(<CompositeItemListPage />)
    expect(screen.getByText('catalog:noCompositeItems')).toBeInTheDocument()
  })

  it('navigates to the create page when the create button is clicked', () => {
    render(<CompositeItemListPage />)
    const createButton = screen.getByRole('button', { name: /catalog:createCompositeItem/i })
    fireEvent.click(createButton)
    expect(mockNavigate).toHaveBeenCalledWith('/catalog/composite-items/new')
  })

  it('renders pagination controls via OffsetPagination (per-page combobox)', () => {
    // OffsetPagination renders a per-page <select> (combobox). The original
    // hand-rolled prev/next buttons had none — this is the red→green driver.
    render(<CompositeItemListPage />)
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })

  it('right-aligns the numeric base-price column with tabular-nums', () => {
    render(<CompositeItemListPage />)
    const priceCell = screen.getByText('3.5000').closest('td')
    expect(priceCell?.className).toContain('text-right')
    expect(priceCell?.className).toContain('tabular-nums')
  })

  it('renders a delete button per row', () => {
    render(<CompositeItemListPage />)
    const deleteButtons = screen.getAllByRole('button', { name: /common:delete/i })
    expect(deleteButtons).toHaveLength(2)
  })

  it('opens a confirm dialog when delete is clicked and calls mutate on confirm', async () => {
    render(<CompositeItemListPage />)
    const deleteButtons = screen.getAllByRole('button', { name: /common:delete/i })
    fireEvent.click(deleteButtons[0]!)

    const confirmButton = await screen.findByRole('button', { name: /common:confirm/i })
    fireEvent.click(confirmButton)

    await waitFor(() => {
      expect(mockDeleteMutate).toHaveBeenCalledWith('1')
    })
  })

  it('hides the delete button when user lacks composite-items.delete permission', () => {
    mockHasPermission = vi.fn().mockReturnValue(false)
    render(<CompositeItemListPage />)
    expect(screen.queryByRole('button', { name: /common:delete/i })).not.toBeInTheDocument()
  })
})
