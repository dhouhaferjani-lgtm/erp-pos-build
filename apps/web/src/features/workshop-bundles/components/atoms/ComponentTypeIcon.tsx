import { Wrench, Package, Layers } from 'lucide-react'
import type { BundleComponentType } from '../../types'

interface ComponentTypeIconProps {
  type: BundleComponentType
  className?: string
}

export function ComponentTypeIcon({
  type,
  className = 'h-4 w-4',
}: ComponentTypeIconProps) {
  if (type === 'part') {
    return <Package className={className} />
  }
  if (type === 'labor') {
    return <Wrench className={className} />
  }
  return <Layers className={className} />
}
