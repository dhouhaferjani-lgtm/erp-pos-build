import { useTranslation } from 'react-i18next'
import { AlertTriangle, Calendar, User, Package } from 'lucide-react'
import type { FraudAlert } from '../types/fraudAlerts'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../../../components/organisms/Modal'
import { Button } from '../../../components/atoms/Button/Button'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface Props {
  alert: FraudAlert
  onClose: () => void
}

export function FraudAlertDetailModal({ alert, onClose }: Props) {
  const { t } = useTranslation(['common', 'compliance'])

  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical':
        return `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger} ${colorTokens.intent.danger.borderSubtle}`
      case 'warning':
        return `${colorTokens.intent.caution.bgSoft} ${colorTokens.intent.caution.textStronger} ${colorTokens.intent.caution.borderSubtle}`
      case 'info':
        return `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger} ${colorTokens.intent.primary.borderSubtle}`
      default:
        return `${colorTokens.surface.muted} ${colorTokens.text.strong} ${colorTokens.border.subtle}`
    }
  }

  return (
    <Modal isOpen onClose={onClose} size="lg" className="max-h-[90vh] overflow-y-auto">
      <ModalHeader onClose={onClose} className={`border-b ${colorTokens.border.subtle} pb-4`}>
        <div className="flex items-center gap-3">
          <div className={`rounded-lg p-2 border ${getSeverityColor(alert.severity)}`}>
            <AlertTriangle className="h-5 w-5" />
          </div>
          <div>
            <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
              {t('compliance:fraudAlerts.detail.title')}
            </h2>
            <p className={`text-sm ${colorTokens.text.subtle}`}>
              {t(`compliance:fraudAlerts.types.${alert.alert_type}`)} - {t(`compliance:fraudAlerts.severities.${alert.severity}`)}
            </p>
          </div>
        </div>
      </ModalHeader>

      <ModalContent className="space-y-6">
        {/* User Info */}
        <div>
          <div className={`flex items-center gap-2 text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
            <User className={`h-4 w-4 ${colorTokens.text.disabled}`} />
            {t('compliance:fraudAlerts.table.user')}
          </div>
          <div className={`${colorTokens.surface.page} rounded-lg p-3`}>
            <div className={`font-medium ${colorTokens.text.primary}`}>{alert.user?.name}</div>
            <div className={`text-sm ${colorTokens.text.subtle}`}>{alert.user?.email}</div>
          </div>
        </div>

        {/* Description */}
        <div>
          <div className={`text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
            {t('compliance:fraudAlerts.detail.description')}
          </div>
          <div className={`${colorTokens.surface.page} rounded-lg p-3 text-sm ${colorTokens.text.primary}`}>
            {alert.description}
          </div>
        </div>

        {/* Detected At */}
        <div>
          <div className={`flex items-center gap-2 text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
            <Calendar className={`h-4 w-4 ${colorTokens.text.disabled}`} />
            {t('compliance:fraudAlerts.detail.detectedAt')}
          </div>
          <div className={`text-sm ${colorTokens.text.primary}`}>
            {new Date(alert.detected_at).toLocaleString()}
          </div>
        </div>

        {/* Flagged Products */}
        {alert.flagged_products && alert.flagged_products.length > 0 && (
          <div>
            <div className={`flex items-center gap-2 text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              <Package className={`h-4 w-4 ${colorTokens.text.disabled}`} />
              {t('compliance:fraudAlerts.detail.flaggedProducts')}
            </div>
            <div className={`${colorTokens.surface.page} rounded-lg p-3 space-y-2`}>
              {alert.flagged_products.map((product, index) => (
                <div key={index} className="flex items-center justify-between text-sm">
                  <span className={colorTokens.text.primary}>{product.product_name}</span>
                  <span className={colorTokens.text.subtle}>
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
            <div className={`text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('compliance:fraudAlerts.detail.metadata')}
            </div>
            <div className={`${colorTokens.surface.page} rounded-lg p-3 space-y-2`}>
              {Object.entries(alert.metadata).map(([key, value]) => (
                <div key={key} className="flex items-center justify-between text-sm">
                  <span className={`${colorTokens.text.subtle} capitalize`}>{key.replace(/_/g, ' ')}</span>
                  <span className={`${colorTokens.text.primary} font-medium`}>{String(value)}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Assignment Info */}
        {alert.assigned_to && (
          <div>
            <div className={`text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('compliance:fraudAlerts.detail.assignedTo')}
            </div>
            <div className={`${colorTokens.intent.primary.bgSubtle} rounded-lg p-3`}>
              <div className={`font-medium ${colorTokens.intent.primary.textStrongest}`}>{alert.assigned_user?.name}</div>
              <div className={`text-sm ${colorTokens.intent.primary.textStrong}`}>{alert.assigned_user?.email}</div>
            </div>
          </div>
        )}

        {/* Resolution Info */}
        {alert.resolved_at && (
          <div>
            <div className={`text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
              {t('compliance:fraudAlerts.detail.resolvedAt')}
            </div>
            <div className={`text-sm ${colorTokens.text.primary} mb-2`}>
              {new Date(alert.resolved_at).toLocaleString()}
            </div>
            {alert.resolution_notes && (
              <>
                <div className={`text-sm font-medium ${colorTokens.text.secondary} mb-2`}>
                  {t('compliance:fraudAlerts.detail.resolutionNotes')}
                </div>
                <div className={`${colorTokens.intent.success.bgSubtle} rounded-lg p-3 text-sm ${colorTokens.intent.success.textStrongest}`}>
                  {alert.resolution_notes}
                </div>
              </>
            )}
          </div>
        )}
      </ModalContent>

      <ModalFooter className={`border-t ${colorTokens.border.subtle} pt-4`}>
        <Button variant="secondary" onClick={onClose}>
          {t('common:actions.close')}
        </Button>
      </ModalFooter>
    </Modal>
  )
}
