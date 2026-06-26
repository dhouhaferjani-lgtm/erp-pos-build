import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  FileText,
  AlertCircle,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  ChevronRight,
} from 'lucide-react'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { formatCurrency } from '../../../lib/decimal'
import type { SupplierInvoiceListParams, SupplierInvoiceMatchStatus, SupplierInvoiceStatus } from './types'
import { useSupplierInvoiceList } from './api'

// ── Match Status Badge ─────────────────────────────────────────────────────

interface MatchStatusBadgeProps {
  status: SupplierInvoiceMatchStatus
  invoiceId: string
}

function MatchStatusBadge({ status, invoiceId }: MatchStatusBadgeProps) {
  const { t } = useTranslation(['purchases'])

  const badgeClass = (() => {
    switch (status) {
      case 'matched':
        return `${tokens.badge.base} ${tokens.badge.green}`
      case 'price_variance':
        return `${tokens.badge.base} ${tokens.badge.yellow}`
      case 'qty_blocked':
        return `${tokens.badge.base} ${tokens.badge.red}`
      case 'unmatched':
        return `${tokens.badge.base} ${tokens.badge.gray}`
    }
  })()

  const Icon = (() => {
    switch (status) {
      case 'matched':
        return CheckCircle2
      case 'price_variance':
        return AlertTriangle
      case 'qty_blocked':
        return XCircle
      case 'unmatched':
        return AlertCircle
    }
  })()

  return (
    <span className={badgeClass} data-testid={`match-badge-${invoiceId}`}>
      <Icon className="me-1 h-3 w-3" />
      {t(`purchases:supplierInvoices.matchStatus.${status}`)}
    </span>
  )
}

// ── Invoice Status Badge ───────────────────────────────────────────────────

function InvoiceStatusBadge({ status }: { status: SupplierInvoiceStatus }) {
  const { t } = useTranslation(['purchases'])

  const cls = (() => {
    switch (status) {
      case 'draft':
        return `${tokens.badge.base} ${tokens.badge.gray}`
      case 'posted':
        return `${tokens.badge.base} ${tokens.badge.blue}`
      case 'paid':
        return `${tokens.badge.base} ${tokens.badge.green}`
    }
  })()

  return (
    <span className={cls}>
      {t(`purchases:supplierInvoices.status.${status}`)}
    </span>
  )
}

// ── Page ───────────────────────────────────────────────────────────────────

