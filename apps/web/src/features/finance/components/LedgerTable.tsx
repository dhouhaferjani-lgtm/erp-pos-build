import { useTranslation } from 'react-i18next'
import type { LedgerLine } from '../types'

interface LedgerTableProps {
  lines: LedgerLine[]
}

export function LedgerTable({ lines }: LedgerTableProps) {
  const { t } = useTranslation(['finance'])

  if (lines.length === 0) {
    return (
      <div className="px-6 py-12 text-center text-sm text-gray-500">
        {t('finance:ledger.empty')}
      </div>
    )
  }

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-gray-200">
        <thead className="bg-gray-50">
          <tr>
            <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('finance:ledger.columns.date')}
            </th>
            <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('finance:ledger.columns.entryNumber')}
            </th>
            <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('finance:ledger.columns.account')}
            </th>
            <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('finance:ledger.columns.description')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('finance:ledger.columns.debit')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('finance:ledger.columns.credit')}
            </th>
            <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('finance:ledger.columns.balance')}
            </th>
          </tr>
        </thead>
        <tbody className="bg-white divide-y divide-gray-200">
          {lines.map((line) => (
            <tr key={line.id} className="hover:bg-gray-50">
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                {new Date(line.date).toLocaleDateString()}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                {line.entry_number}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                <div>
                  <div className="font-medium">{line.account_code}</div>
                  <div className="text-gray-500">{line.account_name}</div>
                </div>
              </td>
              <td className="px-6 py-4 text-sm text-gray-900">
                {line.description}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-end font-mono">
                {line.debit !== '0.00' && line.debit !== '0' ? `$${line.debit}` : ''}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-end font-mono">
                {line.credit !== '0.00' && line.credit !== '0' ? `$${line.credit}` : ''}
              </td>
              <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-end font-mono">
                ${line.balance}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
