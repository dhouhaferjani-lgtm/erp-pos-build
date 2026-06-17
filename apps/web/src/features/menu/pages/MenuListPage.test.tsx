import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { MenuListPage } from './MenuListPage'
import type { MenuData } from '../types/menu'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

const mockUseMenus = vi.fn()
const mockDeleteMutate = vi.fn()
vi.mock('../hooks/useMenus', () => ({
  useMenus: (params: Record<string, string>) => mockUseMenus(params),
  useDeleteMenu: () => ({ mutate: mockDeleteMutate }),
}))

// useTableState is a thin URL/state helper; stub it deterministically.
vi.mock('../../../hooks/useTableState', () => ({
  useTableState: () => ({
    filters: {},
    getQueryParams: () => ({}),
    setFilter: vi.fn(),
    setPage: vi.fn(),
    setPerPage: vi.fn(),
    hasActiveFilters: false,
  }),
}))

vi.mock('../../../components/ui/filters/SearchFilter', () => ({
  SearchFilter: () => <div data-testid="search-filter" />,
}))
vi.mock('../../../components/molecules/FilterTabs', () => ({
  FilterTabs: () => <div data-testid="filter-tabs" />,
}))
vi.mock('../../../components/ui/OffsetPagination', () => ({
  OffsetPagination: () => <div data-testid="pagination" />,
}))

function makeMenu(overrides: Partial<MenuData> = {}): MenuData {
  return {
    id: 'm-1',
    name: 'Breakfast',
    description: null,
    is_default: false,
    is_active: true,
    active_from: null,
    active_until: null,
    start_date: null,
    end_date: null,
    available_days: null,
    display_order: 0,
    categories_count: 2,
    items_count: 5,
    ...overrides,
  } as MenuData
}

beforeEach(() => {
  mockNavigate.mockReset()
  mockDeleteMutate.mockReset()
  mockUseMenus.mockReset()
})

describe('MenuListPage canonicalization', () => {
  it('renders a single canonical <h1> page title', () => {
    mockUseMenus.mockReturnValue({ data: { data: [makeMenu()], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } }, isLoading: false })
    const { container } = render(<MenuListPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('menu:menus')
  })

  it('renders the active status via the canonical StatusBadge pill', () => {
    mockUseMenus.mockReturnValue({ data: { data: [makeMenu({ is_active: true })], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } }, isLoading: false })
    render(<MenuListPage />)
    const badge = screen.getByText('common:active')
    // StatusBadge renders the canonical pill shape (rounded-full + tokens.badge.base).
    expect(badge.tagName).toBe('SPAN')
    expect(badge.className).toContain('rounded-full')
  })

  it('navigates to the new-menu route when the Add button is clicked (Button onClick, not Link)', () => {
    mockUseMenus.mockReturnValue({ data: { data: [makeMenu()], meta: { total: 1, current_page: 1, last_page: 1, per_page: 25 } }, isLoading: false })
    render(<MenuListPage />)
    const addButton = screen.getByRole('button', { name: /menu:createMenu/ })
    fireEvent.click(addButton)
    expect(mockNavigate).toHaveBeenCalledWith('/catalog/menus/new')
  })
})
