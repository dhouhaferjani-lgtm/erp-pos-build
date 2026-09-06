import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {

  FileText,
  Plus,
  AlertCircle,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  ChevronRight,
  Link2,
  ScanLine,
} from 'lucide-react'
import { tokens, textColors, borderColors } from '../../../lib/designTokens'
import { Button } from '../../../components/atoms/Button/Button'
import { Input } from '../../../components/atoms/Input/Input'
import { Select } from '../../../components/atoms/Select/Select'
import { StatusBadge, type StatusTone } from '../../../components/atoms/StatusBadge/StatusBadge'
import { DataTable, type DataTableColumn } from '../../../components/molecules/DataTable/DataTable'
import { PageHeader } from '../../../components/molecules/PageHeader/PageHeader'
import { SearchInput } from '../../../components/molecules/SearchInput/SearchInput'
import { formatCurrency } from '../../../lib/decimal'
import { formatDate } from '../../../lib/format'
import { usePermissions } from '../../../hooks/usePermissions'
import type { SupplierInvoiceListItem, SupplierInvoiceListParams, SupplierInvoiceMatchStatus, SupplierInvoiceStatus } from './types'
import { useSupplierInvoiceList } from './api'

const matchTone: Record<SupplierInvoiceMatchStatus, StatusTone> = {
  matched: 'success',
  price_variance: 'warning',
  qty_blocked: 'danger',
  unmatched: 'neutral',
}

const matchIcon = {
  matched: CheckCircle2,
  price_variance: AlertTriangle,
  qty_blocked: XCircle,
  unmatched: AlertCircle,
} satisfies Record<SupplierInvoiceMatchStatus, typeof CheckCircle2>

const invoiceTone: Record<SupplierInvoiceStatus, StatusTone> = {
  draft: 'pending',
  posted: 'info',
  paid: 'success',
}

// ── Match Status Badge ─────────────────────────────────────────────────────

interface MatchStatusBadgeProps {
  status: SupplierInvoiceMatchStatus
  invoiceId: string
}

function MatchStatusBadge({ status, invoiceId }: MatchStatusBadgeProps) {
  const { t } = useTranslation(['purchases'])

  const Icon = matchIcon[status]

  return (
    <span data-testid={`match-badge-${invoiceId}`}>
      <StatusBadge tone={matchTone[status]} className="gap-1">
        <Icon className="h-3 w-3" />
        {t(`purchases:supplierInvoices.matchStatus.${status}`)}
      </StatusBadge>
    </span>
  )
}

// ── Invoice Status Badge ───────────────────────────────────────────────────

function InvoiceStatusBadge({ status }: { status: SupplierInvoiceStatus }) {
  const { t } = useTranslation(['purchases'])

  return (
    <StatusBadge tone={invoiceTone[status]}>
      {t(`purchases:supplierInvoices.status.${status}`)}
    </StatusBadge>
  )
}

// ── Page ───────────────────────────────────────────────────────────────────

