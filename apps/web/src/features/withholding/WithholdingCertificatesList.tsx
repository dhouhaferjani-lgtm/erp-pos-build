import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Download,
  FileText,
  Filter,
  Send,
  Eye,
  CheckCircle2,
  XCircle,
  AlertCircle
} from 'lucide-react'
import { toast } from 'sonner'
import {
  downloadCertificatePDF,
  downloadCertificateTEJXML,
  downloadBatchTEJXML,
} from './api/withholdingApi'
import { useCurrency } from '@/hooks/useCurrency'
import {
  useWithholdingCertificates,
  useIssueWithholdingCertificate,
  useVoidWithholdingCertificate,
} from './hooks/useWithholding'
import type { CertificateFilters, WithholdingDirection, CertificateStatus } from './types'

export function WithholdingCertificatesList() {
  const { t } = useTranslation(['withholding', 'common'])
  const { decimals } = useCurrency()

  const [filters, setFilters] = useState<CertificateFilters>({})
  const [showFilters, setShowFilters] = useState(false)

  const { data, isLoading } = useWithholdingCertificates(filters)
  const issueMutation = useIssueWithholdingCertificate()
  const voidMutation = useVoidWithholdingCertificate()

  const certificates = data?.data ?? []

  const handleIssue = async (id: string) => {
    if (!window.confirm(t('messages.confirmIssue'))) return

    try {
      await issueMutation.mutateAsync(id)
    } catch (error) {
      // Error handled by mutation onError
    }
  }

  const handleVoid = async (id: string) => {
    const reason = window.prompt(t('messages.confirmVoid'))
    if (!reason) return

    try {
      await voidMutation.mutateAsync({ id, request: { reason } })
    } catch (error) {
      // Error handled by mutation onError
    }
  }

  const handleDownloadPDF = (id: string) => {
    const url = downloadCertificatePDF(id)
    window.open(url, '_blank')
    toast.success(t('messages.downloadStarted'))
  }

  const handleDownloadTEJXML = (id: string) => {
    const url = downloadCertificateTEJXML(id)
    window.open(url, '_blank')
    toast.success(t('messages.downloadStarted'))
  }

  const handleDownloadBatchXML = () => {
    const url = downloadBatchTEJXML(filters.year, filters.direction)
    window.open(url, '_blank')
    toast.success(t('messages.downloadStarted'))
  }

  const getStatusBadge = (status: CertificateStatus) => {
    const styles = {
      draft: 'bg-gray-100 text-gray-800',
      issued: 'bg-green-100 text-green-800',
      submitted: 'bg-blue-100 text-blue-800',
      voided: 'bg-red-100 text-red-800',
    }

    const icons = {
      draft: <FileText className="h-3 w-3" />,
      issued: <CheckCircle2 className="h-3 w-3" />,
      submitted: <Send className="h-3 w-3" />,
      voided: <XCircle className="h-3 w-3" />,
    }

    return (
      <span className={`inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-medium ${styles[status]}`}>
        {icons[status]}
        {t(`status.${status}`)}
      </span>
    )
  }

  const getDirectionBadge = (direction: WithholdingDirection) => {
    const styles = {
      purchase: 'bg-purple-100 text-purple-800',
      sales: 'bg-orange-100 text-orange-800',
    }

    return (
      <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${styles[direction]}`}>
        {t(`direction.${direction}Short`)}
      </span>
    )
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-900">
          {t('certificates.title')}
        </h1>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={handleDownloadBatchXML}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            <Download className="h-4 w-4" />
            {t('certificates.export.batchTEJ')}
          </button>
          <button
            type="button"
            onClick={() => { setShowFilters(!showFilters) }}
            className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
          >
            <Filter className="h-4 w-4" />
            {t('common:filtersLabel')}
          </button>
        </div>
      </div>

      {/* Filters */}
      {showFilters && (
        <div className="rounded-lg border border-gray-200 bg-white p-4">
          <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-4">
            {/* Direction Filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('certificates.filters.direction')}
              </label>
              <select
                value={filters.direction ?? ''}
                onChange={(e) => { setFilters({ ...filters, direction: e.target.value as WithholdingDirection || undefined }) }}
                className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              >
                <option value="">{t('common:all')}</option>
                <option value="purchase">{t('direction.purchase')}</option>
                <option value="sales">{t('direction.sales')}</option>
              </select>
            </div>

            {/* Status Filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('certificates.filters.status')}
              </label>
              <select
                value={filters.status ?? ''}
                onChange={(e) => { setFilters({ ...filters, status: e.target.value as CertificateStatus || undefined }) }}
                className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              >
                <option value="">{t('common:all')}</option>
                <option value="draft">{t('status.draft')}</option>
                <option value="issued">{t('status.issued')}</option>
                <option value="submitted">{t('status.submitted')}</option>
                <option value="voided">{t('status.voided')}</option>
              </select>
            </div>

            {/* Year Filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('certificates.filters.year')}
              </label>
              <input
                type="number"
                value={filters.year ?? ''}
                onChange={(e) => { setFilters({ ...filters, year: e.target.value ? parseInt(e.target.value) : undefined }) }}
                className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                placeholder={new Date().getFullYear().toString()}
              />
            </div>

            {/* Clear Filters */}
            <div className="flex items-end">
              <button
                type="button"
                onClick={() => { setFilters({}) }}
                className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
              >
                {t('common:clearFilters')}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Table */}
      <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-gray-500">
            {t('common:loading')}
          </div>
        ) : certificates.length === 0 ? (
          <div className="p-8 text-center">
            <AlertCircle className="mx-auto h-12 w-12 text-gray-400" />
            <p className="mt-2 text-sm text-gray-600">
              {t('certificates.noCertificates')}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
              <thead className="bg-gray-50">
                <tr>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('certificates.number')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('certificates.direction')}
                  </th>
                  <th className="px-4 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('certificates.partner')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('certificates.grossAmount')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('certificates.rate')}
                  </th>
                  <th className="px-4 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('certificates.withholdingAmount')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('certificates.status')}
                  </th>
                  <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                    {t('common:actions')}
                  </th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-gray-200">
                {certificates.map((cert) => (
                  <tr key={cert.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 whitespace-nowrap">
                      <Link
                        to={`/treasury/withholding-certificates/${cert.id}`}
                        className="text-sm font-medium text-blue-600 hover:text-blue-800"
                      >
                        {cert.certificate_number}
                      </Link>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      {getDirectionBadge(cert.direction)}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">
                      <div className="text-sm text-gray-900">
                        {cert.partner?.name ?? '-'}
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <div className="text-sm font-mono text-gray-900">
                        {parseFloat(cert.gross_amount).toFixed(decimals)} {cert.currency}
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <div className="text-sm font-mono text-gray-900">
                        {cert.rate_percentage}%
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-end">
                      <div className="text-sm font-mono font-semibold text-red-600">
                        {parseFloat(cert.withholding_amount).toFixed(decimals)} {cert.currency}
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-center">
                      {getStatusBadge(cert.status)}
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap text-center">
                      <div className="flex items-center justify-center gap-1">
                        <Link
                          to={`/treasury/withholding-certificates/${cert.id}`}
                          className="inline-flex items-center justify-center rounded p-1 text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                          title={t('certificates.actions.viewDetails')}
                        >
                          <Eye className="h-4 w-4" />
                        </Link>

                        {cert.can_be_issued && (
                          <button
                            type="button"
                            onClick={() => { void handleIssue(cert.id) }}
                            className="inline-flex items-center justify-center rounded p-1 text-green-600 hover:bg-green-100 hover:text-green-900"
                            title={t('certificates.actions.issue')}
                          >
                            <CheckCircle2 className="h-4 w-4" />
                          </button>
                        )}

                        {cert.status !== 'draft' && (
                          <button
                            type="button"
                            onClick={() => { handleDownloadPDF(cert.id) }}
                            className="inline-flex items-center justify-center rounded p-1 text-blue-600 hover:bg-blue-100 hover:text-blue-900"
                            title={t('certificates.actions.downloadPDF')}
                          >
                            <FileText className="h-4 w-4" />
                          </button>
                        )}

                        {cert.status === 'issued' && (
                          <button
                            type="button"
                            onClick={() => { handleDownloadTEJXML(cert.id) }}
                            className="inline-flex items-center justify-center rounded p-1 text-purple-600 hover:bg-purple-100 hover:text-purple-900"
                            title={t('certificates.actions.downloadTEJ')}
                          >
                            <Download className="h-4 w-4" />
                          </button>
                        )}

                        {cert.can_be_voided && (
                          <button
                            type="button"
                            onClick={() => { void handleVoid(cert.id) }}
                            className="inline-flex items-center justify-center rounded p-1 text-red-600 hover:bg-red-100 hover:text-red-900"
                            title={t('certificates.actions.void')}
                          >
                            <XCircle className="h-4 w-4" />
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
    </div>
  )
}
