import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { tokens } from '@/lib/designTokens'
import type { VoucherLedgerRow } from '../types/voucher'

interface LedgerHistoryTableProps {
  rows: VoucherLedgerRow[]
  currency: string
}

const EVENT_BADGE_CLASSES: Record<string, string> = {
  Issued: 'bg-green-100 text-green-800',
  Redeemed: 'bg-blue-100 text-blue-800',
  Refunded: 'bg-indigo-100 text-indigo-800',
  Voided: 'bg-red-100 text-red-800',
  Expired: 'bg-orange-100 text-orange-800',
  Extended: 'bg-purple-100 text-purple-800',
  Transferred: 'bg-yellow-100 text-yellow-800',
  Adjusted: 'bg-gray-100 text-gray-700',
}

function isPositiveEvent(event: string): boolean {
  return ['Issued', 'Refunded', 'Adjusted'].includes(event)
}

export function LedgerHistoryTable({ rows, currency }: LedgerHistoryTableProps) {
  const { t } = useTranslation(['vouchers', 'common'])

  return (
    <div className="overflow-x-auto rounded-lg border border-gray-200">
      <table className="min-w-full divide-y divide-gray-200">
        <thead className={tokens.table.header}>
          <tr>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('vouchers:ledger.occurredAt')}
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('vouchers:ledger.event')}
            </th>
            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('vouchers:ledger.amount')}
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('vouchers:ledger.receipt')}
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('vouchers:ledger.terminal')}
            </th>
            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
              {t('vouchers:ledger.user')}
            </th>
          </tr>
        </thead>
        <tbody className="bg-white divide-y divide-gray-200">
          {rows.map((row) => {
            const positive = isPositiveEvent(row.event)
            return (
              <tr key={row.id} className={tokens.table.rowHover}>
                <td className="px-4 py-3 text-sm text-gray-700 whitespace-nowrap">
                  {new Date(row.occurred_at).toLocaleString()}
                </td>
                <td className="px-4 py-3">
                  <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${EVENT_BADGE_CLASSES[row.event] ?? 'bg-gray-100 text-gray-700'}`}
                    data-testid={`event-badge-${row.event.toLowerCase()}`}
                  >
                    {t(`vouchers:events.${row.event}`)}
                  </span>
                </td>
                <td className="px-4 py-3 text-sm text-right whitespace-nowrap font-mono">
                  <span className={positive ? 'text-green-700' : 'text-red-700'}>
                    {positive ? '+' : '−'}
                    {row.amount} {currency}
                  </span>
                </td>
                <td className="px-4 py-3 text-sm text-gray-700">
                  {row.receipt_id ? (
                    <Link
                      to={`/pos/receipts/${row.receipt_id}`}
                      className="text-blue-600 hover:underline"
                    >
                      {row.receipt_number ?? row.receipt_id}
                    </Link>
                  ) : (
                    '—'
                  )}
                </td>
                <td className="px-4 py-3 text-sm text-gray-700">
                  {row.terminal_name ?? row.terminal_id ?? '—'}
                </td>
                <td className="px-4 py-3 text-sm text-gray-700">
                  {row.user_name ?? row.user_id ?? '—'}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
