import { useTranslation } from 'react-i18next'
import { AlertTriangle, Inbox } from 'lucide-react'

import { QueryError } from '@/components/QueryError'
import { StatusBadge } from '@/components/atoms'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable'
import { EmptyState } from '@/components/molecules/EmptyState'
import { PageHeader } from '@/components/molecules/PageHeader'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import {
  useLaneSeparationReport,
  type InvoicedNotDeliveredRow,
  type UninvoicedDeliveryNoteRow,
} from '../hooks/useLaneSeparationReport'

/**
 * The lane-separation reconciliation (DPA Wave 3 T24).
 *
 * Two mirrored populations where goods and money parted company:
 *
 *  - **Delivered, not invoiced** — the 418 year-end accrual's population.
 *  - **Invoiced, not delivered** — a LEGACY / PRE-POLICY register. Under
 *    `require_delivery_first` no new invoice can join it, so this list should
 *    only ever shrink. Every row shows the policy in force when it was posted,
 *    so an operator can tell a document that predates the rule from evidence of
 *    a posting path that escapes it.
 *
 * READ ONLY. Nothing on this page posts anything — the accrual and its reversal
 * are separate, deliberate operator actions.
 */
export function LaneSeparationReportPage() {
  const { t } = useTranslation(['finance', 'common'])
  const reportQuery = useLaneSeparationReport()

  const report = reportQuery.data
  const uninvoiced = report?.uninvoiced_delivery_notes ?? []
  const invoicedNotDelivered = report?.invoiced_not_delivered ?? []

  const policySourceLabel = report
    ? t('finance:laneSeparation.policySource.' + report.policy_source)
    : ''

  const uninvoicedColumns: DataTableColumn<UninvoicedDeliveryNoteRow>[] = [
    { key: 'document_number', header: t('finance:laneSeparation.columns.document') },
    { key: 'document_date', header: t('finance:laneSeparation.columns.date') },
    { key: 'partner_name', header: t('finance:laneSeparation.columns.partner') },
    { key: 'total', header: t('finance:laneSeparation.columns.total'), numeric: true },
  ]

  const legacyColumns: DataTableColumn<InvoicedNotDeliveredRow>[] = [
    { key: 'document_number', header: t('finance:laneSeparation.columns.document') },
    { key: 'document_date', header: t('finance:laneSeparation.columns.date') },
    { key: 'partner_name', header: t('finance:laneSeparation.columns.partner') },
    { key: 'total', header: t('finance:laneSeparation.columns.total'), numeric: true },
    {
      key: 'policy_at_post_time',
      header: t('finance:laneSeparation.columns.policyAtPostTime'),
      render: (row) => (
        <StatusBadge tone={row.policy_at_post_time === 'pre_policy' ? 'neutral' : 'warning'}>
          {row.policy_at_post_time === 'pre_policy'
            ? t('finance:laneSeparation.prePolicy')
            : row.policy_at_post_time}
        </StatusBadge>
      ),
    },
  ]

  if (reportQuery.isError) {
    return <QueryError error={reportQuery.error} onRetry={() => void reportQuery.refetch()} />
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('finance:laneSeparation.title')}
        subtitle={t('finance:laneSeparation.description')}
      />

      {report && (
        <p className={`text-sm ${textColors.tertiary}`} data-testid="lane-separation-policy">
          {t('finance:laneSeparation.policyInForce', {
            policy: t('finance:laneSeparation.policyValue.' + report.policy),
            source: policySourceLabel,
          })}
        </p>
      )}

      <section className="space-y-3">
        <h2 className={tokens.heading.section}>{t('finance:laneSeparation.uninvoicedTitle')}</h2>
        <p className={`text-sm ${textColors.tertiary}`}>{t('finance:laneSeparation.uninvoicedHelp')}</p>
        {uninvoiced.length === 0 && !reportQuery.isLoading ? (
          <EmptyState icon={<Inbox className={`mb-4 h-16 w-16 ${textColors.disabled}`} />} title={t('finance:laneSeparation.uninvoicedEmpty')} />
        ) : (
          <DataTable<UninvoicedDeliveryNoteRow>
            columns={uninvoicedColumns}
            data={uninvoiced}
            isLoading={reportQuery.isLoading}
            keyExtractor={(row) => row.id}
          />
        )}
      </section>

      <section className="space-y-3">
        <h2 className={tokens.heading.section}>{t('finance:laneSeparation.legacyTitle')}</h2>
        <div className={`rounded-lg border ${borderColors.default} p-4`}>
          <div className="flex gap-3">
            <AlertTriangle className={`h-5 w-5 flex-shrink-0 ${textColors.tertiary}`} />
            <p className={`text-sm ${textColors.tertiary}`}>{t('finance:laneSeparation.legacyHelp')}</p>
          </div>
        </div>
        {invoicedNotDelivered.length === 0 && !reportQuery.isLoading ? (
          <EmptyState icon={<Inbox className={`mb-4 h-16 w-16 ${textColors.disabled}`} />} title={t('finance:laneSeparation.legacyEmpty')} />
        ) : (
          <DataTable<InvoicedNotDeliveredRow>
            columns={legacyColumns}
            data={invoicedNotDelivered}
            isLoading={reportQuery.isLoading}
            keyExtractor={(row) => row.id}
          />
        )}
      </section>
    </div>
  )
}
