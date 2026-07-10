import { act, renderHook } from '@testing-library/react'
import { useForm } from 'react-hook-form'
import { describe, expect, it } from 'vitest'

import type { ProductSectionFormData } from './types'
import { useProductPricingEditAdapter } from './useProductPricingEditAdapter'

function usePricingHarness() {
  const form = useForm<ProductSectionFormData>({
    defaultValues: {
      purchase_price: '100.000',
      sale_price: '120.000',
      tax_rate: '20.00',
      opening_qty: '2.0000',
      opening_unit_cost: '100.000',
    },
  })
  const pricing = useProductPricingEditAdapter({
    control: form.control,
    setValue: form.setValue,
    isOpeningLocked: false,
    productCostPrice: null,
    canEnterOpening: true,
    moneyScale: 3,
  })

  return { form, pricing }
}

describe('useProductPricingEditAdapter', () => {
  it('keeps the literal margin draft focused and commits canonical HT only on blur', () => {
    const { result } = renderHook(usePricingHarness)

    expect(result.current.pricing.margin.value).toBe('20.00')
    expect(result.current.pricing.priceHt.value).toBe('120.000')
    expect(result.current.pricing.priceTtc.value).toBe('144.000')

    act(() => {
      result.current.pricing.margin.onFocus()
      result.current.pricing.margin.onChange('50.')
    })

    expect(result.current.pricing.margin.value).toBe('50.')
    expect(result.current.form.getValues('sale_price')).toBe('120.000')

    act(() => {
      result.current.pricing.margin.onBlur()
    })

    expect(result.current.form.getValues('sale_price')).toBe('150.000')
    expect(result.current.pricing.priceHt.value).toBe('150.000')
    expect(result.current.pricing.priceTtc.value).toBe('180.000')
  })

  it('back-solves TTC to canonical HT and updates cost/opening values in the same controller', () => {
    const { result } = renderHook(usePricingHarness)

    act(() => {
      result.current.pricing.priceTtc.onFocus()
    })
    act(() => {
      result.current.pricing.priceTtc.onChange('180.000')
    })
    act(() => {
      result.current.pricing.priceTtc.onBlur()
    })

    expect(result.current.form.getValues('sale_price')).toBe('150.000')

    act(() => {
      result.current.pricing.commitCost('110.000')
    })

    expect(result.current.form.getValues('purchase_price')).toBe('110.000')
    expect(result.current.form.getValues('opening_unit_cost')).toBe('110.000')
  })

  it('does not mutate or dirty canonical HT when a derived field is focused and blurred unchanged', () => {
    const { result } = renderHook(usePricingHarness)

    act(() => {
      result.current.form.setValue('sale_price', '137.777')
    })
    act(() => {
      result.current.pricing.margin.onFocus()
    })
    act(() => {
      result.current.pricing.margin.onBlur()
    })

    expect(result.current.form.getValues('sale_price')).toBe('137.777')
    expect(result.current.form.getFieldState('sale_price').isDirty).toBe(false)
  })
})
