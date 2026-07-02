import { createContext, useContext, useEffect, useMemo, type ReactNode } from 'react'

/**
 * Supported product variants
 */
export type Product = 'izipos' | 'otospex'

/**
 * Product configuration details
 */
interface ProductInfo {
  name: string
  description: string
}

/**
 * Context value for product configuration
 */
interface ProductConfigContextValue {
  product: Product
  isIziPOS: boolean
  isOtospex: boolean
  productName: string
  productDescription: string
}

const ProductConfigContext = createContext<ProductConfigContextValue | undefined>(undefined)

/**
 * Product information mapping
 */
const PRODUCT_INFO: Record<Product, ProductInfo> = {
  izipos: {
    name: 'IziPOS',
    description: 'Modern POS and business management system',
  },
  otospex: {
    name: 'Otospex',
    description: 'Complete automotive business management solution',
  },
}

interface ProductConfigProviderProps {
  children: ReactNode
  /**
   * Test-only seed that bypasses `VITE_APP_PRODUCT` detection. Production
   * callers never pass this; `renderWithProviders` forwards it so tests can
   * render components against a deterministic product variant.
   */
  initialProduct?: Product
}

/**
 * Get current product from environment variable
 *
 * Defaults to 'izipos' if not set or invalid.
 */
function getCurrentProduct(): Product {
  const envProduct = import.meta.env['VITE_APP_PRODUCT']

  if (!envProduct) {
    return 'izipos'
  }

  const normalized = envProduct.toLowerCase() as Product

  // Validate against known products
  if (normalized === 'izipos' || normalized === 'otospex') {
    return normalized
  }

  // Default to izipos for invalid values
  console.warn(`Invalid VITE_APP_PRODUCT value: ${envProduct}. Defaulting to 'izipos'.`)
  return 'izipos'
}

/**
 * Provider that provides product configuration
 *
 * The product is determined by the VITE_APP_PRODUCT environment variable:
 * - 'izipos': POS-focused variant
 * - 'otospex': Automotive-focused variant
 *
 * This allows the same codebase to serve different products with
 * different branding, features, and vertical focus.
 */
export function ProductConfigProvider({
  children,
  initialProduct,
}: ProductConfigProviderProps) {
  const value = useMemo<ProductConfigContextValue>(() => {
    const product = initialProduct ?? getCurrentProduct()
    const info = PRODUCT_INFO[product]

    return {
      product,
      isIziPOS: product === 'izipos',
      isOtospex: product === 'otospex',
      productName: info.name,
      productDescription: info.description,
    }
  }, [initialProduct])

  useEffect(() => {
    if (document.title === '' || document.title === value.productName || !document.title.includes(' | ')) {
      document.title = value.productName
    }
  }, [value.productName])

  return <ProductConfigContext.Provider value={value}>{children}</ProductConfigContext.Provider>
}

/**
 * Hook to access product configuration
 *
 * @example
 * ```tsx
 * function Header() {
 *   const { productName, isIziPOS } = useProductConfig()
 *
 *   return (
 *     <h1>{productName}</h1>
 *     {isIziPOS && <POSQuickActions />}
 *   )
 * }
 * ```
 */
export function useProductConfig(): ProductConfigContextValue {
  const context = useContext(ProductConfigContext)

  if (context === undefined) {
    throw new Error('useProductConfig must be used within a ProductConfigProvider')
  }

  return context
}
