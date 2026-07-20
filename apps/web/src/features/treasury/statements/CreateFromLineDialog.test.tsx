import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { CreateFromLineDialog } from './CreateFromLineDialog'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/features/finance/hooks/useAccounts', () => ({
  useAccounts: () => ({
    data: [{ id: 'account-1', code: '707', name: 'Product revenue' }],
    isLoading: false,
  }),
}))

describe('CreateFromLineDialog', () => {
  it('uses a revenue-account picker and validates it before income creation', async () => {
    const onSubmit = vi.fn()
    render(<CreateFromLineDialog isOpen direction="in" onClose={vi.fn()} onSubmit={onSubmit} />)

    const submit = screen.getByRole('button', { name: 'statements.workspace.create.submit' })
    fireEvent.click(submit)
    expect(await screen.findByText('statements.workspace.create.required')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: '707 · Product revenue' })).toBeInTheDocument()
    expect(onSubmit).not.toHaveBeenCalled()

    fireEvent.change(screen.getByRole('combobox', { name: /statements\.workspace\.create\.incomeAccount/ }), { target: { value: 'account-1' } })
    fireEvent.click(submit)
    await waitFor(() => expect(onSubmit).toHaveBeenCalledWith({
      action: 'create_income',
      params: { income_account_id: 'account-1', source_name: undefined, notes: undefined },
    }))
  })

  it('does not require an account for expense creation', () => {
    const onSubmit = vi.fn()
    render(<CreateFromLineDialog isOpen direction="out" onClose={vi.fn()} onSubmit={onSubmit} />)

    fireEvent.click(screen.getByRole('button', { name: 'statements.workspace.create.submit' }))
    return waitFor(() => expect(onSubmit).toHaveBeenCalledWith({
      action: 'create_expense',
      params: { vendor_name: undefined, notes: undefined },
    }))
  })
})
