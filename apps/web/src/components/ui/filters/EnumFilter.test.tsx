import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { EnumFilter } from './EnumFilter'

describe('EnumFilter', () => {
  const user = userEvent.setup()
  const onChangeMock = vi.fn()

  const mockOptions = [
    { value: 'option1', label: 'Option 1' },
    { value: 'option2', label: 'Option 2' },
    { value: 'option3', label: 'Option 3' },
  ]

  afterEach(() => {
    vi.clearAllMocks()
  })

  it('renders with label when provided', () => {
    render(
      <EnumFilter
        label="Test Filter"
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    expect(screen.getByText('Test Filter')).toBeInTheDocument()
  })

  it('renders without label when not provided', () => {
    render(
      <EnumFilter
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    const select = screen.getByRole('combobox')
    expect(select).toBeInTheDocument()
  })

  it('displays placeholder as first option', () => {
    render(
      <EnumFilter
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
        placeholder="Choose an option"
      />
    )
    expect(screen.getByText('Choose an option')).toBeInTheDocument()
  })

  it('displays default placeholder when not provided', () => {
    render(
      <EnumFilter
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    expect(screen.getByText('Select...')).toBeInTheDocument()
  })

  it('renders all options', () => {
    render(
      <EnumFilter
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    expect(screen.getByText('Option 1')).toBeInTheDocument()
    expect(screen.getByText('Option 2')).toBeInTheDocument()
    expect(screen.getByText('Option 3')).toBeInTheDocument()
  })

  it('shows selected value', () => {
    render(
      <EnumFilter
        value="option2"
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('option2')
  })

  it('calls onChange with selected value when option is selected', async () => {
    render(
      <EnumFilter
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    const select = screen.getByRole('combobox')
    await user.selectOptions(select, 'option2')

    expect(onChangeMock).toHaveBeenCalledWith('option2')
  })

  it('calls onChange with undefined when placeholder is selected', async () => {
    render(
      <EnumFilter
        value="option1"
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    const select = screen.getByRole('combobox')
    await user.selectOptions(select, '')

    expect(onChangeMock).toHaveBeenCalledWith(undefined)
  })

  it('accepts undefined value', () => {
    render(
      <EnumFilter
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
      />
    )
    const select = screen.getByRole('combobox') as HTMLSelectElement
    expect(select.value).toBe('')
  })

  it('applies custom className', () => {
    const { container } = render(
      <EnumFilter
        value={undefined}
        onChange={onChangeMock}
        options={mockOptions}
        className="custom-class"
      />
    )
    expect(container.firstChild).toHaveClass('custom-class')
  })
})
