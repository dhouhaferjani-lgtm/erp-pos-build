import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AlertCircle, CheckCircle, FileText, Calendar } from 'lucide-react'
import { useSalesWithholdingTracking, useMarkCertificateReceived } from '../hooks/useWithholding'
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

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">
          {t('salesWithholding.title')}
        </h1>
        <p className="mt-1 text-sm text-gray-600">
          {t('salesWithholding.subtitle')}
        </p>
      </div>

      {/* Info Alert */}
      <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
        <div className="flex items-start gap-3">
          <AlertCircle className="h-5 w-5 text-blue-600 flex-shrink-0 mt-0.5" />
          <div>
            <h3 className="text-sm font-medium text-blue-900">
              {t('salesWithholding.infoTitle')}
            </h3>
            <p className="mt-1 text-sm text-blue-700">
              {t('salesWithholding.infoText')}
            </p>
          </div>
        </div>
      </div>

      {/* Filters */}
      <div className="rounded-lg border border-gray-200 bg-white p-4">
        <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
          {/* Certificate Received Filter */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {t('salesWithholding.certificateStatus')}
            </label>
            <select
              value={filters.certificate_received === undefined ? '' : filters.certificate_received.toString()}
              onChange={(e) =>
                setFilters({
                  ...filters,
                  certificate_received: e.target.value === '' ? undefined : e.target.value === 'true',
                })
              }
              className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            >
              <option value="">{t('common:all')}</option>
              <option value="true">{t('salesWithholding.certificateReceived')}</option>
              <option value="false">{t('salesWithholding.certificatePending')}</option>
            </select>
          </div>

          {/* Date From */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {t('salesWithholding.dateFrom')}
            </label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => setFilters({ ...filters, date_from: e.target.value })}
              className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            />
          </div>

          {/* Date To */}
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              {t('salesWithholding.dateTo')}
            </label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => setFilters({ ...filters, date_to: e.target.value })}
              className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
            />
          </div>

          {/* Clear Filters */}
          <div className="flex items-end">
            <button
              type="button"
              onClick={() =>
                setFilters({
                  certificate_received: undefined,
                  date_from: '',
                  date_to: '',
                })
              }
              className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
            >
              {t('common:clearFilters')}
            </button>
          </div>
        </div>
      </div>

      {/* Tracking Records Table */}
      <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-gray-500">{t('common:loading')}</div>
        ) : filteredRecords.length === 0 ? (
          <div className="p-8 text-center">
            <AlertCircle className="mx-auto h-12 w-12 text-gray-400" />
            <p className="mt-2 text-sm text-gray-600">
              {t('salesWithholding.noRecords')}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('salesWithholding.customer')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('salesWithholding.invoiceAmount')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('salesWithholding.withholdingRate', { defaultValue: t('salesWithholding.withheldAmount') })}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('salesWithholding.withheldAmount')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('salesWithholding.expectedReceivable')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('salesWithholding.certificateStatus')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('salesWithholding.date')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('common:actions')}
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {filteredRecords.map((record: SalesWithholdingTrackingRecord) => (
                  <tr key={record.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 whitespace-nowrap">
                      <div className="text-sm text-gray-900">{record.customerName}</div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <div className="text-sm font-mono text-gray-900">
                        {parseFloat(record.invoiceAmount).toFixed(3)}
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <div className="text-sm font-mono text-gray-600">
                        {(parseFloat(record.withholdingRate) * 100).toFixed(2)}%
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <div className="text-sm font-mono font-semibold text-red-600">
                        {parseFloat(record.withholdingAmount).toFixed(3)}
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <div className="text-sm font-mono text-gray-900">
                        {parseFloat(record.expectedReceivable).toFixed(3)}
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-center">
                      {record.certificateReceived ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800">
                          <CheckCircle className="h-3 w-3" />
                          {t('salesWithholding.received')}
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-yellow-100 px-2 py-1 text-xs font-medium text-yellow-800">
                          <Calendar className="h-3 w-3" />
                          {t('salesWithholding.pending')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <div className="text-sm text-gray-600">
                        {new Date(record.createdAt).toLocaleDateString()}
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-center">
                      <div className="flex items-center justify-center gap-1">
                        <Link
                          to={`/sales/invoices/${record.documentId}`}
                          className="inline-flex items-center justify-center rounded p-1 text-blue-600 hover:bg-blue-100 hover:text-blue-900"
                          title={t('salesWithholding.viewDetails')}
                        >
                          <FileText className="h-4 w-4" />
                        </Link>
                        {!record.certificateReceived && (
                          <button
                            type="button"
                            onClick={() => setMarkReceivedModalId(record.id)}
                            className="inline-flex items-center justify-center rounded p-1 text-green-600 hover:bg-green-100 hover:text-green-900"
                            title={t('salesWithholding.markCertificateReceived')}
                          >
                            <CheckCircle className="h-4 w-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* How It Works */}
      <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <h3 className="text-sm font-medium text-gray-900 mb-2">
          {t('salesWithholding.howItWorks.title')}
        </h3>
        <ol className="list-decimal list-inside space-y-2 text-sm text-gray-700">
          <li>{t('salesWithholding.howItWorks.step1')}</li>
          <li>{t('salesWithholding.howItWorks.step2')}</li>
          <li>{t('salesWithholding.howItWorks.step3')}</li>
          <li>{t('salesWithholding.howItWorks.step4')}</li>
          <li>{t('salesWithholding.howItWorks.step5')}</li>
        </ol>
      </div>

      {/* Mark Certificate Received Modal */}
      {markReceivedModalId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 className="text-lg font-semibold text-gray-900 mb-2">
              {t('salesWithholding.markCertificateReceived')}
            </h2>
            <p className="text-sm text-gray-600 mb-4">
              {t('salesWithholding.enterCertificateNumber')}
            </p>

            <div className="mb-4">
              <label
                htmlFor="certificate-number"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                {t('salesWithholding.certificateNumberLabel')}
              </label>
              <input
                id="certificate-number"
                type="text"
                value={certificateNumber}
                onChange={(e) => setCertificateNumber(e.target.value)}
                placeholder={t('salesWithholding.certificateNumberPlaceholder')}
                className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                autoFocus
              />
            </div>

            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={() => {
                  setMarkReceivedModalId(null)
                  setCertificateNumber('')
                }}
                className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="button"
                onClick={handleMarkReceived}
                disabled={!certificateNumber.trim() || markReceivedMutation.isPending}
                className="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {markReceivedMutation.isPending
                  ? t('common:loading')
                  : t('common:actions.confirm')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
