import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'

// ─── i18n: identity translator ────────────────────────────────────────────────
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

// ─── income hooks: isolate the page shell from data fetching ──────────────────
const mutateAsync = vi.fn().mockResolvedValue({ id: 'new-income-id' })

vi.mock('../hooks/useIncome', () => ({
  useIncome: () => ({ data: undefined, isLoading: false }),
  useCreateIncome: () => ({ mutateAsync, isPending: false }),
  useUpdateIncome: () => ({ mutateAsync: vi.fn(), isPending: false }),
}))

// ─── child organism: stub so we only assert the page shell ────────────────────
vi.mock('../components/organisms/IncomeFormFields', () => ({
  IncomeFormFields: ({ onSubmit }: { onSubmit: (d: unknown) => void }) => (
    <form
      data-testid="income-form-fields"
      onSubmit={(e) => {
        e.preventDefault()
        onSubmit({ total: '100.000' })
      }}
    >
      <button type="submit">submit</button>
    </form>
  ),
}))

import { IncomeFormPage } from './IncomeFormPage'

// UUID v4 pattern
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i

describe('IncomeFormPage shell', () => {
  it('renders exactly one <h1>', () => {
    render(<IncomeFormPage />)
    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
  })

  it('exposes a submit button of type="submit"', () => {
    render(<IncomeFormPage />)
    const submit = screen.getByRole('button', { name: 'submit' })
    expect(submit).toHaveAttribute('type', 'submit')
  })

  it('includes a uuid idempotency_key in the create mutation payload', async () => {
    mutateAsync.mockClear()
    render(<IncomeFormPage />)
    fireEvent.click(screen.getByRole('button', { name: 'submit' }))

    await vi.waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1))

    const payload = mutateAsync.mock.calls[0][0] as Record<string, unknown>
    const key = payload['idempotency_key']
    expect(typeof key).toBe('string')
    expect(UUID_RE.test(key as string)).toBe(true)
  })

  it('reuses the same idempotency_key across multiple submit attempts', async () => {
    mutateAsync.mockClear()
    render(<IncomeFormPage />)
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
