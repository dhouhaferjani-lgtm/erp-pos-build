import { useState } from 'react'
import { Calculator, Percent, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button, Input } from '@/components/atoms'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors } from '@/lib/designTokens'

import {
  coefficientFromCostAndPrice,
  marginFromCost,
  priceHtFromCoefficient,
  priceHtFromMargin,
} from './pricingMath'

type PricingMode = 'margin' | 'coefficient'

interface PricingModeCalculatorProps {
  costPrice: string | null | undefined
  salePriceHt: string | null | undefined
  moneyScale: number
  onSalePriceHtChange?: ((value: string) => void) | undefined
}

export function PricingModeCalculator({
  costPrice,
  salePriceHt,
  moneyScale,
  onSalePriceHtChange,
}: PricingModeCalculatorProps) {
  const { t } = useTranslation('inventory')
  const [mode, setMode] = useState<PricingMode>('margin')
  const margin = marginFromCost(costPrice, salePriceHt ?? '')
  const coefficient = coefficientFromCostAndPrice(costPrice, salePriceHt ?? '')
  const [inputValue, setInputValue] = useState(margin)

  const handleModeChange = (nextMode: PricingMode) => {
    setMode(nextMode)
    setInputValue(nextMode === 'margin' ? margin : coefficient)
  }

  const handleValueChange = (value: string) => {
    setInputValue(value)
    if (onSalePriceHtChange === undefined) return

    const nextPrice = mode === 'margin'
      ? priceHtFromMargin(costPrice, value, moneyScale)
      : priceHtFromCoefficient(costPrice, value, moneyScale)

    if (nextPrice !== '') {
      onSalePriceHtChange(nextPrice)
    }
  }

  return (
    <div className={cn('space-y-3 rounded-md border p-3', borderColors.light, colors.neutral[50])}>
      <div className="flex items-center justify-between gap-3">
        <div className={cn('flex items-center gap-2 text-sm font-medium', textColors.primary)}>
          <Calculator className="h-4 w-4" />
          {t('pricing.calculator')}
        </div>
        <div className={cn('inline-flex rounded-md border p-0.5', borderColors.light, colors.white)}>
          <Button
            type="button"
            variant={mode === 'margin' ? 'primary' : 'ghost'}
            size="sm"
            onClick={() => { handleModeChange('margin') }}
            className="h-7 px-2"
          >
            <Percent className="h-3.5 w-3.5" />
            <span className="sr-only">{t('pricing.marginMode')}</span>
          </Button>
          <Button
            type="button"
            variant={mode === 'coefficient' ? 'primary' : 'ghost'}
            size="sm"
            onClick={() => { handleModeChange('coefficient') }}
            className="h-7 px-2"
          >
            <X className="h-3.5 w-3.5" />
            <span className="sr-only">{t('pricing.coefficientMode')}</span>
          </Button>
        </div>
      </div>
      <label className={cn('block text-xs font-medium', textColors.tertiary)} htmlFor="pricing-mode-value">
        {mode === 'margin' ? t('pricing.marginPercent') : t('pricing.coefficient')}
      </label>
      <Input
        id="pricing-mode-value"
        aria-label={mode === 'margin' ? t('pricing.marginPercent') : t('pricing.coefficient')}
        inputMode="decimal"
        value={inputValue}
        onChange={(event) => { handleValueChange(event.target.value) }}
      />
    </div>
  )
}
