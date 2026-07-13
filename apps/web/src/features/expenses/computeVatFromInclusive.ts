import { bcadd, bccomp, bcdiv, bcmul } from '@/lib/decimal'

export function computeVatFromInclusive(total: string, rate: string, scale: number): string {
  if (total.trim() === '' || rate.trim() === '') return ''

  const workingScale = scale + 4
  const denominator = bcadd('100', rate, workingScale)
  if (bccomp(denominator, '0') === 0) return ''

  return bcdiv(bcmul(total, rate, workingScale), denominator, scale)
}
