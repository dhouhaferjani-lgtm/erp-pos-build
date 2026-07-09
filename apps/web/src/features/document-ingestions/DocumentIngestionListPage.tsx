import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Eye, Upload } from 'lucide-react'
import { cn } from '@/lib/utils'
import { textColors, tokens, typography } from '@/lib/designTokens'
import { documentIngestionStatuses, useDocumentIngestions } from './queries'
import type { DocumentKind, DocumentIngestionSummary, IngestionStatus } from './types'

function statusTone(status: IngestionStatus): string {
  switch (status) {
    case 'uploaded':
    case 'extracting':
    case 'committing':
      return tokens.badge.blue
    case 'needs_review':
      return tokens.badge.yellow
    case 'committed':
      return tokens.badge.green
    case 'rejected':
    case 'failed':
      return tokens.badge.red
  }
}

function providerModel(row: DocumentIngestionSummary): string | null {
  return row.providerModel ?? row.provider_model ?? null
}

function confidence(row: DocumentIngestionSummary): number | null {
  return row.confidenceSummary?.averageConfidence ?? row.confidence_summary?.averageConfidence ?? null
}

export function DocumentIngestionListPage() {
  const { t } = useTranslation(['documentIngestions', 'common'])
  const [status, setStatus] = useState<IngestionStatus | ''>('')
  const [kind, setKind] = useState<DocumentKind | ''>('')
  const { data, isLoading } = useDocumentIngestions({ status, kind })

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className={cn(typography.fontSize['2xl'], typography.fontWeight.bold, textColors.primary)}>
            {t('list.title')}
          </h1>
          <p className={cn(typography.fontSize.sm, textColors.tertiary)}>{t('list.subtitle')}</p>
        </div>
        <Link
          to="/purchases/scans/new"
          className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
        >
          <Upload className="me-2 h-4 w-4" aria-hidden="true" />
          {t('actions.upload')}
        </Link>
      </div>

      <section className={cn(tokens.card.base, 'space-y-4')} aria-label={t('list.filters')}>
        <div className="grid gap-4 md:grid-cols-2">
          <div>
            <label className={tokens.label.base} htmlFor="status-filter">{t('fields.status')}</label>
            <select
              id="status-filter"
              className={tokens.select.base}
              value={status}
              onChange={(event) => { setStatus(event.target.value as IngestionStatus | '') }}
            >
              <option value="">{t('filters.allStatuses')}</option>
              {documentIngestionStatuses.map((item) => (
                <option key={item} value={item}>{t(`statuses.${item}`)}</option>
              ))}
            </select>
          </div>
          <div>
            <label className={tokens.label.base} htmlFor="kind-filter">{t('fields.kind')}</label>
            <select
              id="kind-filter"
              className={tokens.select.base}
              value={kind}
              onChange={(event) => { setKind(event.target.value as DocumentKind | '') }}
            >
              <option value="">{t('filters.allKinds')}</option>
              <option value="supplier_delivery_note">{t('kinds.supplier_delivery_note')}</option>
              <option value="supplier_invoice">{t('kinds.supplier_invoice')}</option>
            </select>
          </div>
        </div>
      </section>

      <section className={tokens.card.base} aria-label={t('list.title')}>
        {isLoading ? (
          <p>{t('common:status.loading')}</p>
        ) : (data?.data.length ?? 0) === 0 ? (
          <p>{t('list.empty')}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y">
              <thead className={tokens.table.header}>
                <tr>
                  <th className="px-4 py-3 text-start">{t('fields.kind')}</th>
                  <th className="px-4 py-3 text-start">{t('fields.status')}</th>
                  <th className="px-4 py-3 text-start">{t('fields.provider')}</th>
                  <th className="px-4 py-3 text-start">{t('fields.confidence')}</th>
                  <th className="px-4 py-3 text-end">{t('common:actions.actions')}</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {(data?.data ?? []).map((row) => {
                  const model = providerModel(row)
                  const confidenceValue = confidence(row)
                  return (
                    <tr key={row.id} className={tokens.table.rowHover}>
                      <td className="px-4 py-3">{t(`kinds.${row.kind}`)}</td>
                      <td className="px-4 py-3">
                        <span className={cn(tokens.badge.base, statusTone(row.status))}>
                          {t(`statuses.${row.status}`)}
                        </span>
                      </td>
                      <td className="px-4 py-3">{model ?? t('list.providerPending')}</td>
                      <td className="px-4 py-3">
                        {confidenceValue === null ? t('list.unknownConfidence') : t('list.confidencePercent', { value: Math.round(confidenceValue * 100) })}
                      </td>
                      <td className="px-4 py-3 text-end">
                        <Link
                          to={`/purchases/scans/${row.id}`}
                          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                        >
                          <Eye className="me-2 h-4 w-4" aria-hidden="true" />
                          {t('actions.review')}
                        </Link>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}
