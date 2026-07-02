import { useCallback, useRef, useState } from 'react'
import { apiGet } from '../../../lib/api'

export interface ProductLineProduct {
  id: string
  name: string
  sku?: string | null
  barcode?: string | null
  sale_price?: string | number | null
  tax_rate?: string | number | null
  default_tax_configuration_id?: string | null
  quantity_decimals?: number | null
  primary_image_url?: string | null
  has_variants?: boolean
  requires_batch_tracking?: boolean
}

export interface ProductLineVariant {
  id: string
  product_id: string
  sku: string
  variant_code: string
  barcode: string | null
  name_suffix: string
  is_default: boolean
  price_override: string | null
  cost_override: string | null
  image_url: string | null
}

export type ProductLineLookupOutcome =
  | {
      kind: 'product'
      matched_code_type: 'product_barcode' | 'product_sku'
      product: ProductLineProduct
      incrementBy?: number
    }
  | {
      kind: 'variant'
      matched_code_type: 'variant_barcode' | 'variant_sku'
      product: ProductLineProduct
      variant: ProductLineVariant
      incrementBy?: number
    }
  | {
      kind: 'not_found'
      code: string
      incrementBy?: number
    }
  | {
      kind: 'multiple'
      candidates: ProductLineProduct[]
      incrementBy?: number
    }

type LookupStatus = 'idle' | 'searching' | 'found' | 'not_found' | 'multiple'

interface ScanGroup {
  code: string
  resolvers: ((outcome: ProductLineLookupOutcome) => void)[]
  rejecters: ((error: Error) => void)[]
}

export function useProductLineLookup() {
  const [status, setStatus] = useState<LookupStatus>('idle')
  const [scannedCode, setScannedCode] = useState<string | null>(null)
  const queueRef = useRef<ScanGroup[]>([])
  const activeGroupRef = useRef<ScanGroup | null>(null)

  const lookupCode = useCallback(async (code: string): Promise<ProductLineLookupOutcome> => {
    const trimmed = code.trim()
    if (trimmed === '') {
      return { kind: 'not_found', code: trimmed }
    }

    setStatus('searching')
    setScannedCode(trimmed)

    const outcome = await apiGet<ProductLineLookupOutcome>('/line-entry/resolve-code', { code: trimmed })

    if (outcome.kind === 'not_found') {
      setStatus('not_found')
    } else if (outcome.kind === 'multiple') {
      setStatus('multiple')
    } else {
      setStatus('found')
    }

    return outcome
  }, [])

  const processNext = useCallback(function processNext(): void {
    if (activeGroupRef.current !== null) return

    const group = queueRef.current.shift()
    if (group === undefined) return

    activeGroupRef.current = group
    void lookupCode(group.code)
      .then((outcome) => {
        const countedOutcome = {
          ...outcome,
          incrementBy: group.resolvers.length,
        }
        group.resolvers.forEach((resolve) => {
          resolve(countedOutcome)
        })
      })
      .catch((error: unknown) => {
        const normalizedError = error instanceof Error ? error : new Error(String(error))
        group.rejecters.forEach((reject) => {
          reject(normalizedError)
        })
      })
      .finally(() => {
        activeGroupRef.current = null
        processNext()
      })
  }, [lookupCode])

  const enqueueScan = useCallback((code: string): Promise<ProductLineLookupOutcome> => {
    const trimmed = code.trim()
    return new Promise<ProductLineLookupOutcome>((resolve, reject) => {
      const activeGroup = activeGroupRef.current
      if (activeGroup !== null && activeGroup.code === trimmed) {
        activeGroup.resolvers.push(resolve)
        activeGroup.rejecters.push(reject)
        return
      }

      const lastGroup = queueRef.current.at(-1)
      if (lastGroup?.code === trimmed) {
        lastGroup.resolvers.push(resolve)
        lastGroup.rejecters.push(reject)
        return
      }

      queueRef.current.push({
        code: trimmed,
        resolvers: [resolve],
        rejecters: [reject],
      })
      processNext()
    })
  }, [processNext])

  const resetLookup = useCallback(() => {
    setStatus('idle')
    setScannedCode(null)
  }, [])

  return {
    enqueueScan,
    lookupCode,
    resetLookup,
    scannedCode,
    status,
  }
}
