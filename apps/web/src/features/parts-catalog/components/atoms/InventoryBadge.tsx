import { useTranslation } from 'react-i18next'
import { Package, PackageX } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { LocalInventory } from '../../types/catalog'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

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
          `${colorTokens.intent.available.bgSubtle} ${colorTokens.intent.available.textStrong} ring-1 ring-inset ${colorTokens.intent.available.ring}`,
          className
        )}
      >
        <Package className="h-3 w-3" />
        {t('parts-catalog:inventory.inStock')}
        {showQuantity && inventory.available_quantity > 0 && (
          <span className={colorTokens.intent.available.textSubtle}>({inventory.available_quantity})</span>
        )}
      </span>
    )
  }

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        `${colorTokens.surface.page} ${colorTokens.text.subtle} ring-1 ring-inset ${colorTokens.intent.neutral.ringSubtle}`,
        className
      )}
    >
      <PackageX className="h-3 w-3" />
      {t('parts-catalog:inventory.notInInventory')}
    </span>
  )
}
