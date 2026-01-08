import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CompanyConfigProvider } from '../../../contexts/CompanyConfigContext'
import { POSPage } from '../POSPage'
import * as api from '../../../lib/api'

// Mock the API
vi.mock('../../../lib/api', () => ({
  apiGet: vi.fn(),
}))

describe('POSPage - Loader Component', () => {
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

  const renderPOSPage = () => {
    return render(
      <QueryClientProvider client={queryClient}>
        <CompanyConfigProvider>
          <POSPage />
        </CompanyConfigProvider>
      </QueryClientProvider>
    )
  }

  describe('Phase 1: StandardPOS for All Verticals', () => {
    it('loads StandardPOS for retail vertical', async () => {
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

      renderPOSPage()

      // Should load StandardPOS component
      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
      expect(posInterface).toHaveAttribute('data-vertical', 'retail')
    })

    it('loads StandardPOS for pharmacy vertical', async () => {
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

      renderPOSPage()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
      expect(posInterface).toHaveAttribute('data-vertical', 'pharmacy')
    })

    it('loads StandardPOS for restaurant vertical', async () => {
      const restaurantConfig = {
        vertical: 'restaurant',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product', 'Menu', 'Tables'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product', 'Menu', 'Tables'],
        currency: 'EUR',
        locale: 'fr_FR',
        country_code: 'FR',
      }
      vi.mocked(api.apiGet).mockResolvedValue(restaurantConfig)

      renderPOSPage()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
      expect(posInterface).toHaveAttribute('data-vertical', 'restaurant')
    })

    it('loads StandardPOS for mechanic vertical', async () => {
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

      renderPOSPage()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
      expect(posInterface).toHaveAttribute('data-vertical', 'mechanic')
    })

    it('loads StandardPOS for coffee shop vertical', async () => {
      const coffeeShopConfig = {
        vertical: 'coffee_shop',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        currency: 'EUR',
        locale: 'fr_FR',
        country_code: 'FR',
      }
      vi.mocked(api.apiGet).mockResolvedValue(coffeeShopConfig)

      renderPOSPage()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
      expect(posInterface).toHaveAttribute('data-vertical', 'coffee_shop')
    })

    it('loads StandardPOS for parts retailer vertical', async () => {
      const partsRetailerConfig = {
        vertical: 'parts_retailer',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(partsRetailerConfig)

      renderPOSPage()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toBeInTheDocument()
      expect(posInterface).toHaveAttribute('data-vertical', 'parts_retailer')
    })
  })

  describe('Loading State', () => {
    it('shows loading screen while config is being fetched', () => {
      // Delay the API response
      vi.mocked(api.apiGet).mockImplementation(
        () => new Promise(() => {}) // Never resolves
      )

      renderPOSPage()

      // Should not render POS yet
      const posInterface = screen.queryByTestId('standard-pos')
      expect(posInterface).not.toBeInTheDocument()
    })
  })

  describe('Error State', () => {
    beforeEach(() => {
      vi.mocked(api.apiGet).mockRejectedValue(new Error('Failed to fetch config'))
    })

    it('handles config fetch error', async () => {
      renderPOSPage()

      // POSPage returns null on error, StandardPOS will handle error display
      // Wait for the error to be processed
      await new Promise((resolve) => setTimeout(resolve, 100))

      // Should not render POS interface on error
      const posInterface = screen.queryByTestId('standard-pos')
      expect(posInterface).not.toBeInTheDocument()
    })
  })

  describe('Config Integration', () => {
    it('passes vertical to StandardPOS component', async () => {
      const config = {
        vertical: 'retail',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product'],
        currency: 'USD',
        locale: 'en_US',
        country_code: 'US',
      }
      vi.mocked(api.apiGet).mockResolvedValue(config)

      renderPOSPage()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toHaveAttribute('data-vertical', 'retail')
    })

    it('uses company config context for vertical detection', async () => {
      const config = {
        vertical: 'pharmacy',
        default_modules: ['Identity', 'Sales', 'Inventory', 'Product', 'BatchExpiry'],
        enabled_extras: [],
        all_enabled_modules: ['Identity', 'Sales', 'Inventory', 'Product', 'BatchExpiry'],
        currency: 'TND',
        locale: 'fr_TN',
        country_code: 'TN',
      }
      vi.mocked(api.apiGet).mockResolvedValue(config)

      renderPOSPage()

      const posInterface = await screen.findByTestId('standard-pos')
      expect(posInterface).toHaveAttribute('data-vertical', 'pharmacy')
    })
  })
})
