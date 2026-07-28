export interface QuantityScaleProduct {
  quantity_decimals?: number | null
}

export const QUANTITY_STORAGE_SCALE = 4

const DEFAULT_QUANTITY_DECIMALS = QUANTITY_STORAGE_SCALE

export function getQuantityDecimals(product: QuantityScaleProduct | null | undefined): number {
  const decimals = product?.quantity_decimals
  if (typeof decimals !== 'number' || !Number.isInteger(decimals)) {
    return DEFAULT_QUANTITY_DECIMALS
  }

  return Math.min(Math.max(decimals, 0), DEFAULT_QUANTITY_DECIMALS)
}
