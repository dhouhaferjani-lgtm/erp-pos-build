import { useState } from 'react'
import { convertUnits } from '../api/uomApi'
import type { ConversionResult } from '../api/uomApi'

/**
 * Hook for unit conversion
 *
 * Provides a simpler interface for converting units compared to useMutation.
 * Manages loading and error states internally.
 */
export function useConversion() {
  const [isConverting, setIsConverting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const convert = async (
    quantity: number,
    fromUnitId: string,
    toUnitId: string
  ): Promise<ConversionResult> => {
    setIsConverting(true)
    setError(null)

    try {
      const result = await convertUnits(quantity, fromUnitId, toUnitId)
      return result
    } catch (err: unknown) {
      const errorMessage = err instanceof Error ? err.message : 'Conversion failed'
      setError(errorMessage)
      throw err
    } finally {
      setIsConverting(false)
    }
  }

  return {
    convert,
    isConverting,
    error,
  }
}
