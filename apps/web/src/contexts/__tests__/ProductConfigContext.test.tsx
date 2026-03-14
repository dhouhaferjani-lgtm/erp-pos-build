import { describe, it, expect, afterEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { ProductConfigProvider, useProductConfig } from '../ProductConfigContext'

describe('ProductConfigContext', () => {
  const originalEnv = import.meta.env['VITE_APP_PRODUCT']

  afterEach(() => {
    // Restore original env
    import.meta.env['VITE_APP_PRODUCT'] = originalEnv
  })

  const wrapper = ({ children }: { children: ReactNode }) => (
    <ProductConfigProvider>{children}</ProductConfigProvider>
  )

  it('provides IziPOS product config when VITE_APP_PRODUCT=izipos', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'izipos'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.product).toBe('izipos')
    expect(result.current.isIziPOS).toBe(true)
    expect(result.current.isOtospex).toBe(false)
  })

  it('provides Otospex product config when VITE_APP_PRODUCT=otospex', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'otospex'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.product).toBe('otospex')
    expect(result.current.isIziPOS).toBe(false)
    expect(result.current.isOtospex).toBe(true)
  })

  it('defaults to IziPOS when VITE_APP_PRODUCT is not set', () => {
    delete import.meta.env['VITE_APP_PRODUCT']

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.product).toBe('izipos')
    expect(result.current.isIziPOS).toBe(true)
    expect(result.current.isOtospex).toBe(false)
  })

  it('provides product name', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'izipos'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.productName).toBe('IziPOS')
  })

  it('provides product name for Otospex', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'otospex'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.productName).toBe('Otospex')
  })

  it('provides product description for IziPOS', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'izipos'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.productDescription).toBeTruthy()
    expect(result.current.productDescription).toContain('POS')
  })

  it('provides product description for Otospex', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'otospex'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.productDescription).toBeTruthy()
    expect(result.current.productDescription).toContain('automotive')
  })

  it('throws error when used outside provider', () => {
    expect(() => {
      renderHook(() => useProductConfig())
    }).toThrow('useProductConfig must be used within a ProductConfigProvider')
  })

  it('handles case-insensitive product values', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'IZIPOS'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.product).toBe('izipos')
    expect(result.current.isIziPOS).toBe(true)
  })

  it('handles invalid product values by defaulting to IziPOS', () => {
    import.meta.env['VITE_APP_PRODUCT'] = 'invalid_product'

    const { result } = renderHook(() => useProductConfig(), { wrapper })

    expect(result.current.product).toBe('izipos')
    expect(result.current.isIziPOS).toBe(true)
  })
})
