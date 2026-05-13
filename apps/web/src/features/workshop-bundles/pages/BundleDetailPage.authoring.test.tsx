import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { screen, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { seedAuth, resetAuth } from '@/test/seedAuth'
import { BundleDetailPage } from './BundleDetailPage'
import type { ServiceBundleData } from '../types'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockApiPatch = vi.hoisted(() => vi.fn())
const mockApiDelete = vi.hoisted(() => vi.fn())
const mockApiPut = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: mockApiPost,
      patch: mockApiPatch,
      delete: mockApiDelete,
      put: mockApiPut,
    },
    apiGet: vi.fn().mockImplementation(async (url: string) => {
      if (url === '/uom/units') {
        return [{ id: 'unit-each', code: 'EA', name: 'Each', symbol: 'EA' }]
      }
      return []
    }),
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: 'bundle-1' }),
  }
})

function buildBundle(): ServiceBundleData {
  return {
    id: 'bundle-1',
    tenant_id: 't1',
    company_id: 'c1',
    code: 'TEST',
    name: 'Test bundle',
    description: null,
    pricing_mode: 'standard',
    base_price: null,
    currency: 'TND',
    tax_rate: '19.000',
    estimated_labor_hours: null,
    service_interval_km: null,
    service_interval_months: null,
    is_active: true,
    components: [
      {
        id: 'comp-1',
        bundle_id: 'bundle-1',
        component_type: 'part',
        component_id: 'prod-1',
        component_display_name: 'Oil filter',
        quantity: '1.000',
        unit: 'EA',
        override_unit_price: null,
        is_optional: false,
        display_order: 0,
        notes: null,
      },
    ],
    vehicle_applicabilities: [],
    created_at: '2026-04-20T00:00:00Z',
    updated_at: null,
  }
}

describe('BundleDetailPage — authoring integration', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiPatch.mockReset()
    mockApiDelete.mockReset()
    mockApiPut.mockReset()
    seedAuth()

    mockApiGet.mockImplementation((url: string) => {
      if (url.startsWith('/workshop/bundles/bundle-1/expansion')) {
        return Promise.resolve({ data: { data: [] } })
      }
      if (url === '/workshop/bundles/bundle-1') {
        return Promise.resolve({ data: { data: buildBundle() } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
  })

  afterEach(() => {
    resetAuth()
  })

  it('opens the add-component modal when the add button is clicked', async () => {
    const user = userEvent.setup()
    renderWithProviders(<BundleDetailPage />)

    await waitFor(() => {
      expect(screen.getByText(/Oil filter/i)).toBeInTheDocument()
    })

    await user.click(screen.getByTestId('bundle-add-component-btn'))
    expect(screen.getByTestId('bundle-component-form-modal')).toBeInTheDocument()
  })

  it('opens the edit modal pre-filled when edit is clicked', async () => {
    const user = userEvent.setup()
    renderWithProviders(<BundleDetailPage />)

    await waitFor(() => {
      expect(screen.getByTestId('bundle-component-edit-comp-1')).toBeInTheDocument()
    })

    await user.click(screen.getByTestId('bundle-component-edit-comp-1'))
    expect(screen.getByText(/Edit component/i)).toBeInTheDocument()
  })

  it('confirms delete and DELETEs the component', async () => {
    mockApiDelete.mockResolvedValue({ data: null })
    const user = userEvent.setup()
    renderWithProviders(<BundleDetailPage />)

    await waitFor(() => {
      expect(screen.getByTestId('bundle-component-delete-comp-1')).toBeInTheDocument()
    })

    await user.click(screen.getByTestId('bundle-component-delete-comp-1'))
    expect(screen.getByTestId('bundle-delete-confirm')).toBeInTheDocument()

    fireEvent.click(screen.getByTestId('bundle-delete-confirm-btn'))

    await waitFor(() => {
      expect(mockApiDelete).toHaveBeenCalled()
    })
    const [url] = mockApiDelete.mock.calls[0] as [string]
    expect(url).toContain('/workshop/bundles/bundle-1/components/comp-1')
  })
})
