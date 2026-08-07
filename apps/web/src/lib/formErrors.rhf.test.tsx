import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useForm, type FieldErrors } from 'react-hook-form'
import { describe, expect, it, vi } from 'vitest'

import { focusFirstInvalidField } from './formErrors'

/**
 * FE gate m2 — contract test for the pairing of `focusFirstInvalidField` with
 * react-hook-form.
 *
 * `handleSubmit` runs `_focusError()` (plus a deferred `setTimeout(_focusError)`)
 * AFTER awaiting `onInvalid`. `_focusError` walks the `_fields` registry in
 * REGISTRATION order and calls `ref.focus()` without `preventScroll`. So
 * whenever registration order differs from DOM order, RHF silently overwrites
 * the element the helper chose — and scrolls the page to a different field than
 * the one the toast is about.
 *
 * `ProductForm` happens to register in DOM order today, which is exactly why
 * this has to be pinned here instead: the fixture deliberately registers
 * `alpha` before `beta` while rendering `beta` first, so the two orders
 * disagree and the last writer is observable.
 */

const FORM_ID = 'divergent-order-form'

interface DivergentFormData {
  alpha: string
  beta: string
}

function DivergentOrderForm({ shouldFocusError }: { shouldFocusError: boolean }) {
  const { register, handleSubmit } = useForm<DivergentFormData>({
    shouldFocusError,
    defaultValues: { alpha: '', beta: '' },
  })

  // Registration order: alpha, then beta.
  const alpha = register('alpha', { required: 'alpha is required' })
  const beta = register('beta', { required: 'beta is required' })

  const onInvalid = (fieldErrors: FieldErrors<DivergentFormData>) => {
    const form = document.getElementById(FORM_ID)
    focusFirstInvalidField(form instanceof HTMLFormElement ? form : null, fieldErrors)
  }

  return (
    <form id={FORM_ID} onSubmit={(e) => { void handleSubmit(() => undefined, onInvalid)(e) }}>
      {/* DOM order: beta, then alpha — the reverse of registration order. */}
      <input aria-label="beta" {...beta} />
      <input aria-label="alpha" {...alpha} />
      <button type="submit">save</button>
    </form>
  )
}

async function submitAndSettle() {
  const user = userEvent.setup()
  await user.click(screen.getByRole('button', { name: 'save' }))
  // RHF re-focuses once synchronously and again in a setTimeout; wait both out.
  await new Promise((resolve) => setTimeout(resolve, 0))
}

describe('focusFirstInvalidField vs react-hook-form shouldFocusError', () => {
  it('is OVERRIDDEN by react-hook-form when shouldFocusError is left on (the m2 defect)', async () => {
    window.HTMLElement.prototype.scrollIntoView = vi.fn()

    render(<DivergentOrderForm shouldFocusError />)
    await submitAndSettle()

    // RHF's registration-order pick wins: alpha, not the DOM-first beta.
    await waitFor(() => {
      expect(document.activeElement).toBe(screen.getByLabelText('alpha'))
    })
  })

  it('stays authoritative when shouldFocusError is disabled (the fix ProductForm applies)', async () => {
    window.HTMLElement.prototype.scrollIntoView = vi.fn()

    render(<DivergentOrderForm shouldFocusError={false} />)
    await submitAndSettle()

    // The DOM-order pick survives — beta is first on screen.
    expect(document.activeElement).toBe(screen.getByLabelText('beta'))
  })
})
