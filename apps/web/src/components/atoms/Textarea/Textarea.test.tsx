import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { Textarea } from './Textarea'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

describe('Textarea', () => {
  it('renders a textarea element', () => {
    render(<Textarea placeholder="Enter notes" />)
    expect(screen.getByPlaceholderText('Enter notes')).toBeInTheDocument()
  })

  it('does not leak the error prop to the DOM', () => {
    render(<Textarea error placeholder="Notes" />)
    const el = screen.getByPlaceholderText('Notes')
    expect(el).not.toHaveAttribute('error')
  })

  it('applies error styling when error is true', () => {
    render(<Textarea error placeholder="Notes" />)
    const el = screen.getByPlaceholderText('Notes')
    expect(el.className).toContain(`${colorTokens.intent.danger.borderFocus}`)
  })

  it('does not apply error styling by default', () => {
    render(<Textarea placeholder="Notes" />)
    const el = screen.getByPlaceholderText('Notes')
    expect(el.className).not.toContain(`${colorTokens.intent.danger.borderFocus}`)
  })

  it('forwards arbitrary textarea attributes', () => {
    render(<Textarea placeholder="Notes" rows={6} />)
    const el = screen.getByPlaceholderText('Notes')
    expect(el).toHaveAttribute('rows', '6')
  })
})
