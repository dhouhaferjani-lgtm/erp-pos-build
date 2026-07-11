import { render, screen } from '@testing-library/react'
import { Badge } from './Badge'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

describe('Badge', () => {
  it('renders with default variant', () => {
    render(<Badge>Default</Badge>)
    expect(screen.getByText('Default')).toBeInTheDocument()
  })

  it('renders with success variant', () => {
    render(<Badge variant="success">Success</Badge>)
    const badge = screen.getByText('Success')
    expect(badge).toHaveClass(`${colorTokens.intent.success.bgSoft}`, `${colorTokens.intent.success.textStronger}`)
  })

  it('renders with warning variant', () => {
    render(<Badge variant="warning">Warning</Badge>)
    const badge = screen.getByText('Warning')
    expect(badge).toHaveClass(`${colorTokens.intent.warning.bgSoft}`, `${colorTokens.intent.warning.textStronger}`)
  })

  it('renders with danger variant', () => {
    render(<Badge variant="danger">Danger</Badge>)
    const badge = screen.getByText('Danger')
    expect(badge).toHaveClass(`${colorTokens.intent.danger.bgSoft}`, `${colorTokens.intent.danger.textStronger}`)
  })

  it('renders with info variant', () => {
    render(<Badge variant="info">Info</Badge>)
    const badge = screen.getByText('Info')
    expect(badge).toHaveClass(`${colorTokens.intent.primary.bgSoft}`, `${colorTokens.intent.primary.textStronger}`)
  })

  it('applies custom className', () => {
    render(<Badge className="custom-class">Custom</Badge>)
    const badge = screen.getByText('Custom')
    expect(badge).toHaveClass('custom-class')
  })
})
