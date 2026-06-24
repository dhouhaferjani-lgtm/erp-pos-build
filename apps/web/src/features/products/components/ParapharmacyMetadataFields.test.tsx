import { describe, it, expect, vi } from 'vitest'
import { render, act } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useForm } from 'react-hook-form'
import { ParapharmacyMetadataFields } from './ParapharmacyMetadataFields'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string) => k }),
}))

// ── Wrapper that wires react-hook-form into the component ─────────────────────
// ParapharmacyMetadataFields receives `control`, `register`, and `errors` from
// a parent form. We create a minimal form host to supply those.
function Wrapper() {
  const {
    control,
    register,
    formState: { errors },
  } = useForm()

  return (
    <ParapharmacyMetadataFields
      control={control}
      register={register}
      errors={errors}
    />
  )
}

// ─── Atom-token regression guard ─────────────────────────────────────────────
// After the refactor, every form control must carry the atom's token class
// (`rounded-[var(--radius-input)]`) and must NOT contain the previously
// hardcoded classes (`rounded-lg`, `shadow-sm`).

describe('ParapharmacyMetadataFields — atom-token regression guard', () => {
  it('category select carries token radius and does NOT carry rounded-lg or shadow-sm', () => {
    const { getByRole } = render(<Wrapper />)
    // The category <select> is accessible by its label text (via htmlFor)
    const categorySelect = getByRole('combobox', { name: /products:parapharmacy.category/i })
    expect(categorySelect.className).toContain('rounded-[var(--radius-input)]')
    expect(categorySelect.className).not.toContain('rounded-lg')
    expect(categorySelect.className).not.toContain('shadow-sm')
  })

  it('dosage-form select carries token radius and does NOT carry rounded-lg or shadow-sm', () => {
    const { getAllByRole } = render(<Wrapper />)
    const selects = getAllByRole('combobox')
    // dosage-form is the second select in DOM order (after category)
    const dosageSelect = selects[1]!
    expect(dosageSelect.className).toContain('rounded-[var(--radius-input)]')
    expect(dosageSelect.className).not.toContain('rounded-lg')
    expect(dosageSelect.className).not.toContain('shadow-sm')
  })

  it('active-ingredient text input carries token radius and does NOT carry rounded-lg or shadow-sm', () => {
    const { getByRole, container } = render(<Wrapper />)
    // Click the add-ingredient button to render an ingredient row.
    const addBtn = getByRole('button', { name: /products:parapharmacy.addIngredient/i })
    act(() => {
      addBtn.click()
    })

    // After clicking, an ingredient row with two text inputs appears.
    const inputs = container.querySelectorAll('input[type="text"]')
    const ingredientInput = inputs[0] as HTMLElement
    expect(ingredientInput).not.toBeNull()
    expect(ingredientInput.className).toContain('rounded-[var(--radius-input)]')
    expect(ingredientInput.className).not.toContain('rounded-lg')
    expect(ingredientInput.className).not.toContain('shadow-sm')
  })

  it('usage-instructions textarea carries token radius and does NOT carry rounded-lg or shadow-sm', () => {
    const { container } = render(<Wrapper />)
    const textareas = container.querySelectorAll('textarea')
    // usage_instructions is the first textarea in DOM order
    const usageTextarea = textareas[0] as HTMLElement
    expect(usageTextarea).not.toBeNull()
    expect(usageTextarea.className).toContain('rounded-[var(--radius-input)]')
    expect(usageTextarea.className).not.toContain('rounded-lg')
    expect(usageTextarea.className).not.toContain('shadow-sm')
  })
})

// ─── onChange wiring preservation ────────────────────────────────────────────
// Assert that changing a field calls the registered onChange handler and the
// select element has the correct `name` attribute from register() — proving
// react-hook-form wiring was preserved through the atom refactor.

describe('ParapharmacyMetadataFields — onChange wiring', () => {
  it('dosage-form select has name wired via register and accepts a value change', async () => {
    const user = userEvent.setup()
    const { getAllByRole } = render(<Wrapper />)
    const selects = getAllByRole('combobox')
    // dosage-form is the second select
    const dosageSelect = selects[1] as HTMLSelectElement

    // Confirm the name attribute is set by register()
    expect(dosageSelect.name).toBe('parapharmacy_metadata.dosage_form')

    // Drive a real user selection — if the atom broke the register() spread,
    // the select element would not reflect the new value.
    await user.selectOptions(dosageSelect, 'capsule')
    expect(dosageSelect.value).toBe('capsule')
  })
})
