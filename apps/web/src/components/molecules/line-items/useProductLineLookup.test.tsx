import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { apiGet } from '../../../lib/api'
import { useProductLineLookup } from './useProductLineLookup'

vi.mock('../../../lib/api', () => ({
  apiGet: vi.fn(),
}))

const apiGetMock = vi.mocked(apiGet)

const product = {
  id: 'product-1',
  name: 'Crème solaire SPF50',
  sku: 'CS-050',
  barcode: '6194000123456',
  sale_price: '14.280',
  purchase_price: '8.500',
  cost_price: '8.500',
  tax_rate: '19.00',
  default_tax_configuration_id: null,
  quantity_decimals: 0,
  primary_image_url: null,
  has_variants: false,
  requires_batch_tracking: false,
}

describe('useProductLineLookup', () => {
  beforeEach(() => {
    apiGetMock.mockReset()
  })

  it('returns product outcomes from the line-entry resolver', async () => {
    apiGetMock.mockResolvedValueOnce({
      kind: 'product',
      matched_code_type: 'product_barcode',
      product,
    })

    const { result } = renderHook(() => useProductLineLookup())

    await expect(result.current.lookupCode('6194000123456')).resolves.toMatchObject({
      kind: 'product',
      product: { id: 'product-1' },
    })

    expect(apiGetMock).toHaveBeenCalledWith('/line-entry/resolve-code', { code: '6194000123456' })
  })

  it('returns variant outcomes with parent product and variant identity', async () => {
    apiGetMock.mockResolvedValueOnce({
      kind: 'variant',
      matched_code_type: 'variant_barcode',
      product: { ...product, id: 'product-2', has_variants: true },
      variant: {
        id: 'variant-1',
        product_id: 'product-2',
        sku: 'CS-050-L',
        variant_code: 'VAR-L',
        barcode: 'VARBAR',
        name_suffix: 'Large',
        is_default: false,
        price_override: '15.000',
        cost_override: null,
        image_url: null,
      },
    })

    const { result } = renderHook(() => useProductLineLookup())

    await expect(result.current.lookupCode('VARBAR')).resolves.toMatchObject({
      kind: 'variant',
      product: { id: 'product-2' },
      variant: { id: 'variant-1', product_id: 'product-2' },
    })
  })

  it('serializes rapid scans and coalesces duplicate in-flight codes into one lookup with a count', async () => {
    let releaseFirst: ((value: unknown) => void) | null = null
    apiGetMock
      .mockImplementationOnce(() => new Promise((resolve) => { releaseFirst = resolve }))
      .mockResolvedValueOnce({
        kind: 'product',
        matched_code_type: 'product_barcode',
        product: { ...product, id: 'product-2', sku: 'NEXT' },
      })

    const { result } = renderHook(() => useProductLineLookup())
    const resolved: { kind: string; incrementBy?: number; product?: { id: string } }[] = []

    void result.current.enqueueScan('6194000123456').then((outcome) => {
      resolved.push(outcome)
    })
    void result.current.enqueueScan('6194000123456').then((outcome) => {
      resolved.push(outcome)
    })
    void result.current.enqueueScan('NEXT').then((outcome) => {
      resolved.push(outcome)
    })

    expect(apiGetMock).toHaveBeenCalledTimes(1)

    await act(async () => {
      releaseFirst?.({
        kind: 'product',
        matched_code_type: 'product_barcode',
        product,
      })
      await Promise.resolve()
    })

    await waitFor(() => {
      expect(resolved).toHaveLength(3)
    })

    expect(apiGetMock).toHaveBeenCalledTimes(2)
    expect(resolved.map((item) => item.product?.id)).toEqual(['product-1', 'product-1', 'product-2'])
    expect(resolved[0].incrementBy).toBe(2)
    expect(resolved[1].incrementBy).toBe(2)
  })
})
