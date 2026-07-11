import { useRef, useState } from 'react'
import { useWatch, type Control, type UseFormSetValue } from 'react-hook-form'

import {
  marginFromCost,
  priceHtFromMargin,
  priceHtFromTtc,
  priceTtcFromHt,
} from './productPricingMath'
import type {
  ProductPricingEditController,
  ProductPricingFieldController,
  ProductSectionFormData,
} from './types'

type PricingField = 'margin' | 'priceHt' | 'priceTtc'

interface UseProductPricingEditAdapterOptions {
  control: Control<ProductSectionFormData>
  setValue: UseFormSetValue<ProductSectionFormData>
  isOpeningLocked: boolean
  productCostPrice: string | null
  canEnterOpening: boolean
  moneyScale: number
}

export function useProductPricingEditAdapter({
  control,
  setValue,
  isOpeningLocked,
  productCostPrice,
  canEnterOpening,
  moneyScale,
}: UseProductPricingEditAdapterOptions): ProductPricingEditController {
  const purchasePrice = useWatch({ control, name: 'purchase_price' })
  const salePriceHt = useWatch({ control, name: 'sale_price' })
  const taxRate = useWatch({ control, name: 'tax_rate' })
  const openingQty = useWatch({ control, name: 'opening_qty' })
  const costBasis = isOpeningLocked ? productCostPrice ?? '' : purchasePrice
  const derivedValues: Record<PricingField, string> = {
    margin: marginFromCost(costBasis, salePriceHt),
    priceHt: salePriceHt,
    priceTtc: priceTtcFromHt(salePriceHt, taxRate, moneyScale),
  }
  const [focusedField, setFocusedField] = useState<PricingField | null>(null)
  const [drafts, setDrafts] = useState<Record<PricingField, string>>({ ...derivedValues })
  const draftValues = useRef<Record<PricingField, string>>({ ...derivedValues })
  const focusBaselines = useRef<Record<PricingField, string>>({ ...derivedValues })

  const commitField = (field: PricingField, value: string): void => {
    if (field === 'margin') {
      const nextHt = priceHtFromMargin(costBasis, value, moneyScale)
      if (nextHt !== '') setValue('sale_price', nextHt, { shouldDirty: true })
      return
    }
    if (field === 'priceHt') {
      setValue('sale_price', value, { shouldDirty: true })
      return
    }
    setValue('sale_price', priceHtFromTtc(value, taxRate, moneyScale), { shouldDirty: true })
  }

  const fieldController = (field: PricingField): ProductPricingFieldController => ({
    value: focusedField === field ? drafts[field] : derivedValues[field],
    onFocus: () => {
      draftValues.current[field] = derivedValues[field]
      focusBaselines.current[field] = derivedValues[field]
      setDrafts((current) => ({ ...current, [field]: derivedValues[field] }))
      setFocusedField(field)
    },
    onChange: (value) => {
      draftValues.current[field] = value
      setDrafts((current) => ({ ...current, [field]: value }))
    },
    onBlur: () => {
      const value = draftValues.current[field]
      setFocusedField(null)
      if (value !== focusBaselines.current[field]) commitField(field, value)
    },
  })

  const commitCost = (value: string): void => {
    setValue('purchase_price', value, { shouldDirty: true })
    if (canEnterOpening && openingQty.trim() !== '' && openingQty.trim() !== '0') {
      setValue('opening_unit_cost', value, { shouldDirty: true })
    }
  }

  return {
    costBasis,
    cost: purchasePrice,
    margin: fieldController('margin'),
    priceHt: fieldController('priceHt'),
    priceTtc: fieldController('priceTtc'),
    commitCost,
  }
}
