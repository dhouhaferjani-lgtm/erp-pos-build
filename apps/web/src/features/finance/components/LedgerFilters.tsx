import { useTranslation } from 'react-i18next'
import { FormField, Input, Select } from '../../../components/atoms'
import type { Account, LedgerFilters as LedgerFiltersType } from '../types'

interface LedgerFiltersProps {
  filters: LedgerFiltersType
  accounts: Account[]
  onFiltersChange: (filters: LedgerFiltersType) => void
}

export function LedgerFilters({ filters, accounts, onFiltersChange }: LedgerFiltersProps) {
  const { t } = useTranslation(['finance'])
  const handleAccountChange = (accountId: string) => {
    onFiltersChange({
      ...filters,
      account_id: accountId || undefined,
    })
  }

  const handleDateFromChange = (date: string) => {
    onFiltersChange({
      ...filters,
      date_from: date || undefined,
    })
  }

  const handleDateToChange = (date: string) => {
    onFiltersChange({
      ...filters,
      date_to: date || undefined,
    })
  }

  return (
    <div className="grid w-full grid-cols-1 gap-4 md:grid-cols-3">
      <FormField label={t('finance:ledger.filters.account')} htmlFor="account">
        <Select
          id="account"
          value={filters.account_id ?? ''}
          onChange={(e) => { handleAccountChange(e.target.value); }}
        >
          <option value="">{t('finance:ledger.filters.allAccounts')}</option>
          {accounts.map((account) => (
            <option key={account.id} value={account.id}>
              {account.code} - {account.name}
            </option>
          ))}
        </Select>
      </FormField>

      <FormField label={t('finance:ledger.filters.fromDate')} htmlFor="date-from">
        <Input
          id="date-from"
          type="date"
          value={filters.date_from ?? ''}
          onChange={(e) => { handleDateFromChange(e.target.value); }}
        />
      </FormField>

      <FormField label={t('finance:ledger.filters.toDate')} htmlFor="date-to">
        <Input
          id="date-to"
          type="date"
          value={filters.date_to ?? ''}
          onChange={(e) => { handleDateToChange(e.target.value); }}
        />
      </FormField>
    </div>
  )
}
