import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { tokens } from '@/lib/designTokens'
import type { VoucherLedgerRow } from '../types/voucher'

interface LedgerHistoryTableProps {
  rows: VoucherLedgerRow[]
  currency: string
}

/**
 * Codex review R3 (2026-04-30): the backend's `formatLedger()` returns
 * lowercase storage values from the `VoucherEvent` enum (`issued`,
 * `redeemed`, `voided`, `transferred`, `expiry_extended`, etc.). Until R3
 * landed, this map keyed off the PascalCase TS union so every real backend
 * row fell through to the grey default. We now key the lowercase wire
 * values, and keep the PascalCase aliases pointing to the same classes so
 * pre-R3 hand-fed test fixtures keep rendering with a coloured badge while
 * the migration completes.
 */
const EVENT_BADGE_CLASSES: Record<string, string> = {
  // Canonical lowercase wire values (backend formatLedger output).
  issued: 'bg-green-100 text-green-800',
  redeemed: 'bg-blue-100 text-blue-800',
  partially_redeemed: 'bg-blue-100 text-blue-800',
  voided: 'bg-red-100 text-red-800',
  expired: 'bg-orange-100 text-orange-800',
  reversed: 'bg-indigo-100 text-indigo-800',
  transferred: 'bg-yellow-100 text-yellow-800',
  rounding_adjustment: 'bg-gray-100 text-gray-700',
  // Codex review m2: administrative expiry-extension event — purple, same
  // hue as the legacy "Extended" alias so the visual contract is unchanged.
  expiry_extended: 'bg-purple-100 text-purple-800',
  // Legacy PascalCase aliases — kept so pre-R3 fixtures don't grey out.
  Issued: 'bg-green-100 text-green-800',
  Redeemed: 'bg-blue-100 text-blue-800',
  Refunded: 'bg-indigo-100 text-indigo-800',
  Voided: 'bg-red-100 text-red-800',
  Expired: 'bg-orange-100 text-orange-800',
  Extended: 'bg-purple-100 text-purple-800',
  Transferred: 'bg-yellow-100 text-yellow-800',
  Adjusted: 'bg-gray-100 text-gray-700',
}

/**
 * Codex review R3 (2026-04-30): mirror the case-canonicalization in
 * EVENT_BADGE_CLASSES — `issued` is the wire value, `Issued` the legacy
 * fixture value, and both must be classified as positive (green +).
 */
function isPositiveEvent(event: string): boolean {
  return ['issued', 'Issued', 'Refunded', 'Adjusted'].includes(event)
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
