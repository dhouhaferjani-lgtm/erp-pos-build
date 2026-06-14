import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { format } from 'date-fns'
import { ArrowLeft, FileCheck } from 'lucide-react'
import { useJournalEntry } from '../hooks/useJournalEntries'
import { usePostJournalEntry } from '../hooks/useJournalEntryMutations'
import type { JournalEntryStatus } from '../types'
import { cn } from '../../../lib/utils'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { Button } from '../../../components/atoms'
import {
  StatusBadge,
  statusTone,
  type StatusTone,
} from '../../../components/atoms/StatusBadge'
import { PageHeader } from '../../../components/molecules/PageHeader'

/**
 * Journal-entry status tone overrides for the shared StatusBadge.
 * - `draft`  → `pending` (built-in map already covers it; kept explicit for clarity)
 * - `posted` → `success` (built-in map has no `posted`, so override to success)
 * `reversed` is not part of the frontend `JournalEntryStatus` union yet
 * (see types.ts drift note) — when it lands, map it to `neutral` here.
 */
const statusToneOverrides: Record<string, StatusTone> = {
  posted: 'success',
}

export function JournalEntryDetailPage() {
  const { t } = useTranslation(['finance', 'common'])
  const { id } = useParams<{ id: string }>()
  const { data: entry, isLoading } = useJournalEntry(id)
  const postMutation = usePostJournalEntry()

  const handlePost = () => {
    if (id) {
      postMutation.mutate(id)
    }
  }

  const getStatusLabel = (status: JournalEntryStatus) =>
    t(`finance:journalEntry.status.${status}`)

  if (isLoading) {
    return (
      <div className="flex min-h-[400px] items-center justify-center">
        <p className={textColors.tertiary}>{t('common:status.loading')}</p>
      </div>
    )
  }

  if (!entry) {
    return (
      <div className="flex min-h-[400px] flex-col items-center justify-center">
        <p className={cn('mb-4', textColors.tertiary)}>
          {t('finance:journalEntry.notFound')}
        </p>
        <Link
          to="/finance/journal-entries"
          className={cn(textColors.brand, textColors.hoverPrimary)}
        >
          {t('finance:journalEntry.backToList')}
        </Link>
      </div>
    )
  }

  const totalDebits = entry.lines.reduce(
    (sum, line) => sum + parseFloat(line.debit),
    0
  )
  const totalCredits = entry.lines.reduce(
    (sum, line) => sum + parseFloat(line.credit),
    0
  )

  const formatCurrency = (amount: number | string) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount
    return new Intl.NumberFormat('en-US', {
      style: 'decimal',
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num)
  }

  const backLink = (
    <Link
      to="/finance/journal-entries"
      className={cn(
        'inline-flex items-center gap-2 text-sm',
        textColors.tertiary,
        textColors.hoverPrimary,
      )}
    >
      <ArrowLeft className="h-4 w-4" />
      {t('common:back')}
    </Link>
  )

  return (
    <div className="space-y-6">
      <PageHeader
        title={entry.entry_number}
        breadcrumb={backLink}
        subtitle={
          entry.description || t('finance:journalEntry.noDescription')
        }
        actions={
          <>
            <StatusBadge tone={statusTone(entry.status, statusToneOverrides)}>
              {getStatusLabel(entry.status)}
            </StatusBadge>
            {entry.status === 'draft' && (
              <Button
                variant="primary"
                onClick={handlePost}
                disabled={postMutation.isPending}
              >
                <FileCheck className="me-2 h-4 w-4" />
                {postMutation.isPending
                  ? t('common:status.processing')
                  : t('finance:journalEntry.post')}
              </Button>
            )}
          </>
        }
      />

      <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
        <div className={tokens.card.base}>
          <h3 className={cn('mb-1 text-sm font-medium', textColors.tertiary)}>
            {t('finance:journalEntry.entryDate')}
          </h3>
          <p className={cn('text-lg font-semibold', textColors.primary)}>
            {format(new Date(entry.entry_date), 'MMMM d, yyyy')}
          </p>
        </div>
        <div className={tokens.card.base}>
          <h3 className={cn('mb-1 text-sm font-medium', textColors.tertiary)}>
            {t('common:fields.created')}
          </h3>
          <p className={cn('text-lg font-semibold', textColors.primary)}>
            {format(new Date(entry.created_at), 'MMMM d, yyyy h:mm a')}
          </p>
        </div>
        <div className={tokens.card.base}>
          <h3 className={cn('mb-1 text-sm font-medium', textColors.tertiary)}>
            {t('finance:journalEntry.linesCount')}
          </h3>
          <p className={cn('text-lg font-semibold', textColors.primary)}>
            {entry.lines.length} {t('finance:journalEntry.lines')}
          </p>
        </div>
      </div>

      <div
        className={cn(
          'overflow-hidden rounded-lg border bg-white',
          borderColors.light,
        )}
      >
        <div className={cn('border-b px-6 py-4', borderColors.light)}>
          <h2 className={cn(tokens.heading.section, 'mb-0')}>
            {t('finance:journalEntry.lines')}
          </h2>
        </div>
        <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
          <thead className={tokens.table.header}>
            <tr>
              <th
                className={cn(
                  'px-6 py-3 text-start text-xs font-medium uppercase tracking-wider',
                  textColors.tertiary,
                )}
              >
                {t('finance:journalEntry.account')}
              </th>
              <th
                className={cn(
                  'px-6 py-3 text-end text-xs font-medium uppercase tracking-wider',
                  textColors.tertiary,
                )}
              >
                {t('finance:journalEntry.debit')}
              </th>
              <th
                className={cn(
                  'px-6 py-3 text-end text-xs font-medium uppercase tracking-wider',
                  textColors.tertiary,
                )}
              >
                {t('finance:journalEntry.credit')}
              </th>
              <th
                className={cn(
                  'px-6 py-3 text-start text-xs font-medium uppercase tracking-wider',
                  textColors.tertiary,
                )}
              >
                {t('finance:journalEntry.lineDescription')}
              </th>
            </tr>
          </thead>
          <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
            {entry.lines.map((line) => (
              <tr key={line.id} className={tokens.table.rowHover}>
                <td className="px-6 py-4">
                  <div>
                    <span className={cn('font-mono text-sm', textColors.tertiary)}>
                      {line.account_code}
                    </span>
                    <span className={cn('ms-2 text-sm', textColors.primary)}>
                      {line.account_name}
                    </span>
                  </div>
                </td>
                <td className="px-6 py-4 text-end text-sm tabular-nums">
                  {parseFloat(line.debit) > 0 ? (
                    <span className={cn('font-medium', textColors.primary)}>
                      {formatCurrency(line.debit)}
                    </span>
                  ) : (
                    <span className={textColors.disabled}>-</span>
                  )}
                </td>
                <td className="px-6 py-4 text-end text-sm tabular-nums">
                  {parseFloat(line.credit) > 0 ? (
                    <span className={cn('font-medium', textColors.primary)}>
                      {formatCurrency(line.credit)}
                    </span>
                  ) : (
                    <span className={textColors.disabled}>-</span>
                  )}
                </td>
                <td className={cn('px-6 py-4 text-sm', textColors.tertiary)}>
                  {line.description || '-'}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot className={tokens.table.header}>
            <tr className="font-medium">
              <td className={cn('px-6 py-4 text-sm', textColors.primary)}>
                {t('finance:journalEntry.totals')}
              </td>
              <td
                className={cn(
                  'px-6 py-4 text-end text-sm tabular-nums',
                  textColors.primary,
                )}
              >
                {formatCurrency(totalDebits)}
              </td>
              <td
                className={cn(
                  'px-6 py-4 text-end text-sm tabular-nums',
                  textColors.primary,
                )}
              >
                {formatCurrency(totalCredits)}
              </td>
              <td className="px-6 py-4"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      {postMutation.error && (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('finance:journalEntry.postError')}
        </div>
      )}

      {postMutation.isSuccess && (
        <div className={cn(tokens.alert.base, tokens.alert.success)}>
          {t('finance:journalEntry.postSuccess')}
        </div>
      )}
    </div>
  )
}
