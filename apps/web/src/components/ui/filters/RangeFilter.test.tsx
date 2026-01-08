import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { RangeFilter } from './RangeFilter'

describe('RangeFilter', () => {
  const user = userEvent.setup()
  const onMinChangeMock = vi.fn()
  const onMaxChangeMock = vi.fn()

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders with label when provided', () => {
    render(
      <RangeFilter
        label="Price Range"
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    expect(screen.getByText('Price Range')).toBeInTheDocument()
  })

  it('renders without label when not provided', () => {
    render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const inputs = screen.getAllByRole('spinbutton')
    expect(inputs).toHaveLength(2)
  })

  it('displays placeholder in min and max inputs', () => {
    render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
        placeholder="price"
      />
    )
    expect(screen.getByPlaceholderText('Min price')).toBeInTheDocument()
    expect(screen.getByPlaceholderText('Max price')).toBeInTheDocument()
  })

  it('displays empty placeholder when not provided', () => {
    render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    expect(screen.getByPlaceholderText('Min')).toBeInTheDocument()
    expect(screen.getByPlaceholderText('Max')).toBeInTheDocument()
  })

  it('shows min value', () => {
    render(
      <RangeFilter
        min="100"
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const minInput = screen.getByPlaceholderText('Min') as HTMLInputElement
    expect(minInput.value).toBe('100')
  })

  it('shows max value', () => {
    render(
      <RangeFilter
        min={undefined}
        max="500"
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const maxInput = screen.getByPlaceholderText('Max') as HTMLInputElement
    expect(maxInput.value).toBe('500')
  })

  it('calls onMinChange with value when min input changes', async () => {
    render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const minInput = screen.getByPlaceholderText('Min')
    await user.type(minInput, '100')

    // userEvent.type() triggers onChange for each character
    expect(onMinChangeMock).toHaveBeenCalled()
    expect(onMinChangeMock).toHaveBeenCalledTimes(3)
  })

  it('calls onMaxChange with value when max input changes', async () => {
    render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const maxInput = screen.getByPlaceholderText('Max')
    await user.type(maxInput, '500')

    // userEvent.type() triggers onChange for each character
    expect(onMaxChangeMock).toHaveBeenCalled()
    expect(onMaxChangeMock).toHaveBeenCalledTimes(3)
  })

  it('calls onMinChange with undefined when min input is cleared', async () => {
    render(
      <RangeFilter
        min="100"
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const minInput = screen.getByPlaceholderText('Min')
    await user.clear(minInput)

    expect(onMinChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('calls onMaxChange with undefined when max input is cleared', async () => {
    render(
      <RangeFilter
        min={undefined}
        max="500"
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const maxInput = screen.getByPlaceholderText('Max')
    await user.clear(maxInput)

    expect(onMaxChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('accepts undefined min and max values', () => {
    render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const minInput = screen.getByPlaceholderText('Min') as HTMLInputElement
    const maxInput = screen.getByPlaceholderText('Max') as HTMLInputElement
    expect(minInput.value).toBe('')
    expect(maxInput.value).toBe('')
  })

  it('applies custom className', () => {
    const { container } = render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
        className="custom-class"
      />
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('renders both inputs as number type', () => {
    render(
      <RangeFilter
        min={undefined}
        max={undefined}
        onMinChange={onMinChangeMock}
        onMaxChange={onMaxChangeMock}
      />
    )
    const inputs = screen.getAllByRole('spinbutton')
    expect(inputs).toHaveLength(2)
    inputs.forEach(input => {
      expect(input).toHaveAttribute('type', 'number')
    })
  })
})
