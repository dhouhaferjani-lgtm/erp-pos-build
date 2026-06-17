import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ModifierGroupListPage } from './ModifierGroupListPage'

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

vi.mock('../hooks/useVerticalLabels', () => ({
  useCompanyVerticalLabels: () => (key: string) => key,
}))

const mockPaginatedData = {
  data: [
    {
      id: '1', code: 'SIZE', name: 'Size', selection_type: 'single',
      min_selections: 1, max_selections: 1, is_required: true, is_active: true,
      modifiers: [{ id: 'm1' }, { id: 'm2' }],
    },
    {
      id: '2', code: 'EXTRAS', name: 'Extras', selection_type: 'multiple',
      min_selections: 0, max_selections: 5, is_required: false, is_active: false,
      modifiers: null,
    },
  ],
  meta: { current_page: 1, last_page: 2, per_page: 25, total: 30, from: 1, to: 25 },
}

let mockUseQueryReturn: { data: typeof mockPaginatedData | undefined; isLoading: boolean } = {
  data: mockPaginatedData,
  isLoading: false,
}

vi.mock('../hooks/useModifierGroups', () => ({
  useModifierGroups: () => mockUseQueryReturn,
}))

describe('ModifierGroupListPage', () => {
  beforeEach(() => {
    mockUseQueryReturn = { data: mockPaginatedData, isLoading: false }
    mockNavigate.mockReset()
  })

  it('renders exactly one <h1>', () => {
    const { container } = render(<ModifierGroupListPage />)
    expect(container.querySelectorAll('h1')).toHaveLength(1)
  })

  it('renders modifier groups in the table', () => {
    render(<ModifierGroupListPage />)
    expect(screen.getByText('Size')).toBeInTheDocument()
    expect(screen.getByText('Extras')).toBeInTheDocument()
    expect(screen.getByText('SIZE')).toBeInTheDocument()
  })

  it('renders the Add action as a <button> and navigates on click', () => {
    render(<ModifierGroupListPage />)
    const addButton = screen.getByRole('button', { name: /catalog:createModifierGroup/i })
    expect(addButton.tagName).toBe('BUTTON')
    fireEvent.click(addButton)
    expect(mockNavigate).toHaveBeenCalledWith('/catalog/modifier-groups/new')
  })

  it('renders the empty state when there are no groups', () => {
    mockUseQueryReturn = {
      data: { data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0, from: 0, to: 0 } },
      isLoading: false,
    }
    render(<ModifierGroupListPage />)
    expect(screen.getByText('catalog:noModifierGroups')).toBeInTheDocument()
  })

  it('renders pagination controls via OffsetPagination (per-page combobox)', () => {
    render(<ModifierGroupListPage />)
    expect(screen.getByRole('combobox')).toBeInTheDocument()
  })
})
