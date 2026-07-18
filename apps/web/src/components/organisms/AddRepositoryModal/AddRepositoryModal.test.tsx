import { beforeEach, describe, expect, it, vi } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { mechanicCompanyConfig } from '@/test/fixtures/companyConfig'
import { AddRepositoryModal } from './AddRepositoryModal'

const mockApiPost = vi.hoisted(() => vi.fn())
const mockUseBanks = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiPost: mockApiPost,
  }
})

vi.mock('@/hooks/useBanks', () => ({
  useBanks: mockUseBanks,
}))

vi.mock('@/features/finance/hooks/useAccounts', () => ({
  useAccounts: () => ({ data: [] }),
}))
vi.mock('@/features/locations/hooks/useLocations', () => ({
  useLocations: () => ({ data: [{ id: 'loc-a', name: 'Store A', isActive: true }] }),
}))

const amenBank = {
  id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
  country_code: 'TN',
  name: 'AMEN BANK',
  short_name: 'AMEN',
  bic: 'CFCTTNTT',
  rib_bank_code: '07',
  city: 'TUNIS',
  is_custom: false,
}

describe('AddRepositoryModal bank account flow', () => {
  beforeEach(() => {
    mockApiPost.mockReset()
    mockUseBanks.mockReset()
    mockUseBanks.mockReturnValue({ data: [amenBank], isLoading: false, isError: false })
    mockApiPost.mockResolvedValue({
      id: 'repository-1',
      code: 'BANK-01',
      name: 'Main bank',
      type: 'bank_account',
      bank_id: amenBank.id,
      bank_name: amenBank.name,
      account_number: '07040005810111129653',
      iban: 'TN5907040005810111129653',
      bic: amenBank.bic,
      balance: '0.000',
      is_active: true,
      gl_account_id: null,
    })
  })

  it('selects a directory bank, autofills BIC, derives IBAN, and persists bank_id', async () => {
    const user = userEvent.setup()
    renderWithProviders(<AddRepositoryModal isOpen onClose={() => undefined} />, {
      companyConfig: mechanicCompanyConfig,
    })

    await user.type(screen.getByLabelText(/^Code/), 'BANK-01')
    await user.type(screen.getByLabelText(/^Name/), 'Main bank')
    await user.selectOptions(screen.getByLabelText(/^Type/), 'bank_account')

    const bankSearch = screen.getByRole('combobox', { name: 'Bank' })
    await user.type(bankSearch, 'Amen')

    await waitFor(() => {
      expect(mockUseBanks).toHaveBeenLastCalledWith(expect.objectContaining({ query: 'Amen' }))
      expect(screen.getByRole('option', { name: /AMEN BANK/ })).toBeInTheDocument()
    })
    await user.click(screen.getByRole('option', { name: /AMEN BANK/ }))

    expect(screen.getByLabelText('BIC/SWIFT')).toHaveValue('CFCTTNTT')

    await user.type(screen.getByLabelText('Account'), '07040005810111129653')

    expect(await screen.findByText('Valid Tunisian RIB')).toBeInTheDocument()
    expect(screen.getByLabelText('IBAN')).toHaveValue('TN5907040005810111129653')

    await user.click(screen.getByRole('button', { name: 'Save' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/payment-repositories', expect.objectContaining({
        bank_id: amenBank.id,
        bank_name: amenBank.name,
        bic: amenBank.bic,
      }))
    })
  })

  it('shows an invalid RIB warning without disabling Save', async () => {
    const user = userEvent.setup()
    renderWithProviders(<AddRepositoryModal isOpen onClose={() => undefined} />, {
      companyConfig: mechanicCompanyConfig,
    })

    await user.selectOptions(screen.getByLabelText(/^Type/), 'bank_account')
    await user.type(screen.getByLabelText('Account'), '07040005810111129654')

    expect(await screen.findByText('RIB checksum could not be verified. You can still save.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled()
  })

  it('shows unsupported-country validation as informational', async () => {
    const user = userEvent.setup()
    renderWithProviders(<AddRepositoryModal isOpen onClose={() => undefined} />, {
      companyConfig: { ...mechanicCompanyConfig, country_code: 'FR', currency: 'EUR' },
    })

    await user.selectOptions(screen.getByLabelText(/^Type/), 'bank_account')
    await user.type(screen.getByLabelText('Account'), '12345678901234567890')

    expect(await screen.findByText('Automatic RIB validation is not available for this country.')).toBeInTheDocument()
    expect(screen.queryByText('RIB checksum could not be verified. You can still save.')).not.toBeInTheDocument()
  })

  it('assigns a cash register to a selected location', async () => {
    const user = userEvent.setup()
    renderWithProviders(<AddRepositoryModal isOpen onClose={() => undefined} />, { companyConfig: mechanicCompanyConfig })
    await user.type(screen.getByLabelText(/^Code/), 'CR-01')
    await user.type(screen.getByLabelText(/^Name/), 'Store register')
    await user.selectOptions(screen.getByLabelText(/Location/), 'loc-a')
    await user.click(screen.getByRole('button', { name: 'Save' }))
    await waitFor(() => expect(mockApiPost).toHaveBeenCalledWith('/payment-repositories', expect.objectContaining({ location_id: 'loc-a' })))
  })
})
