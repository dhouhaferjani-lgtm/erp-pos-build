import { useTranslation } from 'react-i18next'
import { Package, PackageX } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { LocalInventory } from '../../types/catalog'

interface InventoryBadgeProps {
  inventory: LocalInventory
  showQuantity?: boolean
  className?: string
}

export function InventoryBadge({ inventory, showQuantity = false, className }: InventoryBadgeProps) {
  const { t } = useTranslation(['parts-catalog'])

  if (inventory.in_stock) {
    return (
      <span
        className={cn(
          'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
          'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20',
          className
        )}
      >
        <Package className="h-3 w-3" />
        {t('parts-catalog:inventory.inStock')}
        {showQuantity && inventory.available_quantity > 0 && (
          <span className="text-emerald-500">({inventory.available_quantity})</span>
        )}
      </span>
    )
  }

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        'bg-gray-50 text-gray-500 ring-1 ring-inset ring-gray-500/10',
        className
      )}
    >
      <PackageX className="h-3 w-3" />
      {t('parts-catalog:inventory.notInInventory')}
    </span>
  )
}
