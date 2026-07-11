import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { tokens , semanticColorTokens as colorTokens } from '@/lib/designTokens'
import type { VoucherLedgerRow } from '../types/voucher'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

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
  issued: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
  redeemed: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  partially_redeemed: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  voided: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
  expired: `${colorTokens.intent.notice.bgSoft} ${colorTokens.intent.notice.textStronger}`,
  reversed: `${colorTokens.intent.verified.bgSoft} ${colorTokens.intent.verified.textStronger}`,
  transferred: `${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`,
  rounding_adjustment: `${colorTokens.surface.muted} ${colorTokens.text.secondary}`,
  // Codex review m2: administrative expiry-extension event — purple, same
  // hue as the legacy "Extended" alias so the visual contract is unchanged.
  expiry_extended: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
  // Legacy PascalCase aliases — kept so pre-R3 fixtures don't grey out.
  Issued: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
  Redeemed: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
  Refunded: `${colorTokens.intent.verified.bgSoft} ${colorTokens.intent.verified.textStronger}`,
  Voided: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
  Expired: `${colorTokens.intent.notice.bgSoft} ${colorTokens.intent.notice.textStronger}`,
  Extended: `${colorTokens.intent.accent.bgSoft} ${colorTokens.intent.accent.textStronger}`,
  Transferred: `${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`,
  Adjusted: `${colorTokens.surface.muted} ${colorTokens.text.secondary}`,
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
    <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
      <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
        <thead className={tokens.table.header}>
          <tr>
            <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
              {t('vouchers:ledger.occurredAt')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
              {t('vouchers:ledger.event')}
            </th>
            <th className={`px-4 py-3 text-right text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
              {t('vouchers:ledger.amount')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
              {t('vouchers:ledger.receipt')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
              {t('vouchers:ledger.terminal')}
            </th>
            <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase tracking-wider`}>
              {t('vouchers:ledger.user')}
            </th>
          </tr>
        </thead>
        <tbody className={`bg-white divide-y ${colorTokens.border.divider}`}>
          {rows.map((row) => {
            const positive = isPositiveEvent(row.event)
            return (
              <tr key={row.id} className={tokens.table.rowHover}>
                <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary} whitespace-nowrap`}>
                  {new Date(row.occurred_at).toLocaleString()}
                </td>
                <td className="px-4 py-3">
                  <span
                    className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${EVENT_BADGE_CLASSES[row.event] ?? `${colorTokens.surface.muted} ${colorTokens.text.secondary}`}`}
                    data-testid={`event-badge-${row.event.toLowerCase()}`}
                  >
                    {t(`vouchers:events.${row.event}`)}
                  </span>
                </td>
                <td className="px-4 py-3 text-sm text-right whitespace-nowrap font-mono">
                  <span className={positive ? `${colorTokens.intent.success.textStrong}` : `${colorTokens.intent.danger.textStrong}`}>
                    {positive ? '+' : '−'}
                    {row.amount} {currency}
                  </span>
                </td>
                <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                  {row.receipt_id ? (
                    <Link
                      to={`/pos/receipts/${row.receipt_id}`}
                      className={`${colorTokens.intent.primary.text} hover:underline`}
                    >
                      {row.receipt_number ?? row.receipt_id}
                    </Link>
                  ) : (
                    '—'
                  )}
                </td>
                <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                  {row.terminal_name ?? row.terminal_id ?? '—'}
                </td>
                <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                  {row.user_name ?? row.user_id ?? '—'}
                </td>
              </tr>
            )
          })}
        </tbody>
      </DataTable>
    </div>
  )
}
