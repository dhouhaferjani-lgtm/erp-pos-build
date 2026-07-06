import { QueryClient } from '@tanstack/react-query'
import { screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '../../test/renderWithProviders'

import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

import { EntryExitNotesPage } from './EntryExitNotesPage'

const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
    },
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
      name: 'Test User',
      email: 'test@example.com',
      tenant_id: tenantId,
      roles: ['admin'],
      email_verified_at: null,
    },
    token: 'test-token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: companyId,
    companies: [],
    isLoading: false,
  })
}

function resetTenant() {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
}

function createPersistentQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity },
      mutations: { retry: false },
    },
  })
}

function cacheKeys(client: QueryClient): unknown[][] {
  return client
    .getQueryCache()
    .getAll()
    .map((q) => q.queryKey as unknown[])
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant('tenant-A', 'company-1')
  mockApiGet.mockResolvedValue({
    data: {
      data: [
        {
          id: 'note-1',
          direction: 'in',
          source_type: 'goods_receipt',
          source_id: 'gr-1',
          source_label: 'GRN-2026-0001',
          location: { id: 'loc-1', name: 'Main Warehouse' },
          actor: { id: 'user-1', name: 'Receiver' },
          timestamp: '2026-07-06T08:30:00Z',
          lines: [
            {
              movement_id: 'm-1',
              product: { id: 'product-1', name: 'Brake Pad' },
              quantity: '2.0000',
              quantity_before: '0.0000',
              quantity_after: '2.0000',
              movement_type: 'receipt',
              reason: 'goods_receipt',
            },
          ],
        },
      ],
      meta: { total: 1 },
    },
  })
})

afterEach(() => {
  resetTenant()
})

describe('EntryExitNotesPage', () => {
  it('uses a tenant-scoped query key', () => {
    const queryClient = createPersistentQueryClient()

    renderWithProviders(<EntryExitNotesPage />, { queryClient })

    expect(cacheKeys(queryClient)).toContainEqual(tenantScopedKey(['entry-exit-notes', 'all', 'all']))
  })

  it('renders grouped note rows from the API', async () => {
    renderWithProviders(<EntryExitNotesPage />)

    await waitFor(() => {
      expect(screen.getByText('GRN-2026-0001')).toBeInTheDocument()
    })
    expect(screen.getByText('Main Warehouse')).toBeInTheDocument()
    expect(screen.getByText('Brake Pad')).toBeInTheDocument()
    expect(screen.getByText('+2')).toBeInTheDocument()
  })
})
