import { useTranslation } from 'react-i18next'
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
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label htmlFor="account" className="block text-sm font-medium text-gray-700 mb-1">
          {t('finance:ledger.filters.account')}
        </label>
        <select
          id="account"
          value={filters.account_id || ''}
          onChange={(e) => { handleAccountChange(e.target.value); }}
          className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">{t('finance:ledger.filters.allAccounts')}</option>
          {accounts.map((account) => (
            <option key={account.id} value={account.id}>
              {account.code} - {account.name}
            </option>
          ))}
        </select>
      </div>

      <div>
        <label htmlFor="date-from" className="block text-sm font-medium text-gray-700 mb-1">
          {t('finance:ledger.filters.fromDate')}
        </label>
        <input
          id="date-from"
          type="date"
          value={filters.date_from || ''}
          onChange={(e) => { handleDateFromChange(e.target.value); }}
          className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>

      <div>
        <label htmlFor="date-to" className="block text-sm font-medium text-gray-700 mb-1">
          {t('finance:ledger.filters.toDate')}
        </label>
        <input
          id="date-to"
          type="date"
          value={filters.date_to || ''}
          onChange={(e) => { handleDateToChange(e.target.value); }}
          className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        />
      </div>
    </div>
  )
}
