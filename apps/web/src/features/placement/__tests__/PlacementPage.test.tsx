import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { PlacementPage } from '../PlacementPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockRawGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { get: mockRawGet },
    apiGet: mockApiGet,
    apiPost: mockApiPost,
    apiPatch: mockApiPatch,
    apiDelete: mockApiDelete,
  }
})

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('@/components/molecules/pickers/ProductPicker', () => ({
  ProductPicker: ({ onChange }: { onChange: (value: { id: string; sku: string; name: string } | null) => void }) => (
    <button type="button" onClick={() => { onChange({ id: 'product-2', sku: 'SKU-2', name: 'Brake pad' }) }}>
      choose-product
    </button>
  ),
}))

const nodes = [
  {
    id: 'node-a1', location_id: 'loc-1', parent_id: null, node_type: 'aisle',
    name: 'Aisle 1', code: 'A1', path: 'A1', depth: 0, sort_order: 0,
    is_active: true, product_count: 2, deleted_at: null,
    created_at: '2026-07-13T00:00:00Z', updated_at: '2026-07-13T00:00:00Z',
  },
  {
    id: 'node-r2', location_id: 'loc-1', parent_id: 'node-a1', node_type: 'rack',
    name: 'Rack 2', code: 'R2', path: 'A1/R2', depth: 1, sort_order: 0,
    is_active: true, product_count: 1, deleted_at: null,
    created_at: '2026-07-13T00:00:00Z', updated_at: '2026-07-13T00:00:00Z',
  },
]

function wrapper(initialEntry = '/inventory/placement?location_id=loc-1') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <MemoryRouter initialEntries={[initialEntry]}>
        <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
      </MemoryRouter>
    )
  }
}

function setIdentity(permissions: string[]) {
  useAuthStore.setState({
    user: {
      id: 'user-1', name: 'User', email: 'user@example.test', tenant_id: 'tenant-1',
      roles: [], permissions, email_verified_at: null,
    },
    token: 'token', isAuthenticated: true, isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

beforeEach(() => {
  vi.clearAllMocks()
  setIdentity(['inventory.view', 'inventory.adjust'])
  mockApiGet.mockImplementation((url: string) => {
    if (url === '/locations') return Promise.resolve([{ id: 'loc-1', name: 'Main warehouse', code: 'MAIN' }])
    if (url === '/inventory/locations/loc-1/nodes') return Promise.resolve(nodes)
    return Promise.resolve([])
  })
  mockRawGet.mockResolvedValue({
    data: {
      data: [{
        id: 'placement-1', product_id: 'product-1', product_name: 'Oil filter',
        product_sku: 'SKU-1', location_id: 'loc-1', node_id: 'node-a1',
        deleted_at: null, created_at: '2026-07-13T00:00:00Z', updated_at: '2026-07-13T00:00:00Z',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    },
  })
  mockApiPost.mockResolvedValue({})
  mockApiPatch.mockResolvedValue(nodes[0])
  mockApiDelete.mockResolvedValue(undefined)
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('PlacementPage', () => {
  it('expands one tree source, shows product counts, and reuses detail in table drawer', async () => {
    const user = userEvent.setup()
    render(<PlacementPage />, { wrapper: wrapper() })

    expect(await screen.findByRole('heading', { name: 'placement.title' })).toBeInTheDocument()
    expect(await screen.findByText('Aisle 1')).toBeInTheDocument()
    expect(screen.getByLabelText('placement.productCount:2')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'placement.tree.expand' }))
    expect(screen.getByText('Rack 2')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'placement.views.table' }))
    const table = screen.getByRole('table', { name: 'placement.table.label' })
    await user.click(within(table).getByRole('button', { name: 'Aisle 1' }))
    expect(screen.getByRole('dialog', { name: 'placement.detail.title' })).toHaveTextContent('A1')
  })

  it('assigns, unassigns, and bulk-moves through Phase-1 endpoints', async () => {
    const user = userEvent.setup()
    render(<PlacementPage />, { wrapper: wrapper() })

    await user.click(await screen.findByRole('button', { name: 'Aisle 1' }))
    expect(await screen.findByText('Oil filter')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'choose-product' }))
    await user.click(screen.getByRole('button', { name: 'placement.products.assign' }))
    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/inventory/nodes/node-a1/assign-products', {
        product_ids: ['product-2'],
      })
    })

    await user.click(screen.getByRole('checkbox', { name: 'Oil filter' }))
    await user.selectOptions(screen.getByLabelText('placement.products.moveTarget'), 'node-r2')
    await user.click(screen.getByRole('button', { name: 'placement.products.bulkMove' }))
    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/inventory/placements/bulk-move', {
        node_id: 'node-r2', product_ids: ['product-1'],
      })
    })

    await user.click(screen.getByRole('button', { name: 'placement.products.unassign:Oil filter' }))
    expect(mockApiDelete).toHaveBeenCalledWith('/inventory/nodes/node-a1/products/product-1')
  })

  it('hides every write action without inventory.adjust while retaining view access', async () => {
    setIdentity(['inventory.view'])
    render(<PlacementPage />, { wrapper: wrapper() })

    await screen.findByText('Aisle 1')
    expect(screen.queryByRole('button', { name: 'placement.actions.addRoot' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Aisle 1' }))
    expect(screen.queryByRole('button', { name: 'placement.actions.edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'choose-product' })).not.toBeInTheDocument()
  })
})
