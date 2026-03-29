import { useState, useEffect, useRef, useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useBarcodeScanner } from '@/hooks/useBarcodeScanner'
import { tokens, textColors } from '@/lib/designTokens'
import { useCatalogLookup } from '../api/platformQueries'
import type { LookupState, SuggestedProduct } from '../types/platform'

interface BarcodeLookupInputProps {
  onProductData: (data: SuggestedProduct) => void
  onLookupStateChange: (state: LookupState) => void
  defaultBarcode?: string
}

const DEBOUNCE_MS = 300
const MIN_LOOKUP_LENGTH = 8

export function BarcodeLookupInput({
  onProductData,
  onLookupStateChange,
  defaultBarcode = '',
}: BarcodeLookupInputProps) {
  const { t } = useTranslation('inventory')
  const [inputValue, setInputValue] = useState(defaultBarcode)
  const [lookupBarcode, setLookupBarcode] = useState<string | null>(null)
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const prevStateRef = useRef<LookupState>('idle')

  const { data, isLoading, isError } = useCatalogLookup(lookupBarcode)

  const handleScan = useCallback((barcode: string) => {
    setInputValue(barcode)
    setLookupBarcode(barcode)
  }, [])

  useBarcodeScanner({ onScan: handleScan })

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value
    setInputValue(value)

    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }

    if (value.length >= MIN_LOOKUP_LENGTH) {
      debounceRef.current = setTimeout(() => {
        setLookupBarcode(value)
      }, DEBOUNCE_MS)
    } else {
      setLookupBarcode(null)
    }
  }

  const handleClear = () => {
    setInputValue('')
    setLookupBarcode(null)
    if (debounceRef.current) {
      clearTimeout(debounceRef.current)
    }
  }

  useEffect(() => {
    let state: LookupState = 'idle'

    if (isLoading) {
      state = 'searching'
    } else if (isError) {
      state = 'error'
    } else if (data) {
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

      if (state === 'found' && data?.suggestedProduct) {
        onProductData(data.suggestedProduct)
      }
    }
  }, [data, isLoading, isError, onLookupStateChange, onProductData])

  useEffect(() => {
    return () => {
      if (debounceRef.current) {
        clearTimeout(debounceRef.current)
      }
    }
  }, [])

  return (
    <div>
      <label
        htmlFor="barcode-lookup"
        className={tokens.label.base}
      >
        {t('products.barcode')}
      </label>
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <input
            type="text"
            id="barcode-lookup"
            value={inputValue}
            onChange={handleInputChange}
            placeholder={t('barcodeLookup.placeholder')}
            className={tokens.input.base}
            disabled={isLoading}
          />
          {inputValue && (
            <button
              type="button"
              onClick={handleClear}
              className="absolute right-2 top-1/2 -translate-y-1/2 text-sm opacity-50 hover:opacity-100"
              aria-label="Clear barcode"
            >
              {'\u2715'}
            </button>
          )}
        </div>
        {isLoading && (
          <span className={`text-sm ${textColors.secondary}`}>
            {t('barcodeLookup.searching')}
          </span>
        )}
      </div>
    </div>
  )
}
