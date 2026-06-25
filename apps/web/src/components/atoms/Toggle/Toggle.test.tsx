import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createRef } from 'react'
import { Toggle } from './Toggle'

describe('Toggle', () => {
  it('renders with role="switch"', () => {
    render(<Toggle aria-label="Track batches" />)
    expect(screen.getByRole('switch', { name: 'Track batches' })).toBeInTheDocument()
  })

  it('reflects checked=true via aria-checked="true"', () => {
    render(<Toggle aria-label="Track batches" checked readOnly />)
    expect(screen.getByRole('switch', { name: 'Track batches' })).toHaveAttribute('aria-checked', 'true')
  })

  it('reflects checked=false via aria-checked="false"', () => {
    render(<Toggle aria-label="Track batches" checked={false} readOnly />)
    expect(screen.getByRole('switch', { name: 'Track batches' })).toHaveAttribute('aria-checked', 'false')
  })

  it('fires onChange on click', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Toggle aria-label="Track batches" onChange={onChange} />)
    await user.click(screen.getByRole('switch', { name: 'Track batches' }))
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('toggles via Space key', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Toggle aria-label="Track batches" onChange={onChange} />)
    const el = screen.getByRole('switch', { name: 'Track batches' })
    el.focus()
    await user.keyboard(' ')
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('forwards a ref to the underlying input (RHF compatibility)', () => {
    const ref = createRef<HTMLInputElement>()
    render(<Toggle aria-label="Track batches" ref={ref} />)
    expect(ref.current).toBeInstanceOf(HTMLInputElement)
  })

  it('applies the toggle design token class (rounded-full)', () => {
    render(<Toggle aria-label="Track batches" />)
    // The track wrapper should contain rounded-full from the token
    const el = screen.getByRole('switch', { name: 'Track batches' })
    // The track is a sibling/parent of the input; query the container
    const container = el.closest('[data-toggle-track]')
    expect(container?.className).toContain('rounded-full')
  })

  it('merges a custom className onto the track', () => {
    render(<Toggle aria-label="Track batches" className="mt-2" />)
    const el = screen.getByRole('switch', { name: 'Track batches' })
    const container = el.closest('[data-toggle-track]')
    expect(container?.className).toContain('mt-2')
  })

  it('disabled blocks interaction', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Toggle aria-label="Track batches" disabled onChange={onChange} />)
    const el = screen.getByRole('switch', { name: 'Track batches' })
    expect(el).toBeDisabled()
    await user.click(el)
    expect(onChange).not.toHaveBeenCalled()
  })

  it('renders an optional label text', () => {
    render(<Toggle aria-label="Track batches" label="Track batches & expiry" />)
    expect(screen.getByText('Track batches & expiry')).toBeInTheDocument()
  })
})
