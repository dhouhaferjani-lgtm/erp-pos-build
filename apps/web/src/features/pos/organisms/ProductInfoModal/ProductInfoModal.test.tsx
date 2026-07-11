import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { ProductInfoModal, type StockLevel } from './ProductInfoModal'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import {
  makeProductDetail,
  makeProductDetailWithParapharmacy,
  makeStockLevel,
  makeStockLevelsResponse,
} from '@/features/pos/__fixtures__/productInfo'
import {
  parapharmacyCompanyConfig,
  genericWithParapharmacyExtraCompanyConfig,
} from '@/test/fixtures/companyConfig'
import * as api from '@/lib/api'

// Mock useCurrency
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'EUR',
    locale: 'fr-FR',
    decimals: 2,
    format: (value: string | number) => {
      const num = typeof value === 'string' ? parseFloat(value) : value
      return `${num.toFixed(2)} EUR`
    },
    toFixed: (value: number) => value.toFixed(2),
  }),
  getDecimals: (currency: string) => currency === 'TND' || currency === 'LYD' ? 3 : 2,
  getLocale: (_currency: string) => 'fr-FR',
  formatAmount: (value: string | number, currency: string) => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    const decimals = currency === 'TND' || currency === 'LYD' ? 3 : 2
    return `${num.toFixed(decimals)} ${currency}`
  },
}))

// Mock i18next
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => {
      const translations: Record<string, string> = {
        'pos:productInfo.title': 'Product Information',
        'pos:productInfo.tabs.details': 'Details',
        'pos:productInfo.tabs.stock': 'Stock Levels',
        'pos:productInfo.tabs.parapharmacy': 'Product Information',
        'pos:productInfo.fields.sku': 'SKU',
        'pos:productInfo.fields.category': 'Category',
        'pos:productInfo.fields.price': 'Price',
        'pos:productInfo.fields.taxRate': 'VAT Rate',
        'pos:productInfo.fields.location': 'Location',
        'pos:productInfo.fields.available': 'Available',
        'pos:productInfo.fields.reserved': 'Reserved',
        'pos:productInfo.fields.total': 'Total',
        'common:actions.close': 'Close',
        'common:actions.add': 'Add',
        'common:errors.loadingFailed': 'Error loading data',
        'common:noData': 'No data available',
        'products:parapharmacy.activeIngredients': 'Active Ingredients',
        'products:parapharmacy.keyComponents': 'Key Components',
        'products:parapharmacy.healthClaims': 'Health Claims',
        'products:parapharmacy.certifications': 'Certifications',
        'common:fields.description': 'Description',
      }
      return translations[key] || key
    },
    i18n: {
      language: 'en',
    },
  }),
}))

// Mock API module
vi.mock('@/lib/api', () => ({
  apiGet: vi.fn(),
}))

const mockProduct = makeProductDetail()
const mockProductWithParapharmacy = makeProductDetailWithParapharmacy()

const mockStockLevels = [
  makeStockLevel({
    id: 'stock-1',
    location_id: 'loc-1',
    location_name: 'Main Warehouse',
    quantity: '60',
    available: '50',
    reserved: '10',
  }),
  makeStockLevel({
    id: 'stock-2',
    location_id: 'loc-2',
    location_name: 'Retail Store',
    quantity: '7',
    available: '5',
    reserved: '2',
    projected_available: '5',
  }),
  makeStockLevel({
    id: 'stock-3',
    location_id: 'loc-3',
    location_name: 'Distribution Center',
    quantity: '0',
    available: '0',
    reserved: '0',
    projected_available: '0',
  }),
]

/**
 * Route `apiGet` by URL so product detail and stock-levels queries
 * always resolve to the right payload, regardless of call order or
 * query-client re-firing.
 */
function configureApiMock(
  product: ReturnType<typeof makeProductDetail> = mockProduct,
  stockLocations: StockLevel[] = mockStockLevels,
) {
  vi.mocked(api.apiGet).mockImplementation(((url: string) => {
    if (url.endsWith('/stock-levels')) {
      return Promise.resolve(makeStockLevelsResponse(stockLocations))
    }
    return Promise.resolve(product)
  }) as typeof api.apiGet)
}

