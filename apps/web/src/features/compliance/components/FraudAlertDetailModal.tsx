import { useTranslation } from 'react-i18next'
import { X, AlertTriangle, Calendar, User, Package } from 'lucide-react'
import type { FraudAlert } from '../types/fraudAlerts'

interface Props {
  alert: FraudAlert
  onClose: () => void
}

export function FraudAlertDetailModal({ alert, onClose }: Props) {
  const { t } = useTranslation(['common', 'compliance'])

  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical':
        return 'bg-red-100 text-red-800 border-red-200'
      case 'warning':
        return 'bg-amber-100 text-amber-800 border-amber-200'
      case 'info':
        return 'bg-blue-100 text-blue-800 border-blue-200'
      default:
        return 'bg-gray-100 text-gray-800 border-gray-200'
    }
  }

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <div className="flex items-center gap-3">
            <div className={`rounded-lg p-2 border ${getSeverityColor(alert.severity)}`}>
              <AlertTriangle className="h-5 w-5" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-gray-900">
                {t('compliance:fraudAlerts.detail.title')}
              </h2>
              <p className="text-sm text-gray-500">
                {t(`compliance:fraudAlerts.types.${alert.alert_type}`)} - {t(`compliance:fraudAlerts.severities.${alert.severity}`)}
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <div className="p-6 space-y-6">
          {/* User Info */}
          <div>
            <div className="flex items-center gap-2 text-sm font-medium text-gray-700 mb-2">
              <User className="h-4 w-4 text-gray-400" />
              {t('compliance:fraudAlerts.table.user')}
            </div>
            <div className="bg-gray-50 rounded-lg p-3">
              <div className="font-medium text-gray-900">{alert.user?.name}</div>
              <div className="text-sm text-gray-500">{alert.user?.email}</div>
            </div>
          </div>

          {/* Description */}
          <div>
            <div className="text-sm font-medium text-gray-700 mb-2">
              {t('compliance:fraudAlerts.detail.description')}
            </div>
            <div className="bg-gray-50 rounded-lg p-3 text-sm text-gray-900">
              {alert.description}
            </div>
          </div>

          {/* Detected At */}
          <div>
            <div className="flex items-center gap-2 text-sm font-medium text-gray-700 mb-2">
              <Calendar className="h-4 w-4 text-gray-400" />
              {t('compliance:fraudAlerts.detail.detectedAt')}
            </div>
            <div className="text-sm text-gray-900">
              {new Date(alert.detected_at).toLocaleString()}
            </div>
          </div>

          {/* Flagged Products */}
          {alert.flagged_products && alert.flagged_products.length > 0 && (
            <div>
              <div className="flex items-center gap-2 text-sm font-medium text-gray-700 mb-2">
                <Package className="h-4 w-4 text-gray-400" />
                {t('compliance:fraudAlerts.detail.flaggedProducts')}
              </div>
              <div className="bg-gray-50 rounded-lg p-3 space-y-2">
                {alert.flagged_products.map((product, index) => (
                  <div key={index} className="flex items-center justify-between text-sm">
                    <span className="text-gray-900">{product.product_name}</span>
                    <span className="text-gray-500">
                      {t('compliance:fraudAlerts.detail.occurrenceCount', { count: product.count })}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Metadata */}
          {alert.metadata && Object.keys(alert.metadata).length > 0 && (
            <div>
              <div className="text-sm font-medium text-gray-700 mb-2">
                {t('compliance:fraudAlerts.detail.metadata')}
              </div>
              <div className="bg-gray-50 rounded-lg p-3 space-y-2">
                {Object.entries(alert.metadata).map(([key, value]) => (
                  <div key={key} className="flex items-center justify-between text-sm">
                    <span className="text-gray-500 capitalize">{key.replace(/_/g, ' ')}</span>
                    <span className="text-gray-900 font-medium">{String(value)}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Assignment Info */}
          {alert.assigned_to && (
            <div>
              <div className="text-sm font-medium text-gray-700 mb-2">
                {t('compliance:fraudAlerts.detail.assignedTo')}
              </div>
              <div className="bg-blue-50 rounded-lg p-3">
                <div className="font-medium text-blue-900">{alert.assigned_user?.name}</div>
                <div className="text-sm text-blue-700">{alert.assigned_user?.email}</div>
              </div>
            </div>
          )}

          {/* Resolution Info */}
          {alert.resolved_at && (
            <div>
              <div className="text-sm font-medium text-gray-700 mb-2">
                {t('compliance:fraudAlerts.detail.resolvedAt')}
              </div>
              <div className="text-sm text-gray-900 mb-2">
                {new Date(alert.resolved_at).toLocaleString()}
              </div>
              {alert.resolution_notes && (
                <>
                  <div className="text-sm font-medium text-gray-700 mb-2">
                    {t('compliance:fraudAlerts.detail.resolutionNotes')}
                  </div>
                  <div className="bg-green-50 rounded-lg p-3 text-sm text-green-900">
                    {alert.resolution_notes}
                  </div>
                </>
              )}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-3 p-6 border-t border-gray-200">
          <button
            onClick={onClose}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
          >
            {t('common:actions.close')}
          </button>
        </div>
      </div>
    </div>
  )
}
