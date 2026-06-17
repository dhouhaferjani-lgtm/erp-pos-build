import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertCircle, CheckCircle, FileText } from 'lucide-react'
import { useSalesWithholdingTracking, useMarkCertificateReceived } from '../hooks/useWithholding'
import { useCurrency } from '@/hooks/useCurrency'
import { cn } from '@/lib/utils'
import { tokens, textColors } from '@/lib/designTokens'
import { Button, FormField, Input, Select, StatusBadge } from '@/components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '@/components/molecules'
import type { SalesWithholdingTrackingFilters, SalesWithholdingTrackingRecord } from '../types'

/**
 * Sales Withholding Tracking Page
 *
 * This page tracks when CUSTOMERS withhold tax from payments they make to us.
 * This is the reverse scenario from purchase withholding where WE withhold from suppliers.
 *
 * Use case: Customer issues invoice, customer withholds 1.5%, we receive reduced payment,
 * we track the withholding and follow up to get the certificate from the customer.
 */
export function SalesWithholdingTrackingPage() {
  const { t } = useTranslation(['withholding', 'common'])
  const { decimals } = useCurrency()

  const [filters, setFilters] = useState<{
    certificate_received: boolean | undefined;
    date_from: string;
    date_to: string;
  }>({
    certificate_received: undefined,
    date_from: '',
    date_to: '',
  })

  const [markReceivedModalId, setMarkReceivedModalId] = useState<string | null>(null)
  const [certificateNumber, setCertificateNumber] = useState('')

  const apiFilters: SalesWithholdingTrackingFilters = {}
  if (filters.certificate_received === false) {
    apiFilters.filter = 'pending'
  }

  const { data: trackingRecords = [], isLoading } = useSalesWithholdingTracking(apiFilters)
  const markReceivedMutation = useMarkCertificateReceived()

  // Client-side filtering for date range and certificate_received=true
  const filteredRecords = trackingRecords.filter((record: SalesWithholdingTrackingRecord) => {
    if (filters.certificate_received === true && !record.certificateReceived) {
      return false
    }
    if (filters.date_from && record.createdAt < filters.date_from) {
      return false
    }
    if (filters.date_to && record.createdAt > filters.date_to + 'T23:59:59') {
      return false
    }
    return true
  })

  function handleMarkReceived() {
    if (!markReceivedModalId || !certificateNumber.trim()) return

    markReceivedMutation.mutate(
      {
        id: markReceivedModalId,
        request: { certificate_number: certificateNumber.trim() },
      },
      {
        onSuccess: () => {
          setMarkReceivedModalId(null)
          setCertificateNumber('')
        },
      }
    )
  }

  const columns: DataTableColumn<SalesWithholdingTrackingRecord>[] = [
    {
      key: 'customer',
      header: t('salesWithholding.customer'),
      render: (record) => (
        <span className={textColors.primary}>{record.customerName}</span>
      ),
    },
    {
      key: 'invoiceAmount',
      header: t('salesWithholding.invoiceAmount'),
      numeric: true,
      cellClassName: 'font-mono',
      render: (record) => parseFloat(record.invoiceAmount).toFixed(decimals),
    },
    {
      key: 'withholdingRate',
      header: t('salesWithholding.withholdingRate', {
        defaultValue: t('salesWithholding.withheldAmount'),
      }),
      numeric: true,
      cellClassName: cn('font-mono', textColors.tertiary),
      render: (record) => `${(parseFloat(record.withholdingRate) * 100).toFixed(4)}%`,
    },
    {
      key: 'withholdingAmount',
      header: t('salesWithholding.withheldAmount'),
      numeric: true,
      cellClassName: cn('font-mono font-semibold', textColors.error),
      render: (record) => parseFloat(record.withholdingAmount).toFixed(decimals),
    },
    {
      key: 'expectedReceivable',
      header: t('salesWithholding.expectedReceivable'),
      numeric: true,
      cellClassName: 'font-mono',
      render: (record) => parseFloat(record.expectedReceivable).toFixed(decimals),
    },
    {
      key: 'certificateStatus',
      header: t('salesWithholding.certificateStatus'),
      align: 'center',
      render: (record) =>
        record.certificateReceived ? (
          <StatusBadge tone="success">{t('salesWithholding.received')}</StatusBadge>
        ) : (
          <StatusBadge tone="pending">{t('salesWithholding.pending')}</StatusBadge>
        ),
    },
    {
      key: 'date',
      header: t('salesWithholding.date'),
      render: (record) => (
        <span className={textColors.tertiary}>
          {new Date(record.createdAt).toLocaleDateString()}
        </span>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('common:actions')}</span>,
      align: 'center',
      render: (record) => (
        <div className="flex items-center justify-center gap-1">
          <Link
            to={`/sales/invoices/${record.documentId}`}
            className={cn('inline-flex items-center justify-center rounded p-1', textColors.brand)}
            title={t('salesWithholding.viewDetails')}
          >
            <FileText className="h-4 w-4" />
          </Link>
          {!record.certificateReceived && (
            <Button
              variant="ghost"
              size="sm"
              className={cn('p-1', textColors.success)}
              onClick={() => { setMarkReceivedModalId(record.id) }}
              title={t('salesWithholding.markCertificateReceived')}
            >
              <CheckCircle className="h-4 w-4" />
            </Button>
          )}
        </div>
      ),
    },
  ]

  const filtersSlot = (
    <div className="grid w-full gap-4 sm:grid-cols-2 md:grid-cols-4">
      <FormField label={t('salesWithholding.certificateStatus')} htmlFor="certificate-received-filter">
        <Select
          id="certificate-received-filter"
          value={filters.certificate_received === undefined ? '' : filters.certificate_received.toString()}
          onChange={(e) => {
            setFilters({
              ...filters,
              certificate_received: e.target.value === '' ? undefined : e.target.value === 'true',
            })
          }}
        >
          <option value="">{t('common:all')}</option>
          <option value="true">{t('salesWithholding.certificateReceived')}</option>
          <option value="false">{t('salesWithholding.certificatePending')}</option>
        </Select>
      </FormField>

      <FormField label={t('salesWithholding.dateFrom')} htmlFor="withholding-date-from">
        <Input
          id="withholding-date-from"
          type="date"
          value={filters.date_from}
          onChange={(e) => { setFilters({ ...filters, date_from: e.target.value }) }}
        />
      </FormField>

      <FormField label={t('salesWithholding.dateTo')} htmlFor="withholding-date-to">
        <Input
          id="withholding-date-to"
          type="date"
          value={filters.date_to}
          onChange={(e) => { setFilters({ ...filters, date_to: e.target.value }) }}
        />
      </FormField>

      <div className="flex items-end">
        <Button
          variant="secondary"
          className="w-full"
          onClick={() => {
            setFilters({
              certificate_received: undefined,
              date_from: '',
              date_to: '',
            })
          }}
        >
          {t('common:clearFilters')}
        </Button>
      </div>
    </div>
  )

  return (
    <ListPageLayout
      title={t('salesWithholding.title')}
      subtitle={t('salesWithholding.subtitle')}
      filters={filtersSlot}
    >
      <div className="space-y-6">
        {/* Info Alert */}
        <div className={cn(tokens.alert.base, tokens.alert.info, 'p-4')}>
          <div className="flex items-start gap-3">
            <AlertCircle className={cn('h-5 w-5 flex-shrink-0 mt-0.5', textColors.brand)} />
            <div>
              <h3 className={cn('text-sm font-medium', textColors.primary)}>
                {t('salesWithholding.infoTitle')}
              </h3>
              <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                {t('salesWithholding.infoText')}
              </p>
            </div>
          </div>
        </div>

        {/* Tracking Records Table */}
        <div className={tokens.card.base}>
          <DataTable
            columns={columns}
            data={filteredRecords}
            keyExtractor={(record) => record.id}
            isLoading={isLoading}
            emptyState={
              <EmptyState
                icon={<AlertCircle className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
                title={t('salesWithholding.noRecords')}
              />
            }
          />
        </div>

        {/* How It Works */}
        <div className={tokens.card.base}>
          <h3 className={cn('text-sm font-medium mb-2', textColors.primary)}>
            {t('salesWithholding.howItWorks.title')}
          </h3>
          <ol className={cn('list-decimal list-inside space-y-2 text-sm', textColors.secondary)}>
            <li>{t('salesWithholding.howItWorks.step1')}</li>
            <li>{t('salesWithholding.howItWorks.step2')}</li>
            <li>{t('salesWithholding.howItWorks.step3')}</li>
            <li>{t('salesWithholding.howItWorks.step4')}</li>
            <li>{t('salesWithholding.howItWorks.step5')}</li>
          </ol>
        </div>
      </div>

      {/* Mark Certificate Received Modal */}
      {markReceivedModalId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className={cn('w-full max-w-md', tokens.card.base, 'shadow-xl')}>
            <h2 className={cn('text-lg font-semibold mb-2', textColors.primary)}>
              {t('salesWithholding.markCertificateReceived')}
            </h2>
            <p className={cn('text-sm mb-4', textColors.tertiary)}>
              {t('salesWithholding.enterCertificateNumber')}
            </p>

            <FormField
              label={t('salesWithholding.certificateNumberLabel')}
              htmlFor="certificate-number"
              className="mb-4"
            >
              <Input
                id="certificate-number"
                type="text"
                value={certificateNumber}
                onChange={(e) => { setCertificateNumber(e.target.value) }}
                placeholder={t('salesWithholding.certificateNumberPlaceholder')}
                autoFocus
              />
            </FormField>

            <div className="flex justify-end gap-3">
              <Button
                variant="secondary"
                onClick={() => {
                  setMarkReceivedModalId(null)
                  setCertificateNumber('')
                }}
              >
                {t('common:actions.cancel')}
              </Button>
              <Button
                onClick={handleMarkReceived}
                disabled={!certificateNumber.trim() || markReceivedMutation.isPending}
              >
                {markReceivedMutation.isPending
                  ? t('common:loading')
                  : t('common:actions.confirm')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </ListPageLayout>
  )
}
