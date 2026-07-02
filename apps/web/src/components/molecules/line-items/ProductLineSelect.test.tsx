import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { ProductLineSelect } from './ProductLineSelect'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockApiGet },
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

function setTenant(tenantId: string, companyId: string) {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: tenantId,
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: companyId, companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: Infinity } },
  })
}

function wrapper(queryClient: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/products/product-1') {
      return {
        data: {
          data: {
            id: 'product-1',
            name: 'Serum Retinol',
            sku: 'SKU-RET',
            barcode: '619100000001',
            primary_image_url: '/retinol.png',
          },
        },
      }
    }
    if (url.startsWith('/products')) {
      return {
        data: {
          data: [
            {
              id: 'product-2',
              name: 'Vitamin C',
              sku: 'SKU-VITC',
              barcode: '619100000002',
              primary_image_url: null,
            },
          ],
        },
      }
    }
    return { data: { data: [] } }
  })
})

afterEach(() => {
  resetTenant()
})

describe('ProductLineSelect', () => {
  it('scopes selected-product reads and renders ProductCell identity fields', async () => {
    const queryClient = createClient()
    render(<ProductLineSelect value="product-1" onChange={vi.fn()} />, { wrapper: wrapper(queryClient) })

    await waitFor(() => {
      expect(queryClient.getQueryData(['line-entry-product-select', 'product-1', 'tenant-A', 'company-1'])).toEqual({
        id: 'product-1',
        name: 'Serum Retinol',
        sku: 'SKU-RET',
        barcode: '619100000001',
        primary_image_url: '/retinol.png',
      })
    })

    expect(screen.getByText('Serum Retinol')).toBeInTheDocument()
    expect(screen.getByText('SKU-RET')).toBeInTheDocument()
    expect(screen.getByText('619100000001')).toBeInTheDocument()
  })

  it('searches with a tenant-scoped key and selects a product by id', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    const queryClient = createClient()

    render(<ProductLineSelect value="" onChange={onChange} placeholder="Choose product" />, { wrapper: wrapper(queryClient) })

    await user.click(screen.getByRole('button', { name: 'Choose product' }))
    await user.type(screen.getByRole('combobox', { name: 'Choose product' }), 'vit')

    await waitFor(() => {
      expect(queryClient.getQueryData(['line-entry-product-select-search', 'vit', 'tenant-A', 'company-1'])).toEqual({
        data: [
          {
            id: 'product-2',
            name: 'Vitamin C',
            sku: 'SKU-VITC',
            barcode: '619100000002',
            primary_image_url: null,
          },
        ],
      })
    })
    await user.click(screen.getByRole('option', { name: /SKU-VITC Vitamin C/ }))

    expect(onChange).toHaveBeenCalledWith('product-2')
  })
})
