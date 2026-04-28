import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import {
  AlertTriangle,
  Filter,
  Eye,
  UserPlus,
  XCircle,
  CheckCircle,
  TrendingUp,
  Shield,
} from 'lucide-react'
import { getFraudAlerts, getFraudAlertStatistics } from '../api/fraudApi'
import type { FraudAlert, FraudAlertFilters } from '../types/fraudAlerts'
import {
  FraudAlertDetailModal,
  AssignAlertModal,
  DismissAlertModal,
  ResolveAlertModal,
} from '../components'

export function FraudAlertsPage() {
  const { t } = useTranslation(['common', 'compliance'])

  const [filters, setFilters] = useState<FraudAlertFilters>({})
  const [currentPage, setCurrentPage] = useState(1)
  const [selectedAlert, setSelectedAlert] = useState<FraudAlert | null>(null)
  const [actionModal, setActionModal] = useState<{
    type: 'assign' | 'dismiss' | 'resolve' | null
    alert: FraudAlert | null
  }>({ type: null, alert: null })

  // Fetch alerts
  const { data: alertsData, isLoading } = useQuery({
    queryKey: ['fraud-alerts', filters, currentPage],
    queryFn: () => getFraudAlerts(filters, currentPage),
  })

  // Fetch statistics
  const { data: statsData } = useQuery({
    queryKey: ['fraud-alert-statistics'],
    queryFn: getFraudAlertStatistics,
  })


  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical':
        return 'bg-red-100 text-red-800'
      case 'warning':
        return 'bg-amber-100 text-amber-800'
      case 'info':
        return 'bg-blue-100 text-blue-800'
      default:
        return 'bg-gray-100 text-gray-800'
    }
  }

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'open':
        return 'bg-red-100 text-red-800'
      case 'investigating':
        return 'bg-blue-100 text-blue-800'
      case 'dismissed':
        return 'bg-gray-100 text-gray-800'
      case 'resolved':
        return 'bg-green-100 text-green-800'
      default:
        return 'bg-gray-100 text-gray-800'
    }
  }

  const alerts = alertsData?.data || []
  const stats = statsData?.data

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
          <Shield className="h-6 w-6 text-gray-400" />
          {t('compliance:fraudAlerts.title')}
        </h1>
        <p className="text-gray-500 mt-1">{t('compliance:fraudAlerts.description')}</p>
      </div>

      {/* Statistics */}
      {stats && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div className="bg-white rounded-lg border border-gray-200 p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t('compliance:fraudAlerts.statistics.totalAlerts')}
                </p>
                <p className="text-2xl font-bold text-gray-900 mt-1">{stats.total_alerts}</p>
              </div>
              <div className="bg-blue-100 rounded-lg p-3">
                <AlertTriangle className="h-6 w-6 text-blue-600" />
              </div>
            </div>
          </div>

          <div className="bg-white rounded-lg border border-gray-200 p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t('compliance:fraudAlerts.statistics.openAlerts')}
                </p>
                <p className="text-2xl font-bold text-red-600 mt-1">{stats.open_alerts}</p>
              </div>
              <div className="bg-red-100 rounded-lg p-3">
                <XCircle className="h-6 w-6 text-red-600" />
              </div>
            </div>
          </div>

          <div className="bg-white rounded-lg border border-gray-200 p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t('compliance:fraudAlerts.statistics.investigating')}
                </p>
                <p className="text-2xl font-bold text-blue-600 mt-1">{stats.investigating}</p>
              </div>
              <div className="bg-blue-100 rounded-lg p-3">
                <Eye className="h-6 w-6 text-blue-600" />
              </div>
            </div>
          </div>

          <div className="bg-white rounded-lg border border-gray-200 p-4">
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium text-gray-500">
                  {t('compliance:fraudAlerts.statistics.recentAlerts')}
                </p>
                <p className="text-2xl font-bold text-amber-600 mt-1">{stats.recent_alerts}</p>
              </div>
              <div className="bg-amber-100 rounded-lg p-3">
                <TrendingUp className="h-6 w-6 text-amber-600" />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Filters */}
      <div className="bg-white rounded-lg border border-gray-200 p-4">
        <div className="flex items-center gap-2 mb-4">
          <Filter className="h-5 w-5 text-gray-400" />
          <h2 className="font-medium text-gray-900">{t('compliance:fraudAlerts.filters.status')}</h2>
        </div>

        <div className="grid gap-4 md:grid-cols-3">
          <div>
            <label htmlFor="status-filter" className="block text-sm font-medium text-gray-700 mb-1">
              {t('compliance:fraudAlerts.filters.status')}
            </label>
            <select
              id="status-filter"
              value={filters.status || ''}
              onChange={(e) => {
                const { status, ...rest } = filters
                setFilters(e.target.value ? { ...rest, status: e.target.value } : rest)
              }}
              className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >
              <option value="">{t('compliance:fraudAlerts.statuses.all')}</option>
              <option value="open">{t('compliance:fraudAlerts.statuses.open')}</option>
              <option value="investigating">{t('compliance:fraudAlerts.statuses.investigating')}</option>
              <option value="dismissed">{t('compliance:fraudAlerts.statuses.dismissed')}</option>
              <option value="resolved">{t('compliance:fraudAlerts.statuses.resolved')}</option>
            </select>
          </div>

          <div>
            <label htmlFor="severity-filter" className="block text-sm font-medium text-gray-700 mb-1">
              {t('compliance:fraudAlerts.filters.severity')}
            </label>
            <select
              id="severity-filter"
              value={filters.severity || ''}
              onChange={(e) => {
                const { severity, ...rest } = filters
                setFilters(e.target.value ? { ...rest, severity: e.target.value } : rest)
              }}
              className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >
              <option value="">{t('compliance:fraudAlerts.severities.all')}</option>
              <option value="critical">{t('compliance:fraudAlerts.severities.critical')}</option>
              <option value="warning">{t('compliance:fraudAlerts.severities.warning')}</option>
              <option value="info">{t('compliance:fraudAlerts.severities.info')}</option>
            </select>
          </div>

          <div>
            <label htmlFor="type-filter" className="block text-sm font-medium text-gray-700 mb-1">
              {t('compliance:fraudAlerts.filters.type')}
            </label>
            <select
              id="type-filter"
              value={filters.alert_type || ''}
              onChange={(e) => {
                const { alert_type, ...rest } = filters
                setFilters(e.target.value ? { ...rest, alert_type: e.target.value } : rest)
              }}
              className="block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
            >
              <option value="">{t('compliance:fraudAlerts.types.all')}</option>
              <option value="high_abandonment">{t('compliance:fraudAlerts.types.high_abandonment')}</option>
              <option value="suspicious_items">{t('compliance:fraudAlerts.types.suspicious_items')}</option>
              <option value="rapid_cycle">{t('compliance:fraudAlerts.types.rapid_cycle')}</option>
            </select>
          </div>
        </div>
      </div>

      {/* Alerts Table */}
      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('compliance:fraudAlerts.table.user')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('compliance:fraudAlerts.table.type')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('compliance:fraudAlerts.table.severity')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('compliance:fraudAlerts.table.detected')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('compliance:fraudAlerts.table.status')}
                </th>
                <th className="px-6 py-3 text-end text-xs font-medium text-gray-500 uppercase tracking-wider">
                  {t('compliance:fraudAlerts.table.actions')}
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {isLoading ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center text-gray-500">
                    {t('compliance:fraudAlerts.messages.loadingAlerts')}
                  </td>
                </tr>
              ) : alerts.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center text-gray-500">
                    {t('compliance:fraudAlerts.messages.noAlerts')}
                  </td>
                </tr>
              ) : (
                alerts.map((alert) => (
                  <tr key={alert.id} className="hover:bg-gray-50">
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm font-medium text-gray-900">{alert.user?.name}</div>
                      <div className="text-sm text-gray-500">{alert.user?.email}</div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <div className="text-sm text-gray-900">
                        {t(`compliance:fraudAlerts.types.${alert.alert_type}`)}
                      </div>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getSeverityColor(alert.severity)}`}>
                        {t(`compliance:fraudAlerts.severities.${alert.severity}`)}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                      {new Date(alert.detected_at).toLocaleDateString()}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap">
                      <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${getStatusColor(alert.status)}`}>
                        {t(`compliance:fraudAlerts.statuses.${alert.status}`)}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-end text-sm font-medium">
                      <div className="flex items-center justify-end gap-2">
                        <button
                          onClick={() => { setSelectedAlert(alert); }}
                          className="text-blue-600 hover:text-blue-900"
                          title={t('compliance:fraudAlerts.actions.view')}
                        >
                          <Eye className="h-4 w-4" />
                        </button>
                        {alert.status === 'open' && (
                          <button
                            onClick={() => { setActionModal({ type: 'assign', alert }); }}
                            className="text-purple-600 hover:text-purple-900"
                            title={t('compliance:fraudAlerts.actions.assign')}
                          >
                            <UserPlus className="h-4 w-4" />
                          </button>
                        )}
                        {alert.status !== 'dismissed' && alert.status !== 'resolved' && (
                          <>
                            <button
                              onClick={() => { setActionModal({ type: 'dismiss', alert }); }}
                              className="text-gray-600 hover:text-gray-900"
                              title={t('compliance:fraudAlerts.actions.dismiss')}
                            >
                              <XCircle className="h-4 w-4" />
                            </button>
                            <button
                              onClick={() => { setActionModal({ type: 'resolve', alert }); }}
                              className="text-green-600 hover:text-green-900"
                              title={t('compliance:fraudAlerts.actions.resolve')}
                            >
                              <CheckCircle className="h-4 w-4" />
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination */}
        {alertsData && alertsData.last_page > 1 && (
          <div className="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
            <div className="flex-1 flex justify-between sm:hidden">
              <button
                onClick={() => { setCurrentPage((p) => Math.max(1, p - 1)); }}
                disabled={currentPage === 1}
                className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
              >
                {t('common:pagination.previous')}
              </button>
              <button
                onClick={() => { setCurrentPage((p) => Math.min(alertsData.last_page, p + 1)); }}
                disabled={currentPage === alertsData.last_page}
                className="ms-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 disabled:opacity-50"
              >
                {t('common:pagination.next')}
              </button>
            </div>
            <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
              <div>
                <p className="text-sm text-gray-700">
                  {t('common:pagination.showing', {
                    from: (currentPage - 1) * alertsData.per_page + 1,
                    to: Math.min(currentPage * alertsData.per_page, alertsData.total),
                    total: alertsData.total
                  })}
                </p>
              </div>
              <div>
                <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                  <button
                    onClick={() => { setCurrentPage((p) => Math.max(1, p - 1)); }}
                    disabled={currentPage === 1}
                    className="relative inline-flex items-center px-2 py-2 rounded-s-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                  >
                    {t('common:pagination.previous')}
                  </button>
                  {(() => {
                    const totalPages = alertsData.last_page
                    const current = currentPage
                    const delta = 1 // Pages to show on each side of current page
                    const pages: (number | string)[] = []

                    // Always show first page
                    pages.push(1)

                    // Calculate range around current page
                    const rangeStart = Math.max(2, current - delta)
                    const rangeEnd = Math.min(totalPages - 1, current + delta)

                    // Add ellipsis after first page if needed
                    if (rangeStart > 2) {
                      pages.push('ellipsis-start')
                    }

                    // Add pages around current page
                    for (let i = rangeStart; i <= rangeEnd; i++) {
                      pages.push(i)
                    }

                    // Add ellipsis before last page if needed
                    if (rangeEnd < totalPages - 1) {
                      pages.push('ellipsis-end')
                    }

                    // Always show last page (if more than 1 page)
                    if (totalPages > 1) {
                      pages.push(totalPages)
                    }

                    return pages.map((page) => {
                      if (typeof page === 'string') {
                        // Render ellipsis
                        return (
                          <span
                            key={page}
                            className="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700"
                          >
                            ...
                          </span>
                        )
                      }

                      // Render page button
                      return (
                        <button
                          key={page}
                          onClick={() => { setCurrentPage(page); }}
                          className={`relative inline-flex items-center px-4 py-2 border text-sm font-medium ${
                            currentPage === page
                              ? 'z-10 bg-blue-50 border-blue-500 text-blue-600'
                              : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                          }`}
                        >
                          {page}
                        </button>
                      )
                    })
                  })()}
                  <button
                    onClick={() => { setCurrentPage((p) => Math.min(alertsData.last_page, p + 1)); }}
                    disabled={currentPage === alertsData.last_page}
                    className="relative inline-flex items-center px-2 py-2 rounded-e-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                  >
                    {t('common:pagination.next')}
                  </button>
                </nav>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Detail Modal */}
      {selectedAlert && (
        <FraudAlertDetailModal alert={selectedAlert} onClose={() => { setSelectedAlert(null); }} />
      )}

      {/* Action Modals */}
      {actionModal.type === 'assign' && actionModal.alert && (
        <AssignAlertModal
          alert={actionModal.alert}
          onClose={() => { setActionModal({ type: null, alert: null }); }}
        />
      )}
      {actionModal.type === 'dismiss' && actionModal.alert && (
        <DismissAlertModal
          alert={actionModal.alert}
          onClose={() => { setActionModal({ type: null, alert: null }); }}
        />
      )}
      {actionModal.type === 'resolve' && actionModal.alert && (
        <ResolveAlertModal
          alert={actionModal.alert}
          onClose={() => { setActionModal({ type: null, alert: null }); }}
        />
      )}
    </div>
  )
}
