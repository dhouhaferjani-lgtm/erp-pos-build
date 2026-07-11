import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Radio } from './Radio'

describe('Radio', () => {
  it('renders a radio input', () => {
    render(<Radio aria-label="fifo" />)
    const el = screen.getByLabelText('fifo')
    expect(el).toBeInTheDocument()
    expect(el).toHaveAttribute('type', 'radio')
  })

  it('applies the radio design token class', () => {
    render(<Radio aria-label="fifo" />)
    expect(screen.getByLabelText('fifo').className).toContain('h-4')
  })

  it('merges custom className with the token base', () => {
    render(<Radio aria-label="fifo" className="mt-1" />)
    const el = screen.getByLabelText('fifo')
    expect(el.className).toContain('mt-1')
    expect(el.className).toContain('h-4')
  })

  it('reflects the checked prop', () => {
    render(<Radio aria-label="fifo" checked readOnly />)
    expect(screen.getByLabelText('fifo')).toBeChecked()
  })

  it('fires onChange when selected', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Radio aria-label="fifo" onChange={onChange} />)
    await user.click(screen.getByLabelText('fifo'))
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('forwards a ref to the underlying input', () => {
    const ref = { current: null as HTMLInputElement | null }
    render(<Radio aria-label="fifo" ref={ref} />)
    expect(ref.current).toBeInstanceOf(HTMLInputElement)
  })

  it('groups by shared name and value', () => {
    render(
      <>
        <Radio aria-label="fifo" name="method" value="fifo" defaultChecked />
        <Radio aria-label="wac" name="method" value="wac" />
      </>,
    )
    const fifo = screen.getByLabelText('fifo') as HTMLInputElement
    const wac = screen.getByLabelText('wac') as HTMLInputElement
    expect(fifo.name).toBe('method')
    expect(wac.name).toBe('method')
    expect(fifo.value).toBe('fifo')
    expect(wac.value).toBe('wac')
  })
})
