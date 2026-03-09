import { useCallback, useRef, useState } from 'react'
import { fetchProductByBarcode, type POSProduct } from '../api/productApi'

export type BarcodeLookupStatus = 'idle' | 'searching' | 'found' | 'not_found' | 'multiple'

interface BarcodeLookupResult {
  status: BarcodeLookupStatus
  products: POSProduct[]
  scannedCode: string | null
}

interface UseBarcodeLookupOptions {
  /**
   * Local products already loaded in the POS grid.
   * The hook tries local matching first before hitting the API.
   */
  localProducts: POSProduct[]
  /** Called when exactly one product matches the barcode. */
  onSingleMatch: (product: POSProduct) => void
  /** Called when no products match the barcode. */
  onNoMatch: (code: string) => void
  /** Called when multiple products match (unlikely for barcodes, but handled). */
  onMultipleMatches: (products: POSProduct[], code: string) => void
}

/**
 * Hook that looks up products by barcode/SKU.
 *
 * Matching strategy:
 * 1. First try exact match against locally-loaded products (instant, no network).
 * 2. If no local match, query the backend with the `barcode` filter
 *    which performs exact matching on both `barcode` and `sku` columns.
 */
export function useBarcodeLookup({
  localProducts,
  onSingleMatch,
  onNoMatch,
  onMultipleMatches,
}: UseBarcodeLookupOptions) {
  const [result, setResult] = useState<BarcodeLookupResult>({
    status: 'idle',
    products: [],
    scannedCode: null,
  })

  // Keep callbacks in refs to avoid re-creating the lookup function
  const onSingleMatchRef = useRef(onSingleMatch)
  onSingleMatchRef.current = onSingleMatch
  const onNoMatchRef = useRef(onNoMatch)
  onNoMatchRef.current = onNoMatch
  const onMultipleMatchesRef = useRef(onMultipleMatches)
  onMultipleMatchesRef.current = onMultipleMatches
  const localProductsRef = useRef(localProducts)
  localProductsRef.current = localProducts

  const lookup = useCallback(async (code: string) => {
    const trimmed = code.trim()
    if (!trimmed) return

    setResult({ status: 'searching', products: [], scannedCode: trimmed })

    // 1. Try local exact match first
    const localMatches = localProductsRef.current.filter(
      (p) =>
        (p.barcode && p.barcode === trimmed) ||
        p.sku.toLowerCase() === trimmed.toLowerCase()
    )

    if (localMatches.length === 1) {
      setResult({ status: 'found', products: localMatches, scannedCode: trimmed })
      onSingleMatchRef.current(localMatches[0])
      return
    }

    if (localMatches.length > 1) {
      setResult({ status: 'multiple', products: localMatches, scannedCode: trimmed })
      onMultipleMatchesRef.current(localMatches, trimmed)
      return
    }

    // 2. No local match -- query API
    try {
      const apiResults = await fetchProductByBarcode(trimmed)

      if (apiResults.length === 1) {
        setResult({ status: 'found', products: apiResults, scannedCode: trimmed })
        onSingleMatchRef.current(apiResults[0])
      } else if (apiResults.length > 1) {
        setResult({ status: 'multiple', products: apiResults, scannedCode: trimmed })
        onMultipleMatchesRef.current(apiResults, trimmed)
      } else {
        setResult({ status: 'not_found', products: [], scannedCode: trimmed })
        onNoMatchRef.current(trimmed)
      }
    } catch {
      // Network error -- fall back to not found
      setResult({ status: 'not_found', products: [], scannedCode: trimmed })
      onNoMatchRef.current(trimmed)
    }
  }, [])

  const resetLookup = useCallback(() => {
    setResult({ status: 'idle', products: [], scannedCode: null })
  }, [])

  return {
    lookup,
    resetLookup,
    status: result.status,
    matchedProducts: result.products,
    scannedCode: result.scannedCode,
  }
}
