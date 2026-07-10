import { bcadd, bccomp, bcdiv, bcmul, bcsub } from '@/lib/decimal'

export type MarginLevel = 'success' | 'warning' | 'danger' | 'neutral'

export interface MarginState {
  level: MarginLevel
  marginPercent: string
}

export function taxDivisor(taxRate: string | null | undefined): string {
  const rate = taxRate?.trim() === '' || taxRate === null || taxRate === undefined ? '0' : taxRate
  return bcadd('1', bcdiv(rate, '100', 6), 6)
}

export function priceHtFromTtc(ttc: string, taxRate: string | null | undefined, scale: number): string {
  if (ttc.trim() === '') return ''
  return bcdiv(ttc, taxDivisor(taxRate), scale)
}

export function priceTtcFromHt(ht: string, taxRate: string | null | undefined, scale: number): string {
  if (ht.trim() === '') return ''
  return bcmul(ht, taxDivisor(taxRate), scale)
}

export function marginFromCost(cost: string | null | undefined, priceHt: string | null | undefined): string {
  if (
    cost === null ||
    cost === undefined ||
    priceHt === null ||
    priceHt === undefined ||
    cost.trim() === '' ||
    priceHt.trim() === '' ||
    bccomp(cost, '0') <= 0
  ) {
    return ''
  }

  return bcmul(bcdiv(bcsub(priceHt, cost, 4), cost, 6), '100', 2)
}

export function priceHtFromMargin(cost: string | null | undefined, margin: string, scale: number): string {
  if (cost === null || cost === undefined || cost.trim() === '' || margin.trim() === '') return ''
  const factor = bcadd('1', bcdiv(margin, '100', 6), 6)
  return bcmul(cost, factor, scale)
}

export function coefficientFromCostAndPrice(cost: string | null | undefined, priceHt: string | null | undefined): string {
  if (
    cost === null ||
    cost === undefined ||
    priceHt === null ||
    priceHt === undefined ||
    cost.trim() === '' ||
    priceHt.trim() === '' ||
    bccomp(cost, '0') <= 0
  ) {
    return ''
  }

  return bcdiv(priceHt, cost, 4)
}

export function priceHtFromCoefficient(cost: string | null | undefined, coefficient: string, scale: number): string {
  if (cost === null || cost === undefined || cost.trim() === '' || coefficient.trim() === '') return ''
  return bcmul(cost, coefficient, scale)
}

export function resolveMarginState(
  cost: string | null | undefined,
  priceHt: string | null | undefined,
  targetMargin: string | null | undefined,
  minimumMargin: string | null | undefined,
): MarginState {
  const marginPercent = marginFromCost(cost, priceHt)

  if (marginPercent === '') return { level: 'neutral', marginPercent: '' }
  if (bccomp(marginPercent, '0') < 0) return { level: 'danger', marginPercent }

  if (minimumMargin !== null && minimumMargin !== undefined && minimumMargin.trim() !== '' && bccomp(marginPercent, minimumMargin) < 0) {
    return { level: 'danger', marginPercent }
  }

  if (targetMargin !== null && targetMargin !== undefined && targetMargin.trim() !== '' && bccomp(marginPercent, targetMargin) < 0) {
    return { level: 'warning', marginPercent }
  }

  return { level: 'success', marginPercent }
}
