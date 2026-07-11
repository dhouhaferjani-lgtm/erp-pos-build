import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CategoryManagementPage } from '../CategoryManagementPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) =>
      typeof second === 'string' ? second : key,
  }),
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

interface CategoryNode {
  id: number
  name: string
  description: string | null
  parent_id: number | null
  depth: number
  products_count: number | null
  children: CategoryNode[]
}

let mockTree: CategoryNode[] = []

vi.mock('../../api/queries', () => ({
  useCategoryTree: () => ({ data: mockTree, isLoading: false }),
  useCreateCategory: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdateCategory: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useDeleteCategory: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

// Stub the heavy tree renderer so the page renders deterministically.
vi.mock('@/components/catalog/CategoryTree', () => ({
  CategoryTree: () => null,
}))

function node(over: Partial<CategoryNode>): CategoryNode {
  return {
    id: 1,
    name: 'Drinks',
    description: 'Hot and cold',
    parent_id: null,
    depth: 0,
    products_count: 0,
    children: [],
    ...over,
  }
}

describe('CategoryManagementPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockTree = [node({ id: 1, name: 'Drinks' })]
  })

  it('renders the page title as a single h1 (PageHeader)', () => {
    render(<CategoryManagementPage />)
    const heading = screen.getByRole('heading', {
      level: 1,
      name: 'common:catalog.categories.title',
    })
    expect(heading).toBeInTheDocument()
  })

  it('renders a Create button in the header', () => {
    render(<CategoryManagementPage />)
    expect(
      screen.getByRole('button', { name: /common:catalog.categories.create/i }),
    ).toBeInTheDocument()
  })

  it('opens the create modal with a tokenized Textarea for the description', async () => {
    const user = userEvent.setup()
    render(<CategoryManagementPage />)

    await user.click(
      screen.getByRole('button', { name: /common:catalog.categories.create/i }),
    )

    // The description field must be the shared Textarea atom, which applies the
    // design-token radius variable rather than the old raw rounded-lg.
    const textarea = screen.getByPlaceholderText(
      'common:catalog.categories.description',
    )
    expect(textarea.tagName).toBe('TEXTAREA')
    expect(textarea.className).toContain('rounded-[var(--radius-input)]')
    expect(textarea.className).not.toContain('rounded-lg')
  })
})
