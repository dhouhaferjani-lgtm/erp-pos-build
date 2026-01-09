import { describe, it, expect, vi } from 'vitest'
import { render, fireEvent } from '@testing-library/react'
import { Calculator } from './Calculator'

describe('Calculator', () => {
  it('renders calculator when isOpen is true', () => {
    const { getByText } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    expect(getByText('Calculator')).toBeInTheDocument()
  })

  it('does not render when isOpen is false', () => {
    const { queryByText } = render(
      <Calculator isOpen={false} onClose={vi.fn()} />
    )

    expect(queryByText('Calculator')).not.toBeInTheDocument()
  })

  it('displays initial value of 0', () => {
    const { getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    expect(getByDisplayValue('0')).toBeInTheDocument()
  })

  it('updates display when number button is clicked', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    expect(getByDisplayValue('5')).toBeInTheDocument()
  })

  it('handles multiple digit input', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '1' }))
    fireEvent.click(getByRole('button', { name: '2' }))
    fireEvent.click(getByRole('button', { name: '3' }))

    expect(getByDisplayValue('123')).toBeInTheDocument()
  })

  it('handles decimal point input', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '.' }))
    fireEvent.click(getByRole('button', { name: '2' }))

    expect(getByDisplayValue('5.2')).toBeInTheDocument()
  })

  it('prevents multiple decimal points', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '.' }))
    fireEvent.click(getByRole('button', { name: '2' }))
    fireEvent.click(getByRole('button', { name: '.' }))
    fireEvent.click(getByRole('button', { name: '3' }))

    expect(getByDisplayValue('5.23')).toBeInTheDocument()
  })

  it('performs addition correctly', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '+' }))
    fireEvent.click(getByRole('button', { name: '3' }))
    fireEvent.click(getByRole('button', { name: '=' }))

    expect(getByDisplayValue('8')).toBeInTheDocument()
  })

  it('performs subtraction correctly', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '1' }))
    fireEvent.click(getByRole('button', { name: '0' }))
    fireEvent.click(getByRole('button', { name: '-' }))
    fireEvent.click(getByRole('button', { name: '3' }))
    fireEvent.click(getByRole('button', { name: '=' }))

    expect(getByDisplayValue('7')).toBeInTheDocument()
  })

  it('performs multiplication correctly', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '4' }))
    fireEvent.click(getByRole('button', { name: '×' }))
    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '=' }))

    expect(getByDisplayValue('20')).toBeInTheDocument()
  })

  it('performs division correctly', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '2' }))
    fireEvent.click(getByRole('button', { name: '0' }))
    fireEvent.click(getByRole('button', { name: '÷' }))
    fireEvent.click(getByRole('button', { name: '4' }))
    fireEvent.click(getByRole('button', { name: '=' }))

    expect(getByDisplayValue('5')).toBeInTheDocument()
  })

  it('handles division by zero', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '÷' }))
    fireEvent.click(getByRole('button', { name: '0' }))
    fireEvent.click(getByRole('button', { name: '=' }))

    expect(getByDisplayValue('Error')).toBeInTheDocument()
  })

  it('clears display when C button clicked', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '1' }))
    fireEvent.click(getByRole('button', { name: '2' }))
    fireEvent.click(getByRole('button', { name: '3' }))
    fireEvent.click(getByRole('button', { name: 'C' }))

    expect(getByDisplayValue('0')).toBeInTheDocument()
  })

  it('handles chained operations', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '+' }))
    fireEvent.click(getByRole('button', { name: '3' }))
    fireEvent.click(getByRole('button', { name: '+' }))
    fireEvent.click(getByRole('button', { name: '2' }))
    fireEvent.click(getByRole('button', { name: '=' }))

    expect(getByDisplayValue('10')).toBeInTheDocument()
  })

  it('calls onClose when close button clicked', () => {
    const onClose = vi.fn()
    const { getByRole } = render(
      <Calculator isOpen={true} onClose={onClose} />
    )

    fireEvent.click(getByRole('button', { name: /close/i }))
    expect(onClose).toHaveBeenCalled()
  })

  it('displays as floating panel', () => {
    const { container } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    const panel = container.querySelector('.fixed')
    expect(panel).toBeInTheDocument()
  })

  it('applies touch-optimized styles when touchOptimized is true', () => {
    const { container } = render(
      <Calculator isOpen={true} onClose={vi.fn()} touchOptimized={true} />
    )

    // Should have larger buttons
    const buttons = container.querySelectorAll('button')
    const numberButton = Array.from(buttons).find((btn) => btn.textContent === '1')
    expect(numberButton?.className).toContain('text-2xl')
  })

  it('handles backspace/delete functionality', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '1' }))
    fireEvent.click(getByRole('button', { name: '2' }))
    fireEvent.click(getByRole('button', { name: '3' }))
    fireEvent.click(getByRole('button', { name: '⌫' }))

    expect(getByDisplayValue('12')).toBeInTheDocument()
  })

  it('handles percentage calculation', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '0' }))
    fireEvent.click(getByRole('button', { name: '%' }))

    expect(getByDisplayValue('0.5')).toBeInTheDocument()
  })

  it('resets after equals when new number is entered', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '+' }))
    fireEvent.click(getByRole('button', { name: '3' }))
    fireEvent.click(getByRole('button', { name: '=' }))
    fireEvent.click(getByRole('button', { name: '2' }))

    expect(getByDisplayValue('2')).toBeInTheDocument()
  })

  it('handles negative numbers', () => {
    const { getByRole, getByDisplayValue } = render(
      <Calculator isOpen={true} onClose={vi.fn()} />
    )

    fireEvent.click(getByRole('button', { name: '3' }))
    fireEvent.click(getByRole('button', { name: '-' }))
    fireEvent.click(getByRole('button', { name: '5' }))
    fireEvent.click(getByRole('button', { name: '=' }))

    expect(getByDisplayValue('-2')).toBeInTheDocument()
  })
})
