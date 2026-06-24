import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Checkbox } from './Checkbox'

describe('Checkbox', () => {
  it('renders a checkbox input', () => {
    render(<Checkbox aria-label="agree" />)
    const el = screen.getByLabelText('agree')
    expect(el).toBeInTheDocument()
    expect(el).toHaveAttribute('type', 'checkbox')
  })

  it('applies the checkbox design token class', () => {
    render(<Checkbox aria-label="agree" />)
    expect(screen.getByLabelText('agree').className).toContain('rounded')
  })

  it('merges custom className with the token base', () => {
    render(<Checkbox aria-label="agree" className="mt-0.5" />)
    expect(screen.getByLabelText('agree').className).toContain('mt-0.5')
  })

  it('reflects the checked prop', () => {
    render(<Checkbox aria-label="agree" checked readOnly />)
    expect(screen.getByLabelText('agree')).toBeChecked()
  })

  it('fires onChange when toggled', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<Checkbox aria-label="agree" onChange={onChange} />)
    await user.click(screen.getByLabelText('agree'))
    expect(onChange).toHaveBeenCalledTimes(1)
  })

  it('forwards a ref to the underlying input', () => {
    const ref = { current: null as HTMLInputElement | null }
    render(<Checkbox aria-label="agree" ref={ref} />)
    expect(ref.current).toBeInstanceOf(HTMLInputElement)
  })

  it('sets the DOM indeterminate property when indeterminate is true', () => {
    render(<Checkbox aria-label="agree" indeterminate />)
    const el = screen.getByLabelText('agree') as HTMLInputElement
    expect(el.indeterminate).toBe(true)
  })

  it('does not set indeterminate by default', () => {
    render(<Checkbox aria-label="agree" />)
    const el = screen.getByLabelText('agree') as HTMLInputElement
    expect(el.indeterminate).toBe(false)
  })

  it('still forwards the ref while managing indeterminate internally', () => {
    const ref = { current: null as HTMLInputElement | null }
    render(<Checkbox aria-label="agree" ref={ref} indeterminate />)
    expect(ref.current).toBeInstanceOf(HTMLInputElement)
    expect(ref.current?.indeterminate).toBe(true)
  })

  it('keeps checked/onChange working alongside indeterminate', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(
      <Checkbox aria-label="agree" indeterminate checked={false} onChange={onChange} />,
    )
    const el = screen.getByLabelText('agree') as HTMLInputElement
    expect(el.indeterminate).toBe(true)
    expect(el).not.toBeChecked()
    await user.click(el)
    expect(onChange).toHaveBeenCalledTimes(1)
  })
})
