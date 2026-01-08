import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { SearchFilter } from './SearchFilter'

describe('SearchFilter', () => {
  const user = userEvent.setup()
  const onChangeMock = vi.fn()

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders with label when provided', () => {
    render(
      <SearchFilter
        label="Search Products"
        value={undefined}
        onChange={onChangeMock}
      />
    )
    expect(screen.getByText('Search Products')).toBeInTheDocument()
  })

  it('renders without label when not provided', () => {
    render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const input = screen.getByPlaceholderText('Search...')
    expect(input).toBeInTheDocument()
  })

  it('displays default placeholder', () => {
    render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    expect(screen.getByPlaceholderText('Search...')).toBeInTheDocument()
  })

  it('displays custom placeholder', () => {
    render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
        placeholder="Type to search"
      />
    )
    expect(screen.getByPlaceholderText('Type to search')).toBeInTheDocument()
  })

  it('shows search icon', () => {
    const { container } = render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    // lucide-react Search icon will be rendered as svg
    const svg = container.querySelector('svg')
    expect(svg).toBeInTheDocument()
  })

  it('shows value in input', () => {
    render(
      <SearchFilter
        value="test query"
        onChange={onChangeMock}
      />
    )
    const input = screen.getByPlaceholderText('Search...') as HTMLInputElement
    expect(input.value).toBe('test query')
  })

  it('calls onChange with value when text is typed', async () => {
    render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const input = screen.getByPlaceholderText('Search...')
    await user.type(input, 'abc')

    // userEvent.type() triggers onChange for each character
    expect(onChangeMock).toHaveBeenCalled()
    expect(onChangeMock).toHaveBeenCalledTimes(3)
  })

  it('calls onChange with undefined when input is cleared via typing', async () => {
    render(
      <SearchFilter
        value="test"
        onChange={onChangeMock}
      />
    )
    const input = screen.getByPlaceholderText('Search...')
    await user.clear(input)

    expect(onChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('does not show clear button when value is undefined', () => {
    render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const clearButton = screen.queryByRole('button', { name: /clear search/i })
    expect(clearButton).not.toBeInTheDocument()
  })

  it('shows clear button when value is present', () => {
    render(
      <SearchFilter
        value="test"
        onChange={onChangeMock}
      />
    )
    const clearButton = screen.getByRole('button', { name: /clear search/i })
    expect(clearButton).toBeInTheDocument()
  })

  it('calls onChange with undefined when clear button is clicked', async () => {
    render(
      <SearchFilter
        value="test"
        onChange={onChangeMock}
      />
    )
    const clearButton = screen.getByRole('button', { name: /clear search/i })
    await user.click(clearButton)

    expect(onChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('accepts undefined value', () => {
    render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const input = screen.getByPlaceholderText('Search...') as HTMLInputElement
    expect(input.value).toBe('')
  })

  it('applies custom className', () => {
    const { container } = render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
        className="custom-class"
      />
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('renders input as text type', () => {
    render(
      <SearchFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const input = screen.getByPlaceholderText('Search...')
    expect(input).toHaveAttribute('type', 'text')
  })

  it('clear button shows X icon', () => {
    const { container } = render(
      <SearchFilter
        value="test"
        onChange={onChangeMock}
      />
    )
    const clearButton = screen.getByRole('button', { name: /clear search/i })
    expect(clearButton.querySelector('svg')).toBeInTheDocument()
  })
})
