import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { ActiveFilters } from './ActiveFilters'
import type { FilterConfig } from './ActiveFilters'

describe('ActiveFilters', () => {
  const user = userEvent.setup()
  const onRemoveMock = vi.fn()

  const filterConfig: Record<string, FilterConfig> = {
    status: { label: 'Status', type: 'enum' },
    active: { label: 'Active', type: 'boolean' },
    price_min: { label: 'Min Price', type: 'range' },
    price_max: { label: 'Max Price', type: 'range' },
    search: { label: 'Search', type: 'text' },
  }

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('returns null when no filters are active', () => {
    const { container } = render(
      <ActiveFilters
        filters={{}}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(container.firstChild).toBeNull()
  })

  it('returns null when filters have only undefined values', () => {
    const { container } = render(
      <ActiveFilters
        filters={{ status: undefined, search: undefined }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(container.firstChild).toBeNull()
  })

  it('returns null when filters have only empty string values', () => {
    const { container } = render(
      <ActiveFilters
        filters={{ status: '', search: '' }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(container.firstChild).toBeNull()
  })

  it('displays filter chip for string value', () => {
    render(
      <ActiveFilters
        filters={{ search: 'test query' }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Search: test query/)).toBeInTheDocument()
  })

  it('displays filter chip for number value', () => {
    render(
      <ActiveFilters
        filters={{ price_min: 100 }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Min Price: 100/)).toBeInTheDocument()
  })

  it('displays "Yes" for true boolean value', () => {
    render(
      <ActiveFilters
        filters={{ active: true }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Active: Yes/)).toBeInTheDocument()
  })

  it('displays "No" for false boolean value', () => {
    render(
      <ActiveFilters
        filters={{ active: false }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Active: No/)).toBeInTheDocument()
  })

  it('displays multiple active filters', () => {
    render(
      <ActiveFilters
        filters={{
          search: 'test',
          status: 'active',
          price_min: 100,
        }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Search: test/)).toBeInTheDocument()
    expect(screen.getByText(/Status: active/)).toBeInTheDocument()
    expect(screen.getByText(/Min Price: 100/)).toBeInTheDocument()
  })

  it('does not display filter without config', () => {
    render(
      <ActiveFilters
        filters={{
          search: 'test',
          unknown_filter: 'value',
        }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Search: test/)).toBeInTheDocument()
    expect(screen.queryByText(/unknown_filter/)).not.toBeInTheDocument()
  })

  it('shows remove button for each filter', () => {
    render(
      <ActiveFilters
        filters={{
          search: 'test',
          status: 'active',
        }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    const removeButtons = screen.getAllByRole('button')
    expect(removeButtons).toHaveLength(2)
  })

  it('calls onRemove with correct key when remove button is clicked', async () => {
    render(
      <ActiveFilters
        filters={{ search: 'test' }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    const removeButton = screen.getByRole('button', { name: /remove search filter/i })
    await user.click(removeButton)

    expect(onRemoveMock).toHaveBeenCalledWith('search')
  })

  it('calls onRemove with correct key for different filters', async () => {
    render(
      <ActiveFilters
        filters={{
          search: 'test',
          status: 'active',
        }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    const statusRemoveButton = screen.getByRole('button', { name: /remove status filter/i })
    await user.click(statusRemoveButton)

    expect(onRemoveMock).toHaveBeenCalledWith('status')
  })

  it('renders X icon in remove button', () => {
    render(
      <ActiveFilters
        filters={{ search: 'test' }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    const removeButton = screen.getByRole('button')
    expect(removeButton.querySelector('svg')).toBeInTheDocument()
  })

  it('uses filter label from config', () => {
    const customConfig: Record<string, FilterConfig> = {
      custom: { label: 'Custom Filter Label', type: 'text' },
    }
    render(
      <ActiveFilters
        filters={{ custom: 'value' }}
        onRemove={onRemoveMock}
        filterConfig={customConfig}
      />
    )
    expect(screen.getByText(/Custom Filter Label: value/)).toBeInTheDocument()
  })

  it('converts number to string for display', () => {
    render(
      <ActiveFilters
        filters={{ price_min: 12345 }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Min Price: 12345/)).toBeInTheDocument()
  })

  it('handles zero as a valid filter value', () => {
    render(
      <ActiveFilters
        filters={{ price_min: 0 }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Min Price: 0/)).toBeInTheDocument()
  })

  it('ignores filters with undefined in mixed filter object', () => {
    render(
      <ActiveFilters
        filters={{
          search: 'test',
          status: undefined,
          price_min: 100,
        }}
        onRemove={onRemoveMock}
        filterConfig={filterConfig}
      />
    )
    expect(screen.getByText(/Search: test/)).toBeInTheDocument()
    expect(screen.queryByText(/Status:/)).not.toBeInTheDocument()
    expect(screen.getByText(/Min Price: 100/)).toBeInTheDocument()
  })
})
