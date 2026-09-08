import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { entityRoutes, type DocumentRouteType, type PartnerRouteType } from '@/lib/entityRoutes'
import { textColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

type BaseEntityLinkProps = {
  id: string | null | undefined
  label: ReactNode
  className?: string
  muted?: boolean
  inline?: boolean
  'data-testid'?: string
}

type EntityLinkProps =
  | (BaseEntityLinkProps & { type: 'product' | 'customer' | 'supplier' | 'payment' | 'expense' | 'stockTransfer' | 'batch' | 'journalEntry' | 'inventoryCounting' })
  | (BaseEntityLinkProps & { type: 'variant'; productId: string | null | undefined })
  | (BaseEntityLinkProps & { type: 'partner'; partnerType?: PartnerRouteType })
  | (BaseEntityLinkProps & { type: 'document'; documentType: DocumentRouteType })
  | (BaseEntityLinkProps & { type: 'goodsReceipt'; purchaseOrderId: string | null | undefined })

function resolveEntityHref(props: EntityLinkProps): string | null {
  if (!props.id) return null

  switch (props.type) {
    case 'product':
      return entityRoutes.product(props.id)
    case 'variant':
      return entityRoutes.variant(props.id, { productId: props.productId })
    case 'customer':
      return entityRoutes.customer(props.id)
    case 'supplier':
      return entityRoutes.supplier(props.id)
    case 'partner':
      return entityRoutes.partner(props.id, { partnerType: props.partnerType })
    case 'document':
      return entityRoutes.document(props.id, { documentType: props.documentType })
    case 'payment':
      return entityRoutes.payment(props.id)
    case 'expense':
      return entityRoutes.expense(props.id)
    case 'stockTransfer':
      return entityRoutes.stockTransfer(props.id)
    case 'goodsReceipt':
      return entityRoutes.goodsReceipt(props.id, { purchaseOrderId: props.purchaseOrderId })
    case 'batch':
      return entityRoutes.batch(props.id)
    case 'journalEntry':
      return entityRoutes.journalEntry(props.id)
    case 'inventoryCounting':
      return entityRoutes.inventoryCounting(props.id)
  }
}

export function EntityLink(props: EntityLinkProps) {
  const href = resolveEntityHref(props)
  const fallbackClassName = cn(props.inline ? 'inline' : undefined, props.className)

  if (!href) {
    return <span className={fallbackClassName} data-testid={props['data-testid']}>{props.label}</span>
  }

  return (
    <Link
      to={href}
      data-testid={props['data-testid']}
      className={cn(
        props.inline ? 'inline' : undefined,
        props.muted ? textColors.tertiary : textColors.brand,
        'hover:underline',
        props.className,
      )}
    >
      {props.label}
    </Link>
  )
}