describe('ProductInfoModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    seedAuth()
  })

  afterEach(() => {
    resetAuth()
  })

  it('should not render when isOpen is false', () => {
    renderWithProviders(
      <ProductInfoModal isOpen={false} onClose={vi.fn()} productId="1" />
    )

    expect(screen.queryByText('Product Information')).not.toBeInTheDocument()
  })

  it('should render modal when isOpen is true', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Product Information')).toBeInTheDocument()
    })
  })

  it('should display loading state while fetching product', () => {
    vi.mocked(api.apiGet).mockImplementation(() => new Promise(() => {}))

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    // Check for loading spinner by class or test-id
    const spinner = document.querySelector('.animate-spin')
    expect(spinner).toBeInTheDocument()
  })

  it('should display error state when product fetch fails', async () => {
    vi.mocked(api.apiGet).mockRejectedValueOnce(new Error('Failed to fetch'))

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Error loading data')).toBeInTheDocument()
    })
  })

  it('should display product details in Details tab', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
      expect(screen.getByText('TEST-001')).toBeInTheDocument()
      expect(screen.getByText('Test Category')).toBeInTheDocument()
      expect(screen.getByText('29.99 EUR')).toBeInTheDocument()
      expect(screen.getByText('19%')).toBeInTheDocument()
      expect(screen.getByText('Test product description')).toBeInTheDocument()
    })
  })

  it('should display product image when available', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      const image = screen.getByAltText('Test Product')
      expect(image).toBeInTheDocument()
      expect(image).toHaveAttribute('src', 'https://example.com/image.jpg')
    })
  })

  it('should display placeholder when no image available', async () => {
    const productWithoutImage = { ...mockProduct, image_url: undefined }
    vi.mocked(api.apiGet).mockResolvedValueOnce(productWithoutImage)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      // Package icon should be rendered as placeholder
      expect(screen.queryByAltText('Test Product')).not.toBeInTheDocument()
    })
  })

  it('should render all three tabs when product has parapharmacy data and the tenant vertical is parapharmacy', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProductWithParapharmacy)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />,
      { companyConfig: parapharmacyCompanyConfig }
    )

    await waitFor(() => {
      expect(screen.getByText('Details')).toBeInTheDocument()
      expect(screen.getByText('Stock Levels')).toBeInTheDocument()
      // Get all tabs and verify there are 3
      const tabs = screen.getAllByRole('tab')
      expect(tabs).toHaveLength(3)
    })
  })

  it('should not render parapharmacy tab when product has no parapharmacy data', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />,
      { companyConfig: parapharmacyCompanyConfig }
    )

    await waitFor(() => {
      expect(screen.getByText('Details')).toBeInTheDocument()
      expect(screen.getByText('Stock Levels')).toBeInTheDocument()
      // Only two tabs, not three
      const tabs = screen.getAllByRole('tab')
      expect(tabs).toHaveLength(2)
    })
  })

  it('should NOT render parapharmacy tab when the tenant vertical is not parapharmacy even if product has parapharmacy data', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProductWithParapharmacy)

    // No companyConfig provided → fail closed (vertical unknown)
    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    // Only two tabs, not three — vertical gate hides the parapharmacy tab
    const tabs = screen.getAllByRole('tab')
    expect(tabs).toHaveLength(2)
  })

  it('should NOT render parapharmacy tab when the Parapharmacy module is enabled as an extra but the vertical is not parapharmacy', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProductWithParapharmacy)

    // genericWithParapharmacyExtraCompanyConfig: module enabled, vertical=generic.
    // The backend authorizes by vertical and would 422-reject this metadata, so
    // the tab must stay hidden despite the module being enabled.
    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />,
      { companyConfig: genericWithParapharmacyExtraCompanyConfig }
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    const tabs = screen.getAllByRole('tab')
    expect(tabs).toHaveLength(2)
  })

  it('should switch to stock tab and load stock data', async () => {
    const user = userEvent.setup()
    configureApiMock()

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    // Click Stock Levels tab
    const stockTab = screen.getByText('Stock Levels')
    await user.click(stockTab)

    await waitFor(() => {
      expect(screen.getByText('Main Warehouse')).toBeInTheDocument()
      expect(screen.getByText('Retail Store')).toBeInTheDocument()
      expect(screen.getByText('Distribution Center')).toBeInTheDocument()
    })
  })

  it('should display parapharmacy data in parapharmacy tab', async () => {
    const user = userEvent.setup()
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProductWithParapharmacy)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />,
      { companyConfig: parapharmacyCompanyConfig }
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    // Click Parapharmacy tab (third tab)
    const parapharmacyTab = screen.getByRole('tab', { name: /Product Information/i })
    await user.click(parapharmacyTab)

    await waitFor(() => {
      // Ingredients
      expect(screen.getByText('Active Ingredients')).toBeInTheDocument()
      expect(screen.getByText(/Vitamin C/i)).toBeInTheDocument()
      expect(screen.getByText(/500mg/i)).toBeInTheDocument()
      expect(screen.getByText(/Zinc/i)).toBeInTheDocument()

      // Key Components
      expect(screen.getByText('Key Components')).toBeInTheDocument()
      expect(screen.getByText('Antioxidant Blend')).toBeInTheDocument()

      // Health Claims
      expect(screen.getByText('Health Claims')).toBeInTheDocument()
      expect(screen.getByText(/Contributes to normal immune function/i)).toBeInTheDocument()

      // Certifications
      expect(screen.getByText('Certifications')).toBeInTheDocument()
      expect(screen.getByText('Organic Certified')).toBeInTheDocument()
    })
  })

  it('should call onClose when close button is clicked', async () => {
    const user = userEvent.setup()
    const onClose = vi.fn()
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={onClose} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    const closeButton = screen.getByLabelText('Close')
    await user.click(closeButton)

    expect(onClose).toHaveBeenCalledTimes(1)
  })

  it('should call onAddToCart when Add button is clicked', async () => {
    const user = userEvent.setup()
    const onAddToCart = vi.fn()
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal
        isOpen={true}
        onClose={vi.fn()}
        productId="1"
        onAddToCart={onAddToCart}
      />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    const addButton = screen.getByRole('button', { name: /Add/i })
    await user.click(addButton)

    expect(onAddToCart).toHaveBeenCalledTimes(1)
    expect(onAddToCart).toHaveBeenCalledWith(mockProduct)
  })

  it('should not render Add button when onAddToCart is not provided', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    expect(screen.queryByRole('button', { name: /Add/i })).not.toBeInTheDocument()
  })

  it('should apply touch-optimized styles when touchOptimized is true', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithProviders(
      <ProductInfoModal
        isOpen={true}
        onClose={vi.fn()}
        productId="1"
        touchOptimized={true}
      />
    )

    await waitFor(() => {
      const productName = screen.getByText('Test Product')
      expect(productName).toHaveClass('text-xl')
    })
  })

  it('should display "No data available" when stock levels are empty', async () => {
    const user = userEvent.setup()
    configureApiMock(mockProduct, [])

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    const stockTab = screen.getByText('Stock Levels')
    await user.click(stockTab)

    await waitFor(() => {
      expect(screen.getByText('No data available')).toBeInTheDocument()
    })
  })

  it('should highlight low stock levels with yellow color', async () => {
    const user = userEvent.setup()
    configureApiMock()

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    const stockTab = screen.getByText('Stock Levels')
    await user.click(stockTab)

    await waitFor(() => {
      const retailStoreRow = screen.getByText('Retail Store').closest('tr')
      const availableCell = retailStoreRow?.querySelector('td:nth-child(2) span')
      expect(availableCell).toHaveClass(colorTokens.intent.warning.text)
    })
  })

  it('should highlight out of stock with red color', async () => {
    const user = userEvent.setup()
    configureApiMock()

    renderWithProviders(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    const stockTab = screen.getByText('Stock Levels')
    await user.click(stockTab)

    await waitFor(() => {
      const distributionRow = screen.getByText('Distribution Center').closest('tr')
      const availableCell = distributionRow?.querySelector('td:nth-child(2) span')
      expect(availableCell).toHaveClass(colorTokens.intent.danger.textStrong)
    })
  })
})
