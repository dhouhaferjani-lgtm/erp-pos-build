import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'

// ─── i18n: identity translator (returns the key, or the string fallback) ──────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── router: stub navigation + no edit-mode id ────────────────────────────────
vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  useParams: () => ({}),
}))

// ─── expense hooks: keep the page shell isolated from data fetching ────────────
vi.mock('../hooks/useExpenses', () => ({
  useExpense: () => ({ data: undefined, isLoading: false }),
  useCreateExpense: () => ({ mutateAsync: vi.fn(), isPending: false }),
  useUpdateExpense: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

// ─── child organism: stub so we only assert the page shell ────────────────────
vi.mock('../components/organisms/ExpenseFormFields', () => ({
  ExpenseFormFields: ({ onSubmit }: { onSubmit: (d: unknown) => void }) => (
    <form
      data-testid="expense-form-fields"
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit({})
      }}
    >
      <button type="submit">submit</button>
    </form>
  ),
}))

import { ExpenseFormPage } from './ExpenseFormPage'

describe('ExpenseFormPage shell', () => {
  it('renders exactly one <h1>', () => {
    render(<ExpenseFormPage />)
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
  })

  it('exposes a submit button of type="submit"', () => {
    render(<ExpenseFormPage />)
    const submit = screen.getByRole('button', { name: 'submit' })
    expect(submit).toHaveAttribute('type', 'submit')
  })
})
