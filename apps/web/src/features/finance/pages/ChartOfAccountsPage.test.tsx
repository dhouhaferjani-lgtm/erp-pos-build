import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import type { Account } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
    i18n: { language: 'en' },
  }),
}))

const useAccountsMock = vi.fn()
vi.mock('../hooks/useAccounts', () => ({
  useAccounts: () => useAccountsMock() as unknown,
}))

// Keep the page test light: stub the child modals so we only render the page shell.
vi.mock('../components/AddAccountModal', () => ({
  AddAccountModal: ({ open }: { open: boolean }) =>
    open ? <div data-testid="add-account-modal" /> : null,
}))
vi.mock('../components/EditAccountModal', () => ({
  EditAccountModal: ({ open }: { open: boolean }) =>
    open ? <div data-testid="edit-account-modal" /> : null,
}))

// AccountTreeView pulls in useCompany / formatCurrency — stub it for the page test.
vi.mock('../components/AccountTreeView', () => ({
  AccountTreeView: () => <div data-testid="account-tree-view" />,
}))

import { ChartOfAccountsPage } from './ChartOfAccountsPage'

function makeAccount(overrides: Partial<Account> = {}): Account {
  return {
    id: 'acc-1',
    tenant_id: 't-1',
    parent_id: null,
    code: '1000',
    name: 'Cash',
    type: 'asset',
    description: null,
    is_active: true,
    is_system: false,
    balance: '0.000',
    created_at: '2026-06-14T00:00:00Z',
    updated_at: '2026-06-14T00:00:00Z',
    ...overrides,
  }
}

describe('ChartOfAccountsPage', () => {
  beforeEach(() => {
    useAccountsMock.mockReset()
  })

  it('renders exactly one h1', () => {
    useAccountsMock.mockReturnValue({
      data: [makeAccount()],
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(<ChartOfAccountsPage />)

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
  })

  it('renders the add control as a button', () => {
    useAccountsMock.mockReturnValue({
      data: [makeAccount()],
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(<ChartOfAccountsPage />)

    const addButton = screen.getByRole('button', {
      name: /chartOfAccounts\.addAccount/i,
    })
    expect(addButton.tagName).toBe('BUTTON')
  })

  it('opens the add modal when the add control is clicked', async () => {
    const { default: userEvent } = await import('@testing-library/user-event')
    useAccountsMock.mockReturnValue({
      data: [makeAccount()],
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    render(<ChartOfAccountsPage />)

    const user = userEvent.setup()
    await user.click(
      screen.getByRole('button', { name: /chartOfAccounts\.addAccount/i }),
    )

    expect(screen.getByTestId('add-account-modal')).toBeInTheDocument()
  })
})
