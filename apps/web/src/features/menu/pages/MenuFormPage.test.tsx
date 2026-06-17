import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { MenuFormPage } from './MenuFormPage'
import { tokens } from '@/lib/designTokens'

// The first class of the canonical primary button token, referenced via the
// token itself so this test contains no hardcoded color literal.
const PRIMARY_BG_CLASS = tokens.button.primary.split(' ')[0]

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

const mockNavigate = vi.fn()
const mockParams: { id?: string } = {}
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => mockParams,
}))

vi.mock('../hooks/useMenus', () => ({
  useMenu: () => ({ data: undefined, isLoading: false }),
  useCreateMenu: () => ({ mutateAsync: vi.fn().mockResolvedValue({ id: 'm-1' }), isPending: false }),
  useUpdateMenu: () => ({ mutateAsync: vi.fn().mockResolvedValue({ id: 'm-1' }), isPending: false }),
  useCreateMenuCategory: () => ({ mutateAsync: vi.fn() }),
  useDeleteMenuCategory: () => ({ mutateAsync: vi.fn() }),
}))

vi.mock('../components/MenuCategoryItemManager', () => ({
  MenuCategoryItemManager: () => <div data-testid="category-item-manager" />,
}))

beforeEach(() => {
  mockNavigate.mockReset()
  delete mockParams.id
})

describe('MenuFormPage canonicalization', () => {
  it('renders a single canonical <h1> page title via PageHeader', () => {
    const { container } = render(<MenuFormPage />)
    const h1s = container.querySelectorAll('h1')
    expect(h1s.length).toBe(1)
    expect(h1s[0].textContent).toContain('menu:createMenu')
  })

  it('renders section headers using the canonical heading token (text-lg font-medium)', () => {
    render(<MenuFormPage />)
    const basicInfo = screen.getByText('menu:basicInfo')
    expect(basicInfo.tagName).toBe('H2')
    expect(basicInfo.className).toContain('text-lg')
    expect(basicInfo.className).toContain('font-medium')
  })

  it('toggles a day-of-week pill: active state uses the canonical primary token', () => {
    render(<MenuFormPage />)
    const monPill = screen.getByRole('button', { name: 'Mon' })
    // Inactive by default — no primary background.
    expect(monPill.className).not.toContain(PRIMARY_BG_CLASS)
    fireEvent.click(monPill)
    // After toggling active, the pill adopts the canonical primary token.
    expect(monPill.className).toContain(PRIMARY_BG_CLASS)
  })

  it('back-navigation button returns to the menus list', () => {
    render(<MenuFormPage />)
    const backButton = screen.getAllByRole('button')[0]
    fireEvent.click(backButton)
    expect(mockNavigate).toHaveBeenCalledWith('/catalog/menus')
  })
})
