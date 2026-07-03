import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'

// ─── i18n: identity translator ────────────────────────────────────────────────
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// ─── currency + data hooks ────────────────────────────────────────────────────
vi.mock('../../../../hooks/useCurrency', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../../hooks/useCurrency')>()
  return {
    ...actual,
    useCurrency: () => ({ currency: 'TND' }),
  }
})

vi.mock('../../../finance/hooks/useAccounts', () => ({
  useAccounts: () => ({
    data: [{ id: 'acc-rev-1', code: '706', name: 'Prestations de services', type: 'revenue' }],
    isLoading: false,
  }),
}))

vi.mock('../../../treasury/hooks/usePaymentMethods', () => ({
  useActivePaymentMethods: () => ({ data: [], isLoading: false }),
}))

vi.mock('../../../treasury/hooks/usePaymentRepositories', () => ({
  useActivePaymentRepositories: () => ({ data: [], isLoading: false }),
}))

import { IncomeFormFields } from './IncomeFormFields'

describe('IncomeFormFields', () => {
  it('renders the income-account (class-7 revenue) select with fetched options', () => {
    render(<IncomeFormFields onSubmit={vi.fn()} />)
    const select = screen.getByRole('combobox', { name: /incomeAccount/i })
    expect(select).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /706/ })).toBeInTheDocument()
  })

  it('emits the money amount as a canonical string (never a JS number)', async () => {
    const onSubmit = vi.fn()
    render(<IncomeFormFields onSubmit={onSubmit} />)

    const amount = screen.getByLabelText(/income:form.amount/i)
    fireEvent.change(amount, { target: { value: '150.750' } })

    fireEvent.click(screen.getByRole('button', { name: 'common:save' }))

    await vi.waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    const payload = onSubmit.mock.calls[0][0] as Record<string, unknown>
    expect(payload['total']).toBe('150.750')
    expect(typeof payload['total']).toBe('string')
  })
})
