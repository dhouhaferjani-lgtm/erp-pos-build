import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CompanyConfigProvider } from '../../../contexts/CompanyConfigContext'
import { StandardPOS } from '../StandardPOS'
import * as api from '../../../lib/api'

// Mock the API
vi.mock('../../../lib/api', () => ({
  apiGet: vi.fn(),
}))

describe('StandardPOS - Vertical Adaptation', () => {
  let queryClient: QueryClient

  beforeEach(() => {
    queryClient = new QueryClient({
      defaultOptions: {
        queries: {
          retry: false,
        },
      },
    })
    vi.clearAllMocks()
  })

  const renderPOS = () => {
    return render(
      <QueryClientProvider client={queryClient}>
        <CompanyConfigProvider>
          <StandardPOS />
        </CompanyConfigProvider>
      </QueryClientProvider>
    )
  }

  describe('Retail Vertical', () => {
    beforeEach(() => {
      const retailConfig = {
        vertical: 'retail',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        currency: 'USD',
        locale: 'en_US',
        country_code: 'US',
      }
      vi.mocked(api.apiGet).mockResolvedValue(retailConfig)
    })

    it('renders POS interface for retail vertical', async () => {
      renderPOS()

      // Should show POS interface elements
      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
    })

    it('shows product search in retail POS', async () => {
      renderPOS()

      const searchBox = await screen.findByPlaceholderText(/search products/i)
      expect(searchBox).toBeInTheDocument()
    })
  })

  describe('Pharmacy Vertical', () => {
    beforeEach(() => {
      const pharmacyConfig = {
        vertical: 'pharmacy',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product', 'BatchExpiry'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product', 'BatchExpiry'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(pharmacyConfig)
    })

    it('renders POS interface for pharmacy vertical', async () => {
      renderPOS()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
    })

    it('shows product search in pharmacy POS', async () => {
      renderPOS()

      const searchBox = await screen.findByPlaceholderText(/search products/i)
      expect(searchBox).toBeInTheDocument()
    })
  })

  describe('Mechanic Vertical (Otospex)', () => {
    beforeEach(() => {
      const mechanicConfig = {
        vertical: 'mechanic',
        default_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Inventory'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Vehicle', 'Workshop', 'Sales', 'Inventory'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(mechanicConfig)
    })

    it('renders POS interface for mechanic vertical', async () => {
      renderPOS()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
    })
  })

  describe('Parts Retailer Vertical', () => {
    beforeEach(() => {
      const partsConfig = {
        vertical: 'parts_retailer',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(partsConfig)
    })

    it('renders POS interface for parts retailer', async () => {
      renderPOS()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
    })
  })

  describe('Loading State', () => {
    it('shows loading state while config is being fetched', () => {
      // Delay the API response
      vi.mocked(api.apiGet).mockImplementation(
        () => new Promise(() => {}) // Never resolves
      )

      renderPOS()

      // Should show loading indicator
      const loadingIndicator = screen.queryByTestId('standard-pos')
      expect(loadingIndicator).not.toBeInTheDocument()
    })
  })

  describe('Error State', () => {
    beforeEach(() => {
      vi.mocked(api.apiGet).mockRejectedValue(new Error('Failed to fetch config'))
    })

    it('handles config fetch error gracefully', async () => {
      renderPOS()

      // Wait for the query to fail and error state to render
      await new Promise((resolve) => setTimeout(resolve, 100))

      // StandardPOS should show error message or not render POS interface
      // Since error state returns an error div, check for it
      const posInterface = screen.queryByTestId('standard-pos')
      expect(posInterface).not.toBeInTheDocument()
    })
  })

  describe('Cart Functionality', () => {
    beforeEach(() => {
      const retailConfig = {
        vertical: 'retail',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        currency: 'USD',
        locale: 'en_US',
        country_code: 'US',
      }
      vi.mocked(api.apiGet).mockResolvedValue(retailConfig)
    })

    it('shows empty cart initially', async () => {
      renderPOS()

      // Check for the main "Empty cart" text specifically
      const emptyCartText = await screen.findByText('Empty cart')
      expect(emptyCartText).toBeInTheDocument()

      // Also verify "No items in cart" text is present
      const noItemsText = screen.getByText('No items in cart')
      expect(noItemsText).toBeInTheDocument()
    })

    it('displays cart total section', async () => {
      renderPOS()

      const totalSection = await screen.findByTestId('cart-total')
      expect(totalSection).toBeInTheDocument()
    })
  })

  describe('Vertical-Specific Features', () => {
    it('adapts UI based on vertical configuration', async () => {
      const retailConfig = {
        vertical: 'retail',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        currency: 'USD',
        locale: 'en_US',
        country_code: 'US',
      }
      vi.mocked(api.apiGet).mockResolvedValue(retailConfig)

      renderPOS()

      // POS should render with retail-specific configuration
      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toHaveAttribute('data-vertical', 'retail')
    })
  })
})
