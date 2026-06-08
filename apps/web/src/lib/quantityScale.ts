export interface QuantityScaleProduct {
  quantity_decimals?: number | null
}

const DEFAULT_QUANTITY_DECIMALS = 4

export function getQuantityDecimals(product: QuantityScaleProduct | null | undefined): number {
  const decimals = product?.quantity_decimals
  if (typeof decimals !== 'number' || !Number.isInteger(decimals)) {
    return DEFAULT_QUANTITY_DECIMALS
  }

  return Math.min(Math.max(decimals, 0), DEFAULT_QUANTITY_DECIMALS)
}
