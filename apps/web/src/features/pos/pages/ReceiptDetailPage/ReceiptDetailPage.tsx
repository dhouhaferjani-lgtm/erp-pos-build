import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Download, Printer } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Button, Spinner, StatusBadge } from '@/components/atoms'
import { DataTable, EmptyState, PageHeader, type DataTableColumn } from '@/components/molecules'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { formatQuantity } from '@/lib/decimal'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { formatCurrency, formatDateTime } from '@/lib/format'
import { getQuantityDecimals } from '@/lib/quantityScale'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { cn } from '@/lib/utils'
import { useCompanyStore } from '@/stores/companyStore'
import { getReceipt, type ReceiptDetail } from '../../api/receiptApi'
import { usePosTenantScope } from '../../hooks/usePosTenantScope'
import { useReceiptPrint } from '../../hooks/useReceiptPrint'

type DetailLine = ReceiptDetail['lines'][number]
type VatDetail = ReceiptDetail['vat_details'][number]
type PaymentDetail = ReceiptDetail['payments'][number]
type ReprintAction = 'print' | 'download'

const sectionClass = cn('rounded-lg border p-5 shadow-sm', colorTokens.border.subtle, colorTokens.surface.base)

export function ReceiptDetailPage() {
  const { id = '' } = useParams<{ id: string }>()
  const { t } = useTranslation(['pos', 'common'])
  const { hasTenantScope } = usePosTenantScope()
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const activeCompany = useCompanyStore((state) => (
    state.companies.find((company) => company.id === currentCompanyId) ?? null
  ))
  const [reprintAction, setReprintAction] = useState<ReprintAction | null>(null)
  const receiptPrint = useReceiptPrint()

  const receiptQuery = useQuery({
    queryKey: tenantScopedKey(['pos', 'receipts', 'detail', id]),
    queryFn: () => getReceipt(id),
    enabled: hasTenantScope && activeCompany !== null && id !== '',
  })

  if (!hasTenantScope) {
    return (
      <EmptyState
        title={t('pos:receipts.empty.noScopeTitle')}
        description={t('pos:receipts.empty.noScopeDescription')}
      />
    )
  }

  if (!activeCompany) return <Spinner fullScreen message={t('common:loading')} />
  if (receiptQuery.isLoading) return <Spinner fullScreen message={t('common:loading')} />
  if (receiptQuery.isError || !receiptQuery.data) {
    return (
      <EmptyState
        title={t('pos:receiptReporting.detail.notFoundTitle')}
        description={t('pos:receiptReporting.detail.notFoundDescription')}
      />
    )
  }

  const receipt = receiptQuery.data
  const dateOptions = { timeZone: activeCompany.timezone }

  const lineColumns: DataTableColumn<DetailLine>[] = [
    {
      key: 'product',
      header: t('pos:receiptReporting.detail.product'),
      render: (line) => (
        <div>
          <div className={cn('font-medium', colorTokens.text.primary)}>{line.product_name}</div>
          <div className={cn('font-mono text-xs', colorTokens.text.subtle)}>{line.product_code}</div>
        </div>
      ),
    },
    {
      key: 'quantity',
      header: t('pos:receiptReporting.detail.quantity'),
      numeric: true,
      render: (line) => formatQuantity(line.quantity, getQuantityDecimals(line)),
    },
    {
      key: 'unit_price',
      header: t('pos:receiptReporting.detail.unitPriceInclVat'),
      numeric: true,
      render: (line) => formatCurrency(line.unit_price, { currency: receipt.currency }),
    },
    {
      key: 'vat_rate',
      header: t('pos:receiptReporting.detail.vatRate'),
      numeric: true,
      render: (line) => t('pos:receiptReporting.detail.percentValue', { value: line.vat_rate }),
    },
    {
      key: 'line_total',
      header: t('pos:receiptReporting.detail.lineNet'),
      numeric: true,
      render: (line) => formatCurrency(line.line_total, { currency: receipt.currency }),
    },
    {
      key: 'returned_quantity',
      header: t('pos:receiptReporting.detail.returnedQuantity'),
      numeric: true,
      render: (line) => formatQuantity(line.returned_quantity, getQuantityDecimals(line)),
    },
  ]

  const vatColumns: DataTableColumn<VatDetail>[] = [
    { key: 'rate', header: t('pos:receiptReporting.detail.vatRate'), render: (row) => t('pos:receiptReporting.detail.percentValue', { value: row.tax_rate }) },
    { key: 'net', header: t('pos:receiptReporting.detail.netAmount'), numeric: true, render: (row) => formatCurrency(row.net_amount, { currency: receipt.currency }) },
    { key: 'vat', header: t('pos:receiptReporting.detail.vatAmount'), numeric: true, render: (row) => formatCurrency(row.vat_amount, { currency: receipt.currency }) },
    { key: 'gross', header: t('pos:receiptReporting.detail.grossAmount'), numeric: true, render: (row) => formatCurrency(row.gross_amount, { currency: receipt.currency }) },
  ]

  const paymentColumns: DataTableColumn<PaymentDetail>[] = [
    { key: 'method', header: t('pos:receiptReporting.detail.paymentMethod'), accessor: (row) => row.payment_method },
    { key: 'card', header: t('pos:receiptReporting.detail.cardLastFour'), accessor: (row) => row.card_last_four ?? t('common:notAvailable') },
    { key: 'instrument', header: t('pos:receiptReporting.detail.instrumentSerial'), accessor: (row) => row.instrument_serial ?? t('common:notAvailable') },
    { key: 'reference', header: t('pos:receiptReporting.detail.reference'), accessor: (row) => row.transaction_reference ?? t('common:notAvailable') },
    { key: 'authorization', header: t('pos:receiptReporting.detail.authorizationCode'), accessor: (row) => row.authorization_code ?? t('common:notAvailable') },
    { key: 'amount', header: t('pos:receiptReporting.detail.amount'), numeric: true, render: (row) => formatCurrency(row.amount, { currency: receipt.currency }) },
  ]

  const handleConfirmedReprint = async () => {
    const action = reprintAction
    setReprintAction(null)
    if (action === 'print') await receiptPrint.printReceipt(receipt.id)
    if (action === 'download') await receiptPrint.downloadReceipt(receipt.id)
  }

  return (
    <div className="w-full space-y-5">
      <PageHeader
        title={receipt.receipt_number}
        subtitle={formatCurrency(receipt.total, { currency: receipt.currency })}
        breadcrumb={(
          <Link to="/pos/receipts" className={cn('text-sm hover:underline', colorTokens.intent.primary.textStrong)}>
            {t('pos:receiptReporting.detail.backToRegister')}
          </Link>
        )}
        actions={(
          <>
            <Button type="button" variant="secondary" onClick={() => { setReprintAction('download') }}>
              <Download className="me-2 h-4 w-4" aria-hidden="true" />
              {t('pos:receiptReporting.detail.downloadDuplicate')}
            </Button>
            <Button type="button" variant="secondary" onClick={() => { setReprintAction('print') }}>
              <Printer className="me-2 h-4 w-4" aria-hidden="true" />
              {t('pos:receiptReporting.detail.printDuplicate')}
            </Button>
          </>
        )}
      />

      <section className={sectionClass} aria-labelledby="receipt-summary-title">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h2 id="receipt-summary-title" className={cn('text-lg font-semibold', colorTokens.text.primary)}>
              {t('pos:receiptReporting.detail.summary')}
            </h2>
            <p className={cn('mt-1 text-sm', colorTokens.text.muted)}>
              {formatDateTime(receipt.posted_at, dateOptions)} · {receipt.terminal_code} · {receipt.location_name ?? t('common:notAvailable')} · {receipt.cashier_name}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {receipt.invoice_type_code === 'SALE' ? null : (
              <StatusBadge tone="warning">
                {t(`pos:receipts.types.${receipt.invoice_type_code}`)}
              </StatusBadge>
            )}
            {receipt.is_voided ? (
              <StatusBadge tone="danger">{t('pos:receipts.fiscalStatuses.voided')}</StatusBadge>
            ) : null}
            <StatusBadge>{t(`pos:receipts.fiscalStatuses.${receipt.fiscal_status}`)}</StatusBadge>
          </div>
        </div>
        <dl className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <SummaryValue label={t('pos:receiptReporting.detail.subtotal')} value={formatCurrency(receipt.subtotal, { currency: receipt.currency })} />
          <SummaryValue label={t('pos:receiptReporting.detail.vatAmount')} value={formatCurrency(receipt.tax_amount, { currency: receipt.currency })} />
          <SummaryValue label={t('pos:receiptReporting.detail.discount')} value={formatCurrency(receipt.discount_amount, { currency: receipt.currency })} />
          <SummaryValue label={t('pos:receiptReporting.detail.rounding')} value={formatCurrency(receipt.cash_rounding_adjustment ?? '0', { currency: receipt.currency })} />
          <SummaryValue label={t('pos:receipts.total')} value={formatCurrency(receipt.total, { currency: receipt.currency })} emphasized />
        </dl>
      </section>

      <ReceiptSection title={t('pos:receiptReporting.detail.lines')}>
        <DataTable columns={lineColumns} data={receipt.lines} keyExtractor={(line) => line.id} ariaLabel={t('pos:receiptReporting.detail.lines')} />
      </ReceiptSection>

      <ReceiptSection title={t('pos:receiptReporting.detail.vatBreakdown')}>
        <DataTable columns={vatColumns} data={receipt.vat_details} keyExtractor={(row) => row.tax_rate} ariaLabel={t('pos:receiptReporting.detail.vatBreakdown')} />
      </ReceiptSection>

      <ReceiptSection title={t('pos:receiptReporting.detail.payments')}>
        <DataTable columns={paymentColumns} data={receipt.payments} keyExtractor={(row) => row.id} ariaLabel={t('pos:receiptReporting.detail.payments')} />
        {receipt.change_due !== null ? (
          <p className={cn('mt-3 text-end text-sm font-medium', colorTokens.text.secondary)}>
            {t('pos:receiptReporting.detail.changeDue')}: {formatCurrency(receipt.change_due, { currency: receipt.currency })}
          </p>
        ) : null}
      </ReceiptSection>

      <ReceiptSection title={t('pos:receiptReporting.detail.lineage')}>
        {receipt.original_receipt ? (
          <div>
            <p className={cn('text-xs font-medium uppercase tracking-wide', colorTokens.text.subtle)}>
              {t('pos:receiptReporting.detail.originalReceipt')}
            </p>
            <Link to={`/pos/receipts/${receipt.original_receipt.id}`} className={cn('mt-1 inline-block font-mono font-medium hover:underline', colorTokens.intent.primary.textStrong)}>
              {receipt.original_receipt.receipt_number}
            </Link>
            <p className={cn('mt-1 text-xs', colorTokens.text.muted)}>
              {formatDateTime(receipt.original_receipt.posted_at, dateOptions)}
            </p>
          </div>
        ) : null}
        {receipt.return_receipts.length > 0 ? (
          <div className="mt-3 space-y-2">
            {receipt.return_receipts.map((refund) => (
              <div key={refund.id} className={cn('flex flex-wrap items-center justify-between gap-3 rounded-md border p-3', colorTokens.border.subtle, colorTokens.surface.page)}>
                <div>
                  <Link to={`/pos/receipts/${refund.id}`} className={cn('font-mono font-medium hover:underline', colorTokens.intent.primary.textStrong)}>
                    {refund.receipt_number}
                  </Link>
                  <p className={cn('mt-1 text-xs', colorTokens.text.muted)}>
                    {formatDateTime(refund.posted_at, dateOptions)}
                  </p>
                  <p className={cn('mt-0.5 text-xs', colorTokens.text.muted)}>
                    {refund.refund_reason ?? t('pos:receiptReporting.refunds.placeholder')} · {refund.refund_destination
                      ? t(`pos:receiptReporting.refunds.destinations.${refund.refund_destination}`)
                      : t('pos:receiptReporting.refunds.placeholder')}
                  </p>
                  <p className={cn('mt-0.5 text-xs', colorTokens.text.muted)}>
                    {refund.refund_reason_source
                      ? t(`pos:receiptReporting.refunds.reasonSources.${refund.refund_reason_source}`)
                      : t('pos:receiptReporting.refunds.placeholder')}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <StatusBadge tone="warning">{t(`pos:receipts.types.${refund.invoice_type_code}`)}</StatusBadge>
                  <span className={cn('font-mono text-sm', colorTokens.text.primary)}>{formatCurrency(refund.total, { currency: refund.currency })}</span>
                </div>
              </div>
            ))}
          </div>
        ) : receipt.original_receipt === null ? (
          <p className={cn('text-sm', colorTokens.text.muted)}>{t('pos:receiptReporting.detail.noLineage')}</p>
        ) : null}
      </ReceiptSection>

      <details className={sectionClass}>
        <summary className={cn('cursor-pointer font-semibold', colorTokens.text.primary)}>
          {t('pos:receiptReporting.detail.fiscalProvenance')}
        </summary>
        <dl className="mt-4 grid gap-4 sm:grid-cols-2">
          <SummaryValue label={t('pos:receiptReporting.detail.fiscalHash')} value={receipt.fiscal_hash} mono />
          <SummaryValue label={t('pos:receiptReporting.detail.previousHash')} value={receipt.previous_hash ?? t('common:notAvailable')} mono />
          <SummaryValue label={t('pos:receiptReporting.detail.chainSequence')} value={String(receipt.chain_sequence)} />
          <SummaryValue label={t('pos:receiptReporting.detail.receiptYear')} value={String(receipt.receipt_year)} />
          <SummaryValue label={t('pos:receiptReporting.detail.fiscalEvent')} value={receipt.fiscal_event_id ?? t('common:notAvailable')} mono />
          <SummaryValue label={t('pos:receiptReporting.detail.syncedAt')} value={receipt.synced_at ? formatDateTime(receipt.synced_at, dateOptions) : t('common:notAvailable')} />
        </dl>
        {receipt.sync_error ? <p className={cn('mt-4 text-sm', colorTokens.intent.danger.textStrong)}>{receipt.sync_error}</p> : null}
      </details>

      <ConfirmDialog
        isOpen={reprintAction !== null}
        onClose={() => { setReprintAction(null) }}
        onConfirm={() => { void handleConfirmedReprint() }}
        title={t('pos:receiptReporting.reprint.title')}
        message={t('pos:receiptReporting.reprint.auditConsequence')}
        confirmText={reprintAction === 'download' ? t('pos:receiptReporting.reprint.confirmDownload') : t('pos:receiptReporting.reprint.confirmPrint')}
        cancelText={t('common:actions.cancel')}
        variant="warning"
        isLoading={receiptPrint.isPrinting || receiptPrint.isDownloading}
      />
    </div>
  )
}

function ReceiptSection({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className={sectionClass}>
      <h2 className={cn('mb-4 text-lg font-semibold', colorTokens.text.primary)}>{title}</h2>
      {children}
    </section>
  )
}

function SummaryValue({
  label,
  value,
  emphasized = false,
  mono = false,
}: {
  label: string
  value: string
  emphasized?: boolean
  mono?: boolean
}) {
  return (
    <div>
      <dt className={cn('text-xs font-medium uppercase tracking-wide', colorTokens.text.subtle)}>{label}</dt>
      <dd className={cn('mt-1 break-all', emphasized ? 'text-lg font-semibold' : 'text-sm', mono && 'font-mono', colorTokens.text.primary)}>{value}</dd>
    </div>
  )
}
