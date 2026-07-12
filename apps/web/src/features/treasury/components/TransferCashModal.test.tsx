import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { TransferCashModal } from './TransferCashModal'

interface TransferPayload {
  from_repository_id: string
  to_repository_id: string
  amount: string
  notes?: string
  transfer_group_id: string
}

interface MutationOptions {
  onSuccess?: () => void
  onError?: (error: unknown) => void
}

const mockMutate = vi.hoisted(() => vi.fn<(
  payload: TransferPayload,
  options?: MutationOptions,
) => void>())
const mockToastSuccess = vi.hoisted(() => vi.fn())
const mockToastError = vi.hoisted(() => vi.fn())
const mockRandomUUID = vi.hoisted(() => vi.fn(() => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'))

vi.stubGlobal('crypto', { randomUUID: mockRandomUUID })

const translatedValidation: Record<string, string> = {
  'treasury:repositories.transfer.zeroAmount': 'Translated positive amount',
  'treasury:repositories.transfer.invalidAmount': 'Translated precision amount',
  'treasury:repositories.transfer.sameRepository': 'Translated different repositories',
}

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => translatedValidation[key] ?? key }),
}))

vi.mock('sonner', () => ({
  toast: { success: mockToastSuccess, error: mockToastError },
}))

vi.mock('../hooks/usePaymentRepositories', () => ({
  useActivePaymentRepositories: () => ({
    data: [
      { id: '11111111-1111-4111-8111-111111111111', code: 'CASH', name: 'Main till', type: 'cash_register', is_active: true, balance: '100.000', currency: 'TND' },
      { id: '22222222-2222-4222-8222-222222222222', code: 'BANK', name: 'Main bank', type: 'bank_account', is_active: true, balance: '25.000', currency: 'TND' },
      { id: '33333333-3333-4333-8333-333333333333', code: 'EUR', name: 'Euro safe', type: 'safe', is_active: true, balance: '50.00', currency: 'EUR' },
      { id: '44444444-4444-4444-8444-444444444444', code: 'VIRTUAL', name: 'Virtual', type: 'virtual', is_active: true, balance: '0.000', currency: 'TND' },
    ],
    isLoading: false,
  }),
}))

vi.mock('../hooks/useTransferCash', () => ({
  useTransferCash: () => ({ mutate: mockMutate, isPending: false }),
}))

describe('TransferCashModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('filters virtual, selected-source, and different-currency repositories and binds MoneyInput currency', async () => {
    const user = userEvent.setup()
    render(<TransferCashModal isOpen onClose={vi.fn()} />)

    const from = screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.from/ })
    const to = screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.to/ })
    expect(within(from).queryByRole('option', { name: /Virtual/ })).not.toBeInTheDocument()

    await user.selectOptions(from, '11111111-1111-4111-8111-111111111111')

    expect(within(to).getByRole('option', { name: /Main bank/ })).toBeInTheDocument()
    expect(within(to).queryByRole('option', { name: /Main till/ })).not.toBeInTheDocument()
    expect(within(to).queryByRole('option', { name: /Euro safe/ })).not.toBeInTheDocument()
    expect(screen.getByRole('spinbutton', { name: /treasury:repositories\.transfer\.amount/ })).toHaveAttribute('step', '0.001')
    expect(screen.getAllByText(/100[,.]000 TND/).length).toBeGreaterThan(0)
  })

  it('rejects zero and four-decimal amounts without submitting', async () => {
    const user = userEvent.setup()
    render(<TransferCashModal isOpen onClose={vi.fn()} />)

    await user.selectOptions(screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.from/ }), '11111111-1111-4111-8111-111111111111')
    await user.selectOptions(screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.to/ }), '22222222-2222-4222-8222-222222222222')
    const amount = screen.getByRole('spinbutton', { name: /treasury:repositories\.transfer\.amount/ })

    await user.type(amount, '0')
    await user.click(screen.getByRole('button', { name: 'treasury:repositories.transfer.submit' }))
    expect(await screen.findByText('Translated positive amount')).toBeInTheDocument()
    expect(mockMutate).not.toHaveBeenCalled()

    await user.clear(amount)
    await user.type(amount, '1.0001')
    await user.click(screen.getByRole('button', { name: 'treasury:repositories.transfer.submit' }))
    expect(await screen.findByText('Translated precision amount')).toBeInTheDocument()
    expect(mockMutate).not.toHaveBeenCalled()
  })

  it('renders a translated same-repository validation error', async () => {
    const user = userEvent.setup()
    render(<TransferCashModal isOpen onClose={vi.fn()} />)

    const sourceId = '11111111-1111-4111-8111-111111111111'
    await user.selectOptions(
      screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.from/ }),
      sourceId,
    )
    const destination = screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.to/ })
    destination.append(new Option('Duplicate source', sourceId))
    await user.selectOptions(destination, sourceId)
    await user.type(
      screen.getByRole('spinbutton', { name: /treasury:repositories\.transfer\.amount/ }),
      '1.000',
    )
    await user.click(screen.getByRole('button', { name: 'treasury:repositories.transfer.submit' }))

    expect(await screen.findByText('Translated different repositories')).toBeInTheDocument()
    expect(mockMutate).not.toHaveBeenCalled()
  })

  it('reuses one transfer UUID across submits and closes after success', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    const onSuccess = vi.fn()
    mockMutate.mockImplementation((_payload, options) => {
      options?.onSuccess?.()
    })
    render(<TransferCashModal isOpen onClose={onClose} onSuccess={onSuccess} />)

    await user.selectOptions(screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.from/ }), '11111111-1111-4111-8111-111111111111')
    await user.selectOptions(screen.getByRole('combobox', { name: /treasury:repositories\.transfer\.to/ }), '22222222-2222-4222-8222-222222222222')
    await user.type(screen.getByRole('spinbutton', { name: /treasury:repositories\.transfer\.amount/ }), '10.000')
    await user.type(screen.getByRole('textbox', { name: 'treasury:repositories.transfer.notes' }), 'Till sweep')

    const submit = screen.getByRole('button', { name: 'treasury:repositories.transfer.submit' })
    await user.click(submit)
    await user.click(submit)

    await waitFor(() => {
      expect(mockMutate).toHaveBeenCalledTimes(2)
    })
    expect(mockMutate.mock.calls[0]?.[0].transfer_group_id).toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
    expect(mockMutate.mock.calls[1]?.[0].transfer_group_id).toBe('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
    expect(mockRandomUUID).toHaveBeenCalledTimes(1)
    expect(mockToastSuccess).toHaveBeenCalledWith('treasury:repositories.transfer.success')
    expect(onSuccess).toHaveBeenCalledTimes(2)
    expect(onClose).toHaveBeenCalledTimes(2)
  })
})
