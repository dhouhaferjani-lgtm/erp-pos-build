import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { ProductPlacementFields } from '../components/ProductPlacementFields'
import {
  listLocationNodes,
  listProductPlacements,
  setProductPlacement,
  type LocationNode,
} from '../api'
import { fetchLocations } from '@/features/locations/api'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: { name?: string }) => options?.name === undefined ? key : `${key}:${options.name}`,
  }),
}))

vi.mock('@/features/locations/api', () => ({ fetchLocations: vi.fn() }))
vi.mock('../api', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api')>()
  return {
    ...actual,
    listLocationNodes: vi.fn(),
    listProductPlacements: vi.fn(),
    setProductPlacement: vi.fn(),
  }
})

const nodes: LocationNode[] = [
  {
    id: 'node-a1', location_id: 'loc-1', parent_id: null, node_type: 'aisle',
    name: 'Aisle 1', code: 'A1', path: 'A1', depth: 0, sort_order: 10,
    is_active: true, product_count: 1, deleted_at: null,
    created_at: '2026-07-13T00:00:00Z', updated_at: '2026-07-13T00:00:00Z',
  },
  {
    id: 'node-b7', location_id: 'loc-1', parent_id: null, node_type: 'bin',
    name: 'Bin 7', code: 'B7', path: 'B7', depth: 0, sort_order: 20,
    is_active: true, product_count: 0, deleted_at: null,
    created_at: '2026-07-13T00:00:00Z', updated_at: '2026-07-13T00:00:00Z',
  },
]

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
  useAuthStore.setState({
    user: {
      id: 'user-1', name: 'User', email: 'user@example.test', tenant_id: 'tenant-1',
      roles: [], permissions: ['inventory.view', 'inventory.adjust'], email_verified_at: null,
    },
    token: 'token', isAuthenticated: true, isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
  vi.mocked(fetchLocations).mockResolvedValue([
    {
      id: 'loc-1', company_id: 'company-1', name: 'Main warehouse', code: 'MAIN',
      type: 'warehouse', phone: null, email: null, address_street: null,
      address_city: null, address_postal_code: null, address_country: null,
      tax_id: null, vat_number: null, legal_identifiers: null, is_default: true,
      is_active: true, pos_enabled: true, onboarding_mode: false,
      pos_stock_policy_override: null, created_at: '2026-07-13T00:00:00Z',
      updated_at: '2026-07-13T00:00:00Z',
    },
  ])
  vi.mocked(listProductPlacements).mockResolvedValue([
    {
      id: 'placement-1', product_id: 'product-1', product_name: 'Oil filter',
      product_sku: 'SKU-1', location_id: 'loc-1', node_id: 'node-a1',
      deleted_at: null, created_at: '2026-07-13T00:00:00Z', updated_at: '2026-07-13T00:00:00Z',
    },
  ])
  vi.mocked(listLocationNodes).mockResolvedValue(nodes)
  vi.mocked(setProductPlacement).mockResolvedValue(null)
})

describe('ProductPlacementFields', () => {
  it('shows the current path and sets or clears one placement per location', async () => {
    const user = userEvent.setup()
    render(<ProductPlacementFields productId="product-1" canEdit />, { wrapper: wrapper() })

    expect(await screen.findByText('A1')).toBeInTheDocument()
    expect(screen.getByText('Main warehouse')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'placement.productField.change:Main warehouse' }))
    await user.click(await screen.findByRole('button', { name: 'Bin 7' }))

    await waitFor(() => {
      expect(setProductPlacement).toHaveBeenCalledWith('product-1', 'loc-1', 'node-b7')
    })

    await user.click(screen.getByRole('button', { name: 'placement.productField.clear:Main warehouse' }))
    await waitFor(() => {
      expect(setProductPlacement).toHaveBeenCalledWith('product-1', 'loc-1', null)
    })
  })

  it('keeps current paths visible but hides write controls without adjust permission', async () => {
    render(<ProductPlacementFields productId="product-1" canEdit={false} />, { wrapper: wrapper() })

    expect(await screen.findByText('A1')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /placement\.productField\.(change|clear)/ })).not.toBeInTheDocument()
  })
})