export function SupplierInvoiceListPage() {
  const { t } = useTranslation(['common', 'purchases', 'documentIngestions'])
  const { hasPermission } = usePermissions()

  const [params, setParams] = useState<SupplierInvoiceListParams>({})

  const { data, isLoading } = useSupplierInvoiceList(params)

  const invoices = data?.data ?? []

  const columns: DataTableColumn<SupplierInvoiceListItem>[] = [
    {
      key: 'number',
      header: t('purchases:supplierInvoices.columns.number'),
      render: (invoice) => (
        <div className="flex flex-wrap items-center gap-2">
          <span className={tokens.table.cellMonoBadge}>{invoice.number}</span>
          {invoice.pending_receipt === true ? (
            <span data-testid={`pending-receipt-badge-${invoice.id}`}>
              <StatusBadge tone="warning" className="gap-1">
                <Link2 className="h-3 w-3" />
                {t('purchases:supplierInvoices.pendingReceipt.badge')}
              </StatusBadge>
            </span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'partner',
      header: t('purchases:supplierInvoices.columns.partner'),
      accessor: (invoice) => invoice.partner.name,
    },
    {
      key: 'issueDate',
      header: t('purchases:supplierInvoices.columns.issueDate'),
      accessor: (invoice) => formatDate(invoice.issue_date),
    },
    {
      key: 'total',
      header: t('purchases:supplierInvoices.columns.total'),
      numeric: true,
      accessor: (invoice) => formatCurrency(invoice.total, true, invoice.currency),
    },
    {
      key: 'status',
      header: t('purchases:supplierInvoices.columns.status'),
      render: (invoice) => <InvoiceStatusBadge status={invoice.status} />,
    },
    {
      key: 'matchStatus',
      header: t('purchases:supplierInvoices.columns.matchStatus'),
      render: (invoice) => (
        <MatchStatusBadge
          status={invoice.match_status}
          invoiceId={invoice.id}
        />
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('common:actions.view')}</span>,
      align: 'right',
      render: (invoice) => (
        <Link
          to={`/purchases/supplier-invoices/${invoice.id}`}
          className={`inline-flex items-center gap-1 font-medium ${textColors.brand}`}
        >
          {t('common:actions.view')}
          <ChevronRight className="h-4 w-4" />
        </Link>
      ),
    },
  ]

  function handleFilterChange(
    key: keyof SupplierInvoiceListParams,
    value: string | undefined
  ) {
    setParams((prev) => {
      // Reset cursor on filter change so we go back to the first page.
      const next: SupplierInvoiceListParams = { ...prev }
      delete next['cursor']
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
      <PageHeader
        title={t('purchases:supplierInvoices.title')}
        subtitle={t('purchases:supplierInvoices.description')}
        actions={
          <>
            {hasPermission('document-ingestions.view') && (
              <Link
                to="/purchases/scans/new?kind=supplier_invoice"
                className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
              >
                <ScanLine className="me-2 h-4 w-4" />
                {t('documentIngestions:actions.scanInvoice')}
              </Link>
            )}
            {/* F-W2-14 gate r1 finding 4: the "New supplier invoice" link carried
                no permission condition at all, so a role without
                supplier-invoices.manage was offered a page whose only action the
                API refuses. Same gate as the route and as POST /supplier-invoices. */}
            {hasPermission('supplier-invoices.manage') && (
              <Link
                to="/purchases/supplier-invoices/new"
                className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}
              >
                <Plus className="me-2 h-4 w-4" />
                {t('purchases:supplierInvoices.new')}
              </Link>
            )}
          </>
        }
        className="mb-0"
      />

      {/* Filters */}
      <div className={`rounded-lg ${borderColors.light} border bg-white p-4`}>
        <div className="mb-4">
          <SearchInput
            value={params.search ?? ''}
            onChange={(value) => { handleFilterChange('search', value === '' ? undefined : value); }}
            placeholder={t('common:actions.search')}
            className="w-full sm:w-96"
          />
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {/* Status filter */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-status">
              {t('purchases:supplierInvoices.filters.status')}
            </label>
            <Select
              id="filter-status"
              data-testid="filter-status"
              className="mt-1"
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
            </Select>
          </div>

          {/* Match status filter */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-match-status">
              {t('purchases:supplierInvoices.filters.matchStatus')}
            </label>
            <Select
              id="filter-match-status"
              data-testid="filter-match-status"
              className="mt-1"
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
            </Select>
          </div>

          {/* Date from */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-date-from">
              {t('purchases:supplierInvoices.filters.dateFrom')}
            </label>
            <Input
              id="filter-date-from"
              data-testid="filter-date-from"
              type="date"
              className="mt-1"
              value={params.date_from ?? ''}
              onChange={(e) => { handleFilterChange('date_from', e.target.value); }}
            />
          </div>

          <div>
            <label className={tokens.label.base} htmlFor="filter-pending-receipt">
              {t('purchases:supplierInvoices.filters.pendingReceipt')}
            </label>
            <Select
              id="filter-pending-receipt"
              data-testid="filter-pending-receipt"
              className="mt-1"
              value={params.pending_receipt ?? ''}
              onChange={(e) => { handleFilterChange('pending_receipt', e.target.value); }}
            >
              <option value="">
                {t('purchases:supplierInvoices.filters.pendingReceiptPlaceholder')}
              </option>
              <option value="1">
                {t('purchases:supplierInvoices.pendingReceipt.badge')}
              </option>
            </Select>
          </div>

          {/* Date to */}
          <div>
            <label className={tokens.label.base} htmlFor="filter-date-to">
              {t('purchases:supplierInvoices.filters.dateTo')}
            </label>
            <Input
              id="filter-date-to"
              data-testid="filter-date-to"
              type="date"
              className="mt-1"
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
          <DataTable
            columns={columns}
            data={invoices}
            keyExtractor={(invoice) => invoice.id}
          />

          {/* Cursor-based pagination — backend returns links.next/prev cursor tokens */}
          {(data?.links?.prev ?? data?.links?.next) && (
            <div className={`flex items-center justify-end gap-2 border-t ${borderColors.light} bg-white px-6 py-3`}>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                disabled={!data?.links?.prev}
                onClick={() => { handleFilterChange('cursor', data?.links?.prev ?? undefined); }}
              >
                {t('common:actions.previous')}
              </Button>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                disabled={!data?.meta?.has_more}
                onClick={() => { handleFilterChange('cursor', data?.links?.next ?? undefined); }}
              >
                {t('common:actions.next')}
              </Button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}
