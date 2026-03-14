import { useState, useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useTranslation } from 'react-i18next'
import { ArrowRight, Calculator } from 'lucide-react'
import { Input } from '../../../components/atoms/Input'
import { FormField } from '../../../components/atoms/FormField'
import { Button } from '../../../components/atoms/Button'
import { UnitDropdown } from './UnitDropdown'
import { useConversion } from '../hooks/useConversion'

/**
 * Validation schema for conversion form
 */
const conversionSchema = z.object({
  quantity: z
    .string()
    .min(1, 'Quantity is required')
    .refine((val) => !isNaN(parseFloat(val)) && parseFloat(val) > 0, {
      message: 'Quantity must be a positive number',
    }),
  fromUnitId: z.string().min(1, 'From unit is required'),
  toUnitId: z.string().min(1, 'To unit is required'),
})

type ConversionFormData = z.infer<typeof conversionSchema>

interface ConversionCalculatorProps {
  /**
   * Optional CSS class for the container
   */
  className?: string

  /**
   * Pre-select from unit (optional)
   */
  defaultFromUnitId?: string

  /**
   * Pre-select to unit (optional)
   */
  defaultToUnitId?: string

  /**
   * Default quantity (optional)
   */
  defaultQuantity?: string
}

/**
 * ConversionCalculator - Widget for converting quantities between units
 *
 * This organism component provides a standalone calculator for unit conversions.
 * It can be embedded in pages or used as a utility tool.
 *
 * Features:
 * - Real-time conversion as user types
 * - Validation for incompatible units
 * - Support for all unit categories
 * - Shows conversion factor
 *
 * @example
 * ```tsx
 * // Standalone calculator
 * <ConversionCalculator />
 *
 * // Pre-configured calculator
 * <ConversionCalculator
 *   defaultFromUnitId="kg-unit-id"
 *   defaultToUnitId="g-unit-id"
 *   defaultQuantity="2.5"
 * />
 * ```
 */
export function ConversionCalculator({
  className = '',
  defaultFromUnitId,
  defaultToUnitId,
  defaultQuantity = '1',
}: ConversionCalculatorProps) {
  const { t } = useTranslation(['uom', 'common'])
  const [result, setResult] = useState<string | null>(null)
  const [conversionFactor, setConversionFactor] = useState<string | null>(null)
  const { convert, isConverting, error: conversionError } = useConversion()

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = useForm<ConversionFormData>({
    resolver: zodResolver(conversionSchema),
    defaultValues: {
      quantity: defaultQuantity,
      fromUnitId: defaultFromUnitId || '',
      toUnitId: defaultToUnitId || '',
    },
  })

  const quantity = watch('quantity')
  const fromUnitId = watch('fromUnitId')
  const toUnitId = watch('toUnitId')

  // Auto-convert when all fields are valid and changed
  useEffect(() => {
    const doConversion = async () => {
      if (!quantity || !fromUnitId || !toUnitId) {
        setResult(null)
        setConversionFactor(null)
        return
      }

      const parsedQuantity = parseFloat(quantity)
      if (isNaN(parsedQuantity) || parsedQuantity <= 0) {
        setResult(null)
        setConversionFactor(null)
        return
      }

      try {
        const conversionResult = await convert(parsedQuantity, fromUnitId, toUnitId)
        setResult(conversionResult.convertedQuantity ?? null)
        setConversionFactor(conversionResult.conversionFactor ?? null)
      } catch (error) {
        setResult(null)
        setConversionFactor(null)
      }
    }

    doConversion()
  }, [quantity, fromUnitId, toUnitId, convert])

  const onSubmit = async (data: ConversionFormData) => {
    // Form is already validated and conversion happens via useEffect
    // This handler is mainly for explicit "Convert" button clicks
    try {
      const conversionResult = await convert(parseFloat(data.quantity), data.fromUnitId, data.toUnitId)
      setResult(conversionResult.convertedQuantity ?? null)
      setConversionFactor(conversionResult.conversionFactor ?? null)
    } catch (error) {
      setResult(null)
      setConversionFactor(null)
    }
  }

  const handleSwapUnits = () => {
    const tempFromUnit = fromUnitId
    setValue('fromUnitId', toUnitId, { shouldValidate: true })
    setValue('toUnitId', tempFromUnit, { shouldValidate: true })
  }

  return (
    <div className={`bg-white rounded-lg shadow p-6 ${className}`}>
      {/* Header */}
      <div className="mb-6">
        <div className="flex items-center gap-2 text-lg font-semibold text-gray-900">
          <Calculator className="h-5 w-5" />
          {t('uom:conversionCalculator')}
        </div>
        <p className="mt-1 text-sm text-gray-600">{t('uom:conversionCalculatorHelp')}</p>
      </div>

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
        {/* Quantity Input */}
        <FormField label={t('uom:quantity')} htmlFor="quantity" required error={errors.quantity?.message}>
          <Input
            id="quantity"
            type="text"
            {...register('quantity')}
            error={!!errors.quantity}
            placeholder="1"
            autoComplete="off"
          />
        </FormField>

        {/* From Unit → To Unit */}
        <div className="grid grid-cols-1 md:grid-cols-[1fr,auto,1fr] gap-4 items-end">
          {/* From Unit */}
          <FormField label={t('uom:fromUnit')} htmlFor="fromUnitId" required error={errors.fromUnitId?.message}>
            <UnitDropdown
              name="fromUnitId"
              value={fromUnitId}
              onChange={(value) => setValue('fromUnitId', value, { shouldValidate: true })}
              error={!!errors.fromUnitId}
            />
          </FormField>

          {/* Swap Button */}
          <div className="flex items-center justify-center pb-1">
            <button
              type="button"
              onClick={handleSwapUnits}
              className="p-2 text-gray-600 hover:bg-gray-100 rounded-full transition-colors"
              title={t('uom:swapUnits')}
              disabled={!fromUnitId || !toUnitId}
            >
              <ArrowRight className="h-5 w-5" />
            </button>
          </div>

          {/* To Unit */}
          <FormField label={t('uom:toUnit')} htmlFor="toUnitId" required error={errors.toUnitId?.message}>
            <UnitDropdown
              name="toUnitId"
              value={toUnitId}
              onChange={(value) => setValue('toUnitId', value, { shouldValidate: true })}
              error={!!errors.toUnitId}
            />
          </FormField>
        </div>

        {/* Conversion Error */}
        {conversionError && (
          <div className="rounded-md bg-red-50 p-4">
            <p className="text-sm text-red-800">{conversionError}</p>
          </div>
        )}

        {/* Result Display */}
        {result !== null && !conversionError && (
          <div className="rounded-md bg-blue-50 p-4 border border-blue-200">
            <div className="flex items-baseline gap-2">
              <span className="text-sm text-blue-700">{t('uom:result')}:</span>
              <span className="text-2xl font-semibold text-blue-900">{result}</span>
            </div>
            {conversionFactor && (
              <div className="mt-2 text-sm text-blue-700">
                {t('uom:conversionFactorDisplay')}: {conversionFactor}
              </div>
            )}
          </div>
        )}

        {/* Convert Button (Optional - conversion happens automatically) */}
        <Button type="submit" variant="primary" disabled={isConverting} className="w-full">
          {isConverting ? t('common:common.loading') : t('uom:convert')}
        </Button>
      </form>
    </div>
  )
}
