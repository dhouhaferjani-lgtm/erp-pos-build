import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { ZonesPanel } from '../ZonesPanel'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPatch: mockApiPatch,
    apiDelete: mockApiDelete,
  }
})

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const name = opts?.['name']
      if (typeof name === 'string') {
        return `${key}:${name}`
      }
      return key
    },
  }),
}))

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({ onChange }: { onChange: (value: { id: string; sku: string; name: string } | null) => void }) => (
    <button
      type="button"
      onClick={() => { onChange({ id: 'prod-1', sku: 'SKU1', name: 'Product One' }) }}
    >
      pick-product-one
    </button>
  ),
}))

function zone(overrides: Partial<Record<string, unknown>> = {}) {
  // LocationNodeDto shape (Phase-1 hierarchy backend): flat zones are
  // top-level nodes with node_type 'zone'.
  return {
    id: 'zone-1',
    location_id: 'loc-1',
    parent_id: null,
    node_type: 'zone',
    name: 'Aisle 1',
    code: 'A1',
    path: 'A1',
    depth: 0,
    sort_order: 0,
    is_active: true,
    deleted_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'current-user',
      name: 'Current User',
      email: 'current@example.test',
      tenant_id: 'tenant-A',
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function wrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant()
})

afterEach(() => {
  resetTenant()
})

describe('ZonesPanel', () => {
  it('renders the zones list for the given location', async () => {
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/inventory/locations/loc-1/nodes') {
        return [zone()]
      }
      return []
    })

    render(<ZonesPanel locationId="loc-1" />, { wrapper: wrapper() })

    await waitFor(() => {
      expect(screen.getByText('Aisle 1')).toBeInTheDocument()
    })
    expect(screen.getByText('A1')).toBeInTheDocument()
    expect(mockApiGet).toHaveBeenCalledWith('/inventory/locations/loc-1/nodes')
  })

  it('validates required name on create and does not submit', async () => {
    const user = userEvent.setup()
    mockApiGet.mockImplementation(async () => [])

    render(<ZonesPanel locationId="loc-1" />, { wrapper: wrapper() })

    await waitFor(() => {
      expect(screen.getAllByRole('button', { name: 'inventory:zones.addZone' })[0]).toBeInTheDocument()
    })

    await user.click(screen.getAllByRole('button', { name: 'inventory:zones.addZone' })[0])

    const saveButton = await screen.findByRole('button', { name: 'common:save' })
    await user.click(saveButton)

    await waitFor(() => {
      expect(screen.getByText('inventory:zones.form.nameRequired')).toBeInTheDocument()
    })
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('posts product_ids when bulk-assigning products to a zone', async () => {
    const user = userEvent.setup()
    mockApiGet.mockImplementation(async (url: string) => {
      if (url === '/inventory/locations/loc-1/nodes') {
        return [zone()]
      }
      if (url === '/inventory/nodes/zone-1/products') {
        return []
      }
      return []
    })
    mockApiPost.mockImplementation(async (url: string) => {
      if (url === '/inventory/nodes/zone-1/assign-products') {
        return [{ id: 'assign-1', product_id: 'prod-1', product_name: 'Product One', product_sku: 'SKU1', location_id: 'loc-1', node_id: 'zone-1', deleted_at: null, created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-01T00:00:00Z' }]
      }
      return {}
    })

    render(<ZonesPanel locationId="loc-1" />, { wrapper: wrapper() })

    await waitFor(() => {
      expect(screen.getByText('Aisle 1')).toBeInTheDocument()
    })

    await user.click(screen.getByRole('button', { name: 'inventory:zones.actions.assignProducts' }))

    await user.click(await screen.findByText('pick-product-one'))

    const submitButton = await screen.findByRole('button', { name: 'inventory:zones.bulkAssign.submit' })
    await user.click(submitButton)

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/inventory/nodes/zone-1/assign-products', {
        product_ids: ['prod-1'],
      })
    })
  })
})
