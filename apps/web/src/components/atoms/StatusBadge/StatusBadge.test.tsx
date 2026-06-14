import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { tokens } from '@/lib/designTokens'
import { StatusBadge } from './StatusBadge'
import { statusTone } from './statusTone'

describe('StatusBadge', () => {
  it('renders the provided label', () => {
    render(<StatusBadge>Active</StatusBadge>)
    expect(screen.getByText('Active')).toBeInTheDocument()
  })

  it('defaults to the neutral tone (gray badge token)', () => {
    render(<StatusBadge>Default</StatusBadge>)
    const el = screen.getByText('Default')
    expect(el.className).toContain(tokens.badge.gray)
  })

  it('applies the info tone token', () => {
    render(<StatusBadge tone="info">Info</StatusBadge>)
    const el = screen.getByText('Info')
    expect(el.className).toContain(tokens.alert.info)
  })

  it('applies the success tone token', () => {
    render(<StatusBadge tone="success">Done</StatusBadge>)
    const el = screen.getByText('Done')
    expect(el.className).toContain(tokens.alert.success)
  })

  it('applies the warning tone token', () => {
    render(<StatusBadge tone="warning">Low</StatusBadge>)
    const el = screen.getByText('Low')
    expect(el.className).toContain(tokens.alert.warning)
  })

  it('applies the danger tone token', () => {
    render(<StatusBadge tone="danger">Failed</StatusBadge>)
    const el = screen.getByText('Failed')
    expect(el.className).toContain(tokens.alert.error)
  })

  it('applies the pending tone token (gray badge)', () => {
    render(<StatusBadge tone="pending">Draft</StatusBadge>)
    const el = screen.getByText('Draft')
    expect(el.className).toContain(tokens.badge.gray)
  })

  it('merges a caller-supplied className', () => {
    render(<StatusBadge className="ml-2">Tagged</StatusBadge>)
    expect(screen.getByText('Tagged').className).toContain('ml-2')
  })
})

describe('statusTone', () => {
  it('maps known success-like statuses (case-insensitive)', () => {
    expect(statusTone('active')).toBe('success')
    expect(statusTone('COMPLETED')).toBe('success')
    expect(statusTone('Paid')).toBe('success')
    expect(statusTone('approved')).toBe('success')
  })

  it('maps known pending-like statuses', () => {
    expect(statusTone('pending')).toBe('pending')
    expect(statusTone('draft')).toBe('pending')
    expect(statusTone('scheduled')).toBe('pending')
  })

  it('maps known danger-like statuses', () => {
    expect(statusTone('cancelled')).toBe('danger')
    expect(statusTone('void')).toBe('danger')
    expect(statusTone('failed')).toBe('danger')
    expect(statusTone('rejected')).toBe('danger')
    expect(statusTone('error')).toBe('danger')
  })

  it('maps known warning-like statuses', () => {
    expect(statusTone('warning')).toBe('warning')
    expect(statusTone('low')).toBe('warning')
    expect(statusTone('overdue')).toBe('warning')
  })

  it('falls back to neutral for unknown statuses', () => {
    expect(statusTone('something-weird')).toBe('neutral')
  })

  it('lets overrides win over built-in mappings', () => {
    expect(statusTone('active', { active: 'info' })).toBe('info')
    expect(statusTone('CUSTOM', { custom: 'warning' })).toBe('warning')
  })
})
