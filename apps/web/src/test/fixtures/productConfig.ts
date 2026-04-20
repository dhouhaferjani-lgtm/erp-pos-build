import type { Product } from '@/contexts/ProductConfigContext'

/**
 * Seed value for ProductConfigProvider in tests.
 *
 * The real provider reads VITE_APP_PRODUCT at build time; tests pass
 * `productConfig` into `renderWithProviders`, which forwards it as the
 * `initialProduct` prop to short-circuit detection.
 */
export interface TestProductConfig {
  product: Product
}

export const defaultProductConfig: TestProductConfig = {
  product: 'izipos',
}

export const otospexProductConfig: TestProductConfig = {
  product: 'otospex',
}
