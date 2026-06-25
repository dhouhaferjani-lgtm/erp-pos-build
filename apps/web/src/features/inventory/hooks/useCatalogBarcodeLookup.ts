import { useState, useEffect, useRef } from 'react'
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner'
import { useCatalogLookup } from '../api/platformQueries'
import type { LookupState, SuggestedProduct } from '../types/platform'

const DEBOUNCE_MS = 300
const MIN_LOOKUP_LENGTH = 8

export interface UseCatalogBarcodeLookupOptions {
  /** The current barcode value (controlled — from react-hook-form state). */
  barcode: string
  /** Called with suggested product data when the lookup returns `found`. */
  onProductData: (data: SuggestedProduct) => void
  /** Called when the lookup state changes (idle → searching → found | not_found | error). */
  onLookupStateChange: (state: LookupState) => void
  /**
   * Called when the barcode scanner fires a scan event.
   * The caller should update the controlled `barcode` value (e.g. `setValue('barcode', code)`).
   */
  onScan: (code: string) => void
}

export interface UseCatalogBarcodeLookupResult {
  /** True while the catalog lookup request is in-flight. */
  isSearching: boolean
}

/**
 * Headless hook that encapsulates the barcode-lookup engine:
 * debounce, minimum-length gate, barcode scanner integration,
 * and lookup state + product-data callbacks.
 *
 * The hook is driven by the `barcode` prop (controlled value from the form).
 * It does NOT manage its own input value; that lives in the form state.
 */
export function useCatalogBarcodeLookup({
  barcode,
  onProductData,
  onLookupStateChange,
  onScan,
}: UseCatalogBarcodeLookupOptions): UseCatalogBarcodeLookupResult {
  const [lookupBarcode, setLookupBarcode] = useState<string | null>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const prevStateRef = useRef<LookupState>('idle')

  // Wire up the hardware barcode scanner (keyboard-wedge).
  // The scanner fires rapid keystrokes ending with Enter; the hook detects this
  // and calls onScan — which updates the form barcode via setValue. The new
  // barcode value arrives here via the `barcode` prop on the next render.
  useBarcodeScanner({ onScan })

  // Debounce the controlled barcode value into a lookup trigger.
  useEffect(() => {
    if (debounceRef.current !== null) {
      clearTimeout(debounceRef.current)
    }

    if (barcode.length >= MIN_LOOKUP_LENGTH) {
      debounceRef.current = setTimeout(() => {
        setLookupBarcode(barcode)
      }, DEBOUNCE_MS)
    } else {
      setLookupBarcode(null)
    }

    return () => {
      if (debounceRef.current !== null) {
        clearTimeout(debounceRef.current)
      }
    }
  }, [barcode])

  const { data, isLoading, isError } = useCatalogLookup(lookupBarcode)

  // Derive the current lookup state and notify the caller when it changes.
  // prevStateRef de-dupes so callbacks only fire on actual transitions.
  useEffect(() => {
    let state: LookupState = 'idle'

    if (isLoading) {
      state = 'searching'
    } else if (isError) {
      state = 'error'
    } else if (data !== undefined) {
      if (data.status === 'found') {
        state = 'found'
      } else if (data.status === 'not_found') {
        state = 'not_found'
      } else if (data.status === 'error') {
        state = 'error'
      }
    }

    if (state !== prevStateRef.current) {
      prevStateRef.current = state
      onLookupStateChange(state)

      if (state === 'found' && data?.suggestedProduct !== null && data?.suggestedProduct !== undefined) {
        onProductData(data.suggestedProduct)
      }
    }
  }, [data, isLoading, isError, onLookupStateChange, onProductData])

  return { isSearching: isLoading }
}
