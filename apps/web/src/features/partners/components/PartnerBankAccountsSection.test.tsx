import { fireEvent, render, screen } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { describe, expect, it, vi } from 'vitest'
import type { PartnerFormData } from '../PartnerForm'
import { PartnerBankAccountsSection } from './PartnerBankAccountsSection'

vi.mock('@/hooks/useBanks', () => ({
  useBanks: () => ({ data: [], isLoading: false, isError: false }),
}))
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

function Harness() {
  const { control, register, setValue } = useForm<PartnerFormData>({
    defaultValues: {
      bank_accounts: [{
        label: '',
        bank_id: '',
        bank_name: '',
        rib: '',
        iban: '',
        bic: '',
        currency: 'EUR',
        is_primary: true,
      }],
    },
  })

  return (
    <PartnerBankAccountsSection
      control={control}
      register={register}
      setValue={setValue}
      country="FR"
      defaultCurrency="EUR"
    />
  )
}

describe('PartnerBankAccountsSection', () => {
  it('shows unsupported-country validation as informational', () => {
    render(<Harness />)

    fireEvent.change(screen.getByLabelText('partners.bankAccounts.rib'), {
      target: { value: '12345678901234567890' },
    })

    expect(screen.getByText('partners.bankAccounts.unsupportedCountry')).toBeInTheDocument()
    expect(screen.queryByText('partners.bankAccounts.invalidRib')).not.toBeInTheDocument()
  })
})
