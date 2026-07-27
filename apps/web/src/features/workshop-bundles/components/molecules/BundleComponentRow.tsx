import { useTranslation } from 'react-i18next'
import { borderColors, textColors } from '../../../../lib/designTokens'
import { Button } from '@/components/atoms'
import type { ServiceBundleComponentData } from '../../types'
import { ComponentTypeIcon } from '../atoms/ComponentTypeIcon'
import { formatQuantity } from '@/lib/decimal'
import { getQuantityDecimals } from '@/lib/quantityScale'


interface BundleComponentRowProps {
  component: ServiceBundleComponentData
  currency: string
  /** When provided, shows Edit + Delete actions for authoring. */
  onEdit?: (component: ServiceBundleComponentData) => void
  onDelete?: (component: ServiceBundleComponentData) => void
}

export function BundleComponentRow({
  component,
  currency,
  onEdit,
  onDelete,
}: BundleComponentRowProps) {
  const { t } = useTranslation('workshop-bundles')
  const hasActions = onEdit !== undefined || onDelete !== undefined

  return (
    <div className={`flex items-center justify-between gap-3 border-b ${borderColors.light} py-2 last:border-b-0`}>
      <div className="flex items-center gap-2">
        <ComponentTypeIcon type={component.component_type} className={`h-4 w-4 ${textColors.tertiary}`} />
        <div>
          <div className={`text-sm font-medium ${textColors.primary}`}>
            {component.component_display_name}
          </div>
          <div className={`text-xs ${textColors.tertiary}`}>
            {formatQuantity(component.quantity, getQuantityDecimals(component))} {component.unit}
            {component.notes !== null && ` · ${component.notes}`}
          </div>
        </div>
      </div>
      <div className="flex items-center gap-3">
        {component.override_unit_price !== null && (
          <span className={`text-xs font-mono ${textColors.secondary}`}>
            {component.override_unit_price} {currency}
          </span>
        )}
        {hasActions ? (
          <div className="flex items-center gap-2">
            {onEdit !== undefined ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  onEdit(component)
                }}
                data-testid={`bundle-component-edit-${component.id}`}
              >
                {t('authoring.row.edit')}
              </Button>
            ) : null}
            {onDelete !== undefined ? (
              <Button
                type="button"
                variant="dangerOutline"
                size="sm"
                onClick={() => {
                  onDelete(component)
                }}
                data-testid={`bundle-component-delete-${component.id}`}
              >
                {t('authoring.row.delete')}
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  )
}
