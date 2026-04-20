import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { FilterPanel } from './FilterPanel'

// Mock react-i18next.
//
// NOTE: The component now uses `t('filtersLabel')` (renamed from
// `filters`) — keeping the old key would leave assertions looking for
// the literal "Filters" string against rendered "filtersLabel".
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        filters: 'Filters',
        filtersLabel: 'Filters',
        clearAll: 'Clear all',
      }
      return translations[key] || key
    },
  }),
}))

describe('FilterPanel', () => {
  const user = userEvent.setup()
  const onToggleMock = vi.fn()
  const onClearMock = vi.fn()

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders filter button with label', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.getByText('Filters')).toBeInTheDocument()
  })

  it('renders filter icon', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    // lucide-react Filter icon will be rendered as svg
    const filterButton = screen.getByText('Filters').closest('button')
    expect(filterButton?.querySelector('svg')).toBeInTheDocument()
  })

  it('does not show filter count badge when no active filters', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    const badge = screen.queryByText(/\d+/)
    expect(badge).not.toBeInTheDocument()
  })

  it('shows filter count badge when there are active filters', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={true}
        activeFilterCount={3}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('does not show clear button when no active filters', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.queryByText('Clear all')).not.toBeInTheDocument()
  })

  it('shows clear button when there are active filters', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={true}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.getByText('Clear all')).toBeInTheDocument()
  })

  it('does not show content when closed', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.queryByText('Filter content')).not.toBeInTheDocument()
  })

  it('shows content when open', () => {
    render(
      <FilterPanel
        isOpen={true}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.getByText('Filter content')).toBeInTheDocument()
  })

  it('calls onToggle when filter button is clicked', async () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    const filterButton = screen.getByText('Filters').closest('button')
    await user.click(filterButton!)

    expect(onToggleMock).toHaveBeenCalledTimes(1)
  })

  it('calls onClear when clear button is clicked', async () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={true}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    const clearButton = screen.getByText('Clear all')
    await user.click(clearButton)

    expect(onClearMock).toHaveBeenCalledTimes(1)
  })

  it('sets aria-expanded to false when closed', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    const filterButton = screen.getByText('Filters').closest('button')
    expect(filterButton).toHaveAttribute('aria-expanded', 'false')
  })

  it('sets aria-expanded to true when open', () => {
    render(
      <FilterPanel
        isOpen={true}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    const filterButton = screen.getByText('Filters').closest('button')
    expect(filterButton).toHaveAttribute('aria-expanded', 'true')
  })

  it('sets aria-controls attribute', () => {
    render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    const filterButton = screen.getByText('Filters').closest('button')
    expect(filterButton).toHaveAttribute('aria-controls', 'filter-panel-content')
  })

  it('content has correct id matching aria-controls', () => {
    render(
      <FilterPanel
        isOpen={true}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    const content = screen.getByText('Filter content').parentElement
    expect(content).toHaveAttribute('id', 'filter-panel-content')
  })

  it('applies custom className', () => {
    const { container } = render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
        className="custom-class"
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('renders children correctly', () => {
    render(
      <FilterPanel
        isOpen={true}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div data-testid="child1">Child 1</div>
        <div data-testid="child2">Child 2</div>
      </FilterPanel>
    )
    expect(screen.getByTestId('child1')).toBeInTheDocument()
    expect(screen.getByTestId('child2')).toBeInTheDocument()
  })

  it('updates badge count when activeFilterCount changes', () => {
    const { rerender } = render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={true}
        activeFilterCount={2}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.getByText('2')).toBeInTheDocument()

    rerender(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={true}
        activeFilterCount={5}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.getByText('5')).toBeInTheDocument()
    expect(screen.queryByText('2')).not.toBeInTheDocument()
  })

  it('shows and hides clear button based on hasActiveFilters', () => {
    const { rerender } = render(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={false}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.queryByText('Clear all')).not.toBeInTheDocument()

    rerender(
      <FilterPanel
        isOpen={false}
        onToggle={onToggleMock}
        onClear={onClearMock}
        hasActiveFilters={true}
      >
        <div>Filter content</div>
      </FilterPanel>
    )
    expect(screen.getByText('Clear all')).toBeInTheDocument()
  })
})