export function SupplierInvoiceListPage() {
  const { t } = useTranslation(['common', 'purchases'])

  const [params, setParams] = useState<SupplierInvoiceListParams>({
    page: 1,
  })

  const { data, isLoading } = useSupplierInvoiceList(params)

  const invoices = data?.data ?? []

  function handleFilterChange(
    key: keyof SupplierInvoiceListParams,
    value: string | number | undefined
  ) {
    setParams((prev) => {
      const next: SupplierInvoiceListParams = { ...prev, page: 1 }
      if (value === '' || value === undefined) {
        delete next[key]
      } else {
        // Safe cast: callers are responsible for passing the correct type per key.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        ;(next as any)[key] = value
      }
      return next
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className={`text-2xl font-bold ${textColors.primary}`}>
            {t('purchases:supplierInvoices.title')}
          </h1>
          <p className={`mt-1 text-sm ${textColors.tertiary}`}>
            {t('purchases:supplierInvoices.description')}
          </p>
        </div>
      </div>

      {/* Filters */}
      <div className={`rounded-lg ${borderColors.light} border bg-white p-4`}>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {/* Status filter */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-status">
              {t('purchases:supplierInvoices.filters.status')}
            </label>
            <select
              id="filter-status"
              data-testid="filter-status"
              className={`${tokens.select.base} mt-1`}
              value={params.status ?? ''}
              onChange={(e) =>
                { handleFilterChange('status', e.target.value); }
              }
            >
              <option value="">
                {t('purchases:supplierInvoices.filters.statusPlaceholder')}
              </option>
              <option value="draft">{t('purchases:supplierInvoices.status.draft')}</option>
              <option value="posted">{t('purchases:supplierInvoices.status.posted')}</option>
              <option value="paid">{t('purchases:supplierInvoices.status.paid')}</option>
            </select>
          </div>

          {/* Match status filter */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-match-status">
              {t('purchases:supplierInvoices.filters.matchStatus')}
            </label>
            <select
              id="filter-match-status"
              data-testid="filter-match-status"
              className={`${tokens.select.base} mt-1`}
              value={params.match_status ?? ''}
              onChange={(e) =>
                { handleFilterChange('match_status', e.target.value); }
              }
            >
              <option value="">
                {t('purchases:supplierInvoices.filters.matchStatusPlaceholder')}
              </option>
              <option value="matched">
                {t('purchases:supplierInvoices.matchStatus.matched')}
              </option>
              <option value="price_variance">
                {t('purchases:supplierInvoices.matchStatus.price_variance')}
              </option>
              <option value="qty_blocked">
                {t('purchases:supplierInvoices.matchStatus.qty_blocked')}
              </option>
              <option value="unmatched">
                {t('purchases:supplierInvoices.matchStatus.unmatched')}
              </option>
            </select>
          </div>

          {/* Date from */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-date-from">
              {t('purchases:supplierInvoices.filters.dateFrom')}
            </label>
            <input
              id="filter-date-from"
              data-testid="filter-date-from"
              type="date"
              className={`${tokens.input.base} mt-1`}
              value={params.date_from ?? ''}
              onChange={(e) => { handleFilterChange('date_from', e.target.value); }}
            />
          </div>

          {/* Date to */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-date-to">
              {t('purchases:supplierInvoices.filters.dateTo')}
            </label>
            <input
              id="filter-date-to"
              data-testid="filter-date-to"
              type="date"
              className={`${tokens.input.base} mt-1`}
              value={params.date_to ?? ''}
              onChange={(e) => { handleFilterChange('date_to', e.target.value); }}
            />
          </div>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className={textColors.tertiary}>{t('common:status.loading')}</div>
        </div>
      ) : invoices.length === 0 ? (
        <div className={`rounded-lg border ${borderColors.light} bg-white p-12 text-center`}>
          <FileText className={`mx-auto h-12 w-12 ${textColors.disabled}`} />
          <h3 className={`mt-4 text-lg font-medium ${textColors.primary}`}>
            {t('purchases:supplierInvoices.empty')}
          </h3>
          <p className={`mt-2 text-sm ${textColors.tertiary}`}>
            {t('purchases:supplierInvoices.emptyHint')}
          </p>
        </div>
      ) : (
        <div className={`overflow-hidden rounded-lg border ${borderColors.light} bg-white`}>
          <table className="min-w-full divide-y divide-gray-200">
            <thead className={tokens.table.header}>
              <tr>
                <th
                  scope="col"
                  className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('purchases:supplierInvoices.columns.number')}
                </th>
                <th
                  scope="col"
                  className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('purchases:supplierInvoices.columns.partner')}
                </th>
                <th
                  scope="col"
                  className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('purchases:supplierInvoices.columns.issueDate')}
                </th>
                <th
                  scope="col"
                  className={`px-6 py-3 text-end text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('purchases:supplierInvoices.columns.total')}
                </th>
                <th
                  scope="col"
                  className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('purchases:supplierInvoices.columns.status')}
                </th>
                <th
                  scope="col"
                  className={`px-6 py-3 text-start text-xs font-medium uppercase tracking-wider ${textColors.tertiary}`}
                >
                  {t('purchases:supplierInvoices.columns.matchStatus')}
                </th>
                <th scope="col" className="relative px-6 py-3">
                  <span className="sr-only">{t('common:actions.view')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {invoices.map((invoice) => (
                <tr key={invoice.id} className={tokens.table.rowHover}>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span className={tokens.table.cellMonoBadge}>{invoice.number}</span>
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.primary}`}>
                    {invoice.partner.name}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${textColors.tertiary}`}>
                    {new Date(invoice.issue_date).toLocaleDateString()}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-end text-sm font-medium ${textColors.primary}`}>
                    {formatCurrency(invoice.total, true, invoice.currency)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <InvoiceStatusBadge status={invoice.status} />
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <MatchStatusBadge
                      status={invoice.match_status}
                      invoiceId={invoice.id}
                    />
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                    <Link
                      to={`/purchases/supplier-invoices/${invoice.id}`}
                      className={`inline-flex items-center gap-1 font-medium ${textColors.brand}`}
                    >
                      {t('common:actions.view')}
                      <ChevronRight className="h-4 w-4" />
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {/* Pagination */}
          {data?.meta && data.meta.last_page > 1 && (
            <div className={`flex items-center justify-between border-t ${borderColors.light} bg-white px-6 py-3`}>
              <div className={`text-sm ${textColors.tertiary}`}>
                {t('common:pagination.showing', {
                  from: (data.meta.current_page - 1) * data.meta.per_page + 1,
                  to: Math.min(data.meta.current_page * data.meta.per_page, data.meta.total),
                  total: data.meta.total,
                })}
              </div>
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={data.meta.current_page <= 1}
                  onClick={() => { handleFilterChange('page', data.meta.current_page - 1); }}
                  className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                >
                  {t('common:actions.previous')}
                </button>
                <button
                  type="button"
                  disabled={data.meta.current_page >= data.meta.last_page}
                  onClick={() => { handleFilterChange('page', data.meta.current_page + 1); }}
                  className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
                >
                  {t('common:actions.next')}
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
