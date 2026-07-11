import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'

// ─── i18n: identity translator (returns the key, or the string fallback) ──────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── router: stub navigation + no edit-mode id ────────────────────────────────
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({}),
}))

// ─── expense hooks: keep the page shell isolated from data fetching ────────────
const mutateAsync = vi.fn().mockResolvedValue({ id: 'new-expense-id' })

vi.mock('../hooks/useExpenses', () => ({
  useExpense: () => ({ data: undefined, isLoading: false }),
  useCreateExpense: () => ({ mutateAsync, isPending: false }),
  useUpdateExpense: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

// ─── child organism: stub so we only assert the page shell ────────────────────
vi.mock('../components/organisms/ExpenseFormFields', () => ({
  ExpenseFormFields: ({ onSave }: { onSave: (d: unknown) => void }) => (
    <form
      data-testid="expense-form-fields"
      onSubmit={(e) => {
        e.preventDefault()
        onSave({ total: '100.000' })
      }}
    >
      <button type="submit">submit</button>
    </form>
  ),
}))

import { ExpenseFormPage } from './ExpenseFormPage'

// UUID v4 pattern
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

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

  it('includes a uuid idempotency_key in the create mutation payload', async () => {
    mutateAsync.mockClear()
    render(<ExpenseFormPage />)
    const submitBtn = screen.getByRole('button', { name: 'submit' })
    fireEvent.click(submitBtn)

    // Wait for the async handler to settle
    await vi.waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))

    const payload = mutateAsync.mock.calls[0][0] as Record<string, unknown>
    const key = payload['idempotency_key']
    expect(typeof key).toBe('string')
    expect(UUID_RE.test(key as string)).toBe(true)
  })

  it('reuses the same idempotency_key across multiple submit attempts', async () => {
    mutateAsync.mockClear()
    render(<ExpenseFormPage />)
    const submitBtn = screen.getByRole('button', { name: 'submit' })

    fireEvent.click(submitBtn)
    await vi.waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))
    const firstKey = (mutateAsync.mock.calls[0][0] as Record<string, unknown>)['idempotency_key']

    fireEvent.click(submitBtn)
    await vi.waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(2))
    const secondKey = (mutateAsync.mock.calls[1][0] as Record<string, unknown>)['idempotency_key']

    expect(firstKey).toBe(secondKey)
  })
})
