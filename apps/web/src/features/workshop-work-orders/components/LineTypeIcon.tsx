import { Clock, Droplet, Package, RefreshCw, Tag, Wrench } from 'lucide-react'
import { textColors } from '@/lib/designTokens'
import type { WorkOrderLineType } from '../types'

interface LineTypeIconProps {
  type: WorkOrderLineType
  className?: string
}

/**
 * Icon + subtle colour per WorkOrderLineType. Used in line tables and totals
 * rollups. Colour variants stay outside the action-state palette.
 */
export function LineTypeIcon({ type, className = 'h-4 w-4' }: LineTypeIconProps) {
  const iconClass = `${className} ${textColors.tertiary}`
  switch (type) {
    case 'part':
      return <Package className={iconClass} aria-hidden />
    case 'labor':
      return <Wrench className={iconClass} aria-hidden />
    case 'core_charge':
      return <Tag className={iconClass} aria-hidden />
    case 'core_return':
      return <RefreshCw className={iconClass} aria-hidden />
    case 'sublet':
      return <Wrench className={iconClass} aria-hidden />
    case 'environmental_fee':
      return <Droplet className={iconClass} aria-hidden />
    case 'misc_fee':
      return <Clock className={iconClass} aria-hidden />
    case 'bundle_header':
      return <Package className={iconClass} aria-hidden />
    default:
      return <Package className={iconClass} aria-hidden />
  }
}
