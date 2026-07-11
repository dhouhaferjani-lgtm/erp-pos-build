import { useTranslation } from 'react-i18next'
import { borderColors, textColors, tokens } from '../../../../lib/designTokens'
import type { ServiceBundleComponentData } from '../../types'
import { ComponentTypeIcon } from '../atoms/ComponentTypeIcon'

const buttonTokens = tokens.button


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
            {component.quantity} {component.unit}
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
              <button
                type="button"
                onClick={() => {
                  onEdit(component)
                }}
                className={`${buttonTokens.base} ${buttonTokens.ghost} ${buttonTokens.sizes.sm}`}
                data-testid={`bundle-component-edit-${component.id}`}
              >
                {t('authoring.row.edit')}
              </button>
            ) : null}
            {onDelete !== undefined ? (
              <button
                type="button"
                onClick={() => {
                  onDelete(component)
                }}
                className={`${buttonTokens.base} ${buttonTokens.dangerOutline} ${buttonTokens.sizes.sm}`}
                data-testid={`bundle-component-delete-${component.id}`}
              >
                {t('authoring.row.delete')}
              </button>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  )
}
