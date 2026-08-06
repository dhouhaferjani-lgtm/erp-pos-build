import { describe, expect, it, vi } from 'vitest'

import { collectErrorFieldNames, focusFirstInvalidField } from './formErrors'

describe('collectErrorFieldNames', () => {
  it('flattens nested react-hook-form errors into register() field paths', () => {
    const errors = {
      name: { type: 'required', message: 'Name is required' },
      parapharmacy_metadata: {
        category: { type: 'required', message: 'Required' },
      },
      cross_references: [
        { brand: { type: 'required', message: 'Required' } },
      ],
    }

    expect(collectErrorFieldNames(errors).sort()).toEqual([
      'cross_references.0.brand',
      'name',
      'parapharmacy_metadata.category',
    ])
  })

  it('returns nothing for an empty error object', () => {
    expect(collectErrorFieldNames({})).toEqual([])
    expect(collectErrorFieldNames(undefined)).toEqual([])
  })
})

describe('focusFirstInvalidField', () => {
  function buildForm(): HTMLFormElement {
    document.body.innerHTML = `
      <form id="f">
        <input name="name" />
        <input name="sku" />
        <select name="parapharmacy_metadata.category"></select>
      </form>
    `
    const form = document.getElementById('f')
    if (!(form instanceof HTMLFormElement)) throw new Error('fixture form missing')
    return form
  }

  it('moves to the first invalid control in DOM order, not in error-key order', () => {
    const form = buildForm()
    const scrollIntoView = vi.fn()
    window.HTMLElement.prototype.scrollIntoView = scrollIntoView

    const moved = focusFirstInvalidField(form, {
      parapharmacy_metadata: { category: { type: 'required' } },
      sku: { type: 'required' },
    })

    expect(moved).toBe(form.querySelector('[name="sku"]'))
    expect(document.activeElement).toBe(moved)
    expect(scrollIntoView).toHaveBeenCalled()
  })

  it('reaches a below-the-fold nested field when it is the only invalid one', () => {
    const form = buildForm()
    window.HTMLElement.prototype.scrollIntoView = vi.fn()

    const moved = focusFirstInvalidField(form, {
      parapharmacy_metadata: { category: { type: 'required' } },
    })

    expect(moved).toBe(form.querySelector('[name="parapharmacy_metadata.category"]'))
  })

  it('is a no-op without a form or without errors', () => {
    const form = buildForm()

    expect(focusFirstInvalidField(null, { sku: { type: 'required' } })).toBeNull()
    expect(focusFirstInvalidField(form, {})).toBeNull()
  })
})
