/**
 * Helpers for surfacing a BLOCKED form submit.
 *
 * BUG-003: react-hook-form aborts `handleSubmit` when validation fails and,
 * by default, does nothing visible. If the offending field is below the fold
 * the operator sees no toast, no scroll and no network request — the Save
 * button just appears dead.
 *
 * These helpers are field-agnostic on purpose: they resolve the first invalid
 * control in DOM order from whatever `errors` object react-hook-form produced,
 * so any newly added required field is covered without a code change.
 */

interface FieldErrorLike {
  type?: unknown
  message?: unknown
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

/**
 * A leaf is react-hook-form's `FieldError` — it carries `type` (and usually
 * `message`). Nested field groups and field arrays are plain containers.
 */
function isFieldErrorLeaf(value: unknown): value is FieldErrorLike {
  return isRecord(value) && typeof value['type'] === 'string'
}

/**
 * Flatten a react-hook-form `errors` object into the dotted field paths that
 * `register()` uses as the control's `name` attribute
 * (e.g. `parapharmacy_metadata.category`, `cross_references.0.brand`).
 */
export function collectErrorFieldNames(errors: unknown, prefix = ''): string[] {
  if (!isRecord(errors)) {
    return []
  }

  const names: string[] = []
  for (const [key, value] of Object.entries(errors)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (isFieldErrorLeaf(value)) {
      names.push(path)
      continue
    }
    names.push(...collectErrorFieldNames(value, path))
  }
  return names
}

/**
 * Scroll to, and focus, the first invalid control of `form` in DOM order.
 *
 * DOM order (not the key order of the `errors` object) is what matches the
 * operator's reading order, so they are taken to the topmost problem first.
 *
 * Returns the element it moved to, or `null` when nothing matched.
 */
export function focusFirstInvalidField(
  form: HTMLFormElement | null,
  errors: unknown,
): HTMLElement | null {
  if (form === null) {
    return null
  }

  const invalidNames = new Set(collectErrorFieldNames(errors))
  if (invalidNames.size === 0) {
    return null
  }

  for (const element of form.querySelectorAll<HTMLElement>('[name]')) {
    const name = element.getAttribute('name')
    if (name === null || !invalidNames.has(name)) {
      continue
    }

    // jsdom does not implement scrollIntoView; guard so tests and older
    // engines degrade to focus-only rather than throwing inside the handler.
    if (typeof element.scrollIntoView === 'function') {
      element.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
    element.focus({ preventScroll: true })
    return element
  }

  return null
}
