import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { BooleanFilter } from './BooleanFilter'

describe('BooleanFilter', () => {
  const user = userEvent.setup()
  const onChangeMock = vi.fn()

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders with label when provided', () => {
    render(
      <BooleanFilter
        label="Test Filter"
        value={undefined}
        onChange={onChangeMock}
      />
    )
    expect(screen.getByText('Test Filter')).toBeInTheDocument()
  })

  it('renders without label when not provided', () => {
    render(
      <BooleanFilter
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const checkbox = screen.getByRole('checkbox')
    expect(checkbox).toBeInTheDocument()
  })

  it('checkbox is unchecked when value is undefined', () => {
    render(
      <BooleanFilter
        label="Test Filter"
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const checkbox = screen.getByRole('checkbox') as HTMLInputElement
    expect(checkbox.checked).toBe(false)
  })

  it('checkbox is unchecked when value is false', () => {
    render(
      <BooleanFilter
        label="Test Filter"
        value={false}
        onChange={onChangeMock}
      />
    )
    const checkbox = screen.getByRole('checkbox') as HTMLInputElement
    expect(checkbox.checked).toBe(false)
  })

  it('checkbox is checked when value is true', () => {
    render(
      <BooleanFilter
        label="Test Filter"
        value={true}
        onChange={onChangeMock}
      />
    )
    const checkbox = screen.getByRole('checkbox') as HTMLInputElement
    expect(checkbox.checked).toBe(true)
  })

  it('calls onChange with true when checkbox is checked', async () => {
    render(
      <BooleanFilter
        label="Test Filter"
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const checkbox = screen.getByRole('checkbox')
    await user.click(checkbox)

    expect(onChangeMock).toHaveBeenCalledWith(true)
  })

  it('calls onChange with undefined when checkbox is unchecked', async () => {
    render(
      <BooleanFilter
        label="Test Filter"
        value={true}
        onChange={onChangeMock}
      />
    )
    const checkbox = screen.getByRole('checkbox')
    await user.click(checkbox)

    expect(onChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('applies custom className', () => {
    const { container } = render(
      <BooleanFilter
        label="Test Filter"
        value={undefined}
        onChange={onChangeMock}
        className="custom-class"
      />
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })

  it('label is clickable and toggles checkbox', async () => {
    render(
      <BooleanFilter
        label="Click Me"
        value={undefined}
        onChange={onChangeMock}
      />
    )
    const label = screen.getByText('Click Me')
    await user.click(label)

    expect(onChangeMock).toHaveBeenCalledWith(true)
  })
})
