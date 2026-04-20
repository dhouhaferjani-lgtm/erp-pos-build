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
    <div className="flex items-center justify-between gap-3 border-b border-gray-100 py-2 last:border-b-0">
      <div className="flex items-center gap-2">
        <ComponentTypeIcon type={component.component_type} className="h-4 w-4 text-gray-500" />
        <div>
          <div className="text-sm font-medium text-gray-900">
            {component.component_display_name}
          </div>
          <div className="text-xs text-gray-500">
            {component.quantity} {component.unit}
            {component.notes !== null && ` · ${component.notes}`}
          </div>
        </div>
      </div>
      {component.override_unit_price !== null && (
        <span className="text-xs font-mono text-gray-700">
          {component.override_unit_price} {currency}
        </span>
      )}
    </div>
  )
}
