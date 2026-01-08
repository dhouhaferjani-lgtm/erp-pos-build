import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DateRangeFilter } from './DateRangeFilter'

describe('DateRangeFilter', () => {
  const user = userEvent.setup()
  const onFromChangeMock = vi.fn()
  const onToChangeMock = vi.fn()

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders with label', () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    expect(screen.getByText('Date Range')).toBeInTheDocument()
  })

  it('displays From and To placeholders', () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    expect(screen.getByPlaceholderText('From')).toBeInTheDocument()
    expect(screen.getByPlaceholderText('To')).toBeInTheDocument()
  })

  it('shows from value', () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue="2024-01-01"
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const fromInput = screen.getByPlaceholderText('From') as HTMLInputElement
    expect(fromInput.value).toBe('2024-01-01')
  })

  it('shows to value', () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue="2024-12-31"
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const toInput = screen.getByPlaceholderText('To') as HTMLInputElement
    expect(toInput.value).toBe('2024-12-31')
  })

  it('calls onFromChange with value when from input changes', async () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const fromInput = screen.getByPlaceholderText('From')
    await user.type(fromInput, '2024-01-01')

    expect(onFromChangeMock).toHaveBeenCalled()
  })

  it('calls onToChange with value when to input changes', async () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const toInput = screen.getByPlaceholderText('To')
    await user.type(toInput, '2024-12-31')

    expect(onToChangeMock).toHaveBeenCalled()
  })

  it('calls onFromChange with undefined when from input is cleared', async () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue="2024-01-01"
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const fromInput = screen.getByPlaceholderText('From')
    await user.clear(fromInput)

    expect(onFromChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('calls onToChange with undefined when to input is cleared', async () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue="2024-12-31"
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const toInput = screen.getByPlaceholderText('To')
    await user.clear(toInput)

    expect(onToChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('accepts undefined fromValue and toValue', () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const fromInput = screen.getByPlaceholderText('From') as HTMLInputElement
    const toInput = screen.getByPlaceholderText('To') as HTMLInputElement
    expect(fromInput.value).toBe('')
    expect(toInput.value).toBe('')
  })

  it('applies custom className', () => {
    const { container } = render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
        className="custom-class"
      />
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('renders both inputs as date type', () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue={undefined}
        toValue={undefined}
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const fromInput = screen.getByPlaceholderText('From')
    const toInput = screen.getByPlaceholderText('To')
    expect(fromInput).toHaveAttribute('type', 'date')
    expect(toInput).toHaveAttribute('type', 'date')
  })

  it('displays both from and to values together', () => {
    render(
      <DateRangeFilter
        label="Date Range"
        fromValue="2024-01-01"
        toValue="2024-12-31"
        onFromChange={onFromChangeMock}
        onToChange={onToChangeMock}
      />
    )
    const fromInput = screen.getByPlaceholderText('From') as HTMLInputElement
    const toInput = screen.getByPlaceholderText('To') as HTMLInputElement
    expect(fromInput.value).toBe('2024-01-01')
    expect(toInput.value).toBe('2024-12-31')
  })
})
