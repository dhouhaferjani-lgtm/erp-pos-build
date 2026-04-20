import { borderColors, textColors } from '../../../../lib/designTokens'
import type { ServiceBundleComponentData } from '../../types'
import { ComponentTypeIcon } from '../atoms/ComponentTypeIcon'

interface BundleComponentRowProps {
  component: ServiceBundleComponentData
  currency: string
}

export function BundleComponentRow({
  component,
  currency,
}: BundleComponentRowProps) {
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
      {component.override_unit_price !== null && (
        <span className={`text-xs font-mono ${textColors.secondary}`}>
          {component.override_unit_price} {currency}
        </span>
      )}
    </div>
  )
}
