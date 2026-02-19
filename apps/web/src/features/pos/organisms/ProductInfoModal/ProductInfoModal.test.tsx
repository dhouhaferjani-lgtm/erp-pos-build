import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ProductInfoModal, type ProductDetailResponse, type StockLevel } from './ProductInfoModal'
import * as api from '@/lib/api'

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

const mockProduct: ProductDetailResponse = {
  id: '1',
  name: 'Test Product',
  sku: 'TEST-001',
  description: 'Test product description',
  sale_price: '29.99',
  tax_rate: '19',
  category: {
    id: 'cat-1',
    name: 'Test Category',
  },
  image_url: 'https://example.com/image.jpg',
}

const mockProductWithParapharmacy: ProductDetailResponse = {
  ...mockProduct,
  parapharmacy_metadata: {
    ingredients: [
      {
        id: 'ing-1',
        name: { en: 'Vitamin C', fr: 'Vitamine C' },
        concentration: '500mg',
      },
      {
        id: 'ing-2',
        name: { en: 'Zinc', fr: 'Zinc' },
        concentration: '15mg',
      },
    ],
    key_components: [
      {
        id: 'kc-1',
        name: { en: 'Antioxidant Blend', fr: 'Mélange antioxydant' },
        benefit: { en: 'Supports immune system', fr: 'Soutient le système immunitaire' },
      },
    ],
    health_claims: [
      {
        id: 'hc-1',
        claim: { en: 'Contributes to normal immune function', fr: 'Contribue à la fonction immunitaire normale' },
        regulation_reference: 'EU Reg 432/2012',
      },
    ],
    certifications: [
      {
        id: 'cert-1',
        name: { en: 'Organic Certified', fr: 'Certifié biologique' },
        logo_url: 'https://example.com/cert-logo.jpg',
        issuing_body: 'EU Organic',
      },
    ],
  },
}

const mockStockLevels: StockLevel[] = [
  {
    location_id: 'loc-1',
    location_name: 'Main Warehouse',
    available: 50,
    reserved: 10,
    total: 60,
  },
  {
    location_id: 'loc-2',
    location_name: 'Retail Store',
    available: 5,
    reserved: 2,
    total: 7,
  },
  {
    location_id: 'loc-3',
    location_name: 'Distribution Center',
    available: 0,
    reserved: 0,
    total: 0,
  },
]

function createTestQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
    },
  })
}

function renderWithClient(ui: React.ReactElement) {
  const queryClient = createTestQueryClient()
  return render(<QueryClientProvider client={queryClient}>{ui}</QueryClientProvider>)
}

describe('ProductInfoModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('should not render when isOpen is false', () => {
    renderWithClient(
      <ProductInfoModal isOpen={false} onClose={vi.fn()} productId="1" />
    )

    expect(screen.queryByText('Product Information')).not.toBeInTheDocument()
  })

  it('should render modal when isOpen is true', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Product Information')).toBeInTheDocument()
    })
  })

  it('should display loading state while fetching product', () => {
    vi.mocked(api.apiGet).mockImplementation(() => new Promise(() => {}))

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    // Check for loading spinner by class or test-id
    const spinner = document.querySelector('.animate-spin')
    expect(spinner).toBeInTheDocument()
  })

  it('should display error state when product fetch fails', async () => {
    vi.mocked(api.apiGet).mockRejectedValueOnce(new Error('Failed to fetch'))

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Error loading data')).toBeInTheDocument()
    })
  })

  it('should display product details in Details tab', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
      expect(screen.getByText('TEST-001')).toBeInTheDocument()
      expect(screen.getByText('Test Category')).toBeInTheDocument()
      expect(screen.getByText('29.99 TND')).toBeInTheDocument()
      expect(screen.getByText('19%')).toBeInTheDocument()
      expect(screen.getByText('Test product description')).toBeInTheDocument()
    })
  })

  it('should display product image when available', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithClient(
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

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      // Package icon should be rendered as placeholder
      expect(screen.queryByAltText('Test Product')).not.toBeInTheDocument()
    })
  })

  it('should render all three tabs when product has parapharmacy data', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProductWithParapharmacy)

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
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

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Details')).toBeInTheDocument()
      expect(screen.getByText('Stock Levels')).toBeInTheDocument()
      // Only two tabs, not three
      const tabs = screen.getAllByRole('tab')
      expect(tabs).toHaveLength(2)
    })
  })

  it('should switch to stock tab and load stock data', async () => {
    const user = userEvent.setup()
    vi.mocked(api.apiGet)
      .mockResolvedValueOnce(mockProduct) // Product details
      .mockResolvedValueOnce(mockStockLevels) // Stock levels

    renderWithClient(
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

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
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

    renderWithClient(
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

    renderWithClient(
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

    renderWithClient(
      <ProductInfoModal isOpen={true} onClose={vi.fn()} productId="1" />
    )

    await waitFor(() => {
      expect(screen.getByText('Test Product')).toBeInTheDocument()
    })

    expect(screen.queryByRole('button', { name: /Add/i })).not.toBeInTheDocument()
  })

  it('should apply touch-optimized styles when touchOptimized is true', async () => {
    vi.mocked(api.apiGet).mockResolvedValueOnce(mockProduct)

    renderWithClient(
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
    vi.mocked(api.apiGet)
      .mockResolvedValueOnce(mockProduct)
      .mockResolvedValueOnce([])

    renderWithClient(
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
    vi.mocked(api.apiGet)
      .mockResolvedValueOnce(mockProduct)
      .mockResolvedValueOnce(mockStockLevels)

    renderWithClient(
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
      expect(availableCell).toHaveClass('text-yellow-600')
    })
  })

  it('should highlight out of stock with red color', async () => {
    const user = userEvent.setup()
    vi.mocked(api.apiGet)
      .mockResolvedValueOnce(mockProduct)
      .mockResolvedValueOnce(mockStockLevels)

    renderWithClient(
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
      expect(availableCell).toHaveClass('text-red-600')
    })
  })
})
