import type { OpeningBatchType } from './types'

export function openingBatchTypeKey(type: OpeningBatchType): 'accounting' | 'inventory' | 'arOpenItems' | 'apOpenItems' {
  switch (type) {
    case 'ACCOUNTING':
      return 'accounting'
    case 'INVENTORY':
      return 'inventory'
    case 'AR_OPEN_ITEMS':
      return 'arOpenItems'
    case 'AP_OPEN_ITEMS':
      return 'apOpenItems'
  }
}
