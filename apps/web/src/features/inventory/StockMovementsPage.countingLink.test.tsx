import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useViewScopeStore } from '../../stores/viewScopeStore'
import { resetAuth, seedAuth } from '../../test/seedAuth'
import {
  StockMovementsPage,
  type StockMovement,
  type StockMovementsResponse,
} from './StockMovementsPage'

/**
 * QA-BUG-09 / DEV-QA-077 — the movements list must link a count movement back to
 * the counting that produced it. Before the fix the row rendered `COUNT_REPLAY`
 * as inert text: the label was a constant and `source_document_type` was null
 * for every non-Document reference type.
 */

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, second?: unknown) => (typeof second === 'string' ? second : key),
  }),
}))

vi.mock('react-router-dom', () => ({
  useNavigate: () => vi.fn(),
  Link: ({ to, children, ...props }: { to: string; children: ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

vi.mock('../locations/hooks/useViewScope', async () => {
  const { useViewScopeStore: store } = await vi.importActual<
    typeof import('../../stores/viewScopeStore')
  >('../../stores/viewScopeStore')
  return {
    useViewScope: () => {
      const scope = store((state) => state.scope)
      return {
        scope,
        effectiveLocationIds: scope === 'all' ? [] : scope,
        isAll: scope === 'all',
        setScope: store.getState().setScope,
      }
    },
  }
})
vi.mock('../../hooks/useLocation', () => ({ useLocation: () => ({ currentLocationId: null }) }))
vi.mock('../locations/LocationSelector', () => ({
  LocationSelector: () => <div data-testid="location-selector" />,
}))
vi.mock('../../hooks/usePermissions', () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}))
vi.mock('../batches/api/batches', () => ({ reverseWriteOff: vi.fn() }))

const apiGet = vi.hoisted(() => vi.fn<(url: string) => Promise<MovementsBody>>())
vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    api: { ...actual.api, get: (url: string): Promise<MovementsBody> => apiGet(url) },
  }
})

interface MovementsBody {
  data: StockMovementsResponse
}

function makeMovement(overrides: Partial<StockMovement>): StockMovement {
  return {
    id: 'movement-0',
    product_id: 'product-0',
    product_name: 'Widget',
    location_id: 'location-1',
    location_name: 'Main',
    movement_type: 'adjustment',
    reason: 'count_correction',
    quantity: '-2.0000',
    quantity_decimals: 3,
    quantity_before: '70.0000',
    quantity_after: '68.0000',
    reference: 'CNT-2026-0010',
    reference_type: 'inventory_counting',
    reference_id: 'counting-1',
    source_document_id: 'counting-1',
    source_document_type: 'inventory_counting',
    notes: null,
    user_id: 'user-1',
    user_name: 'Alice',
    reverses_movement_id: null,
    is_reversed: false,
    created_at: '2026-09-01T10:00:00Z',
    ...overrides,
  }
}

function body(rows: StockMovement[]): MovementsBody {
  return {
    data: {
      data: rows,
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: rows.length,
        from: rows.length === 0 ? null : 1,
        to: rows.length === 0 ? null : rows.length,
      },
    },
  }
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return render(
    <QueryClientProvider client={client}>
      <StockMovementsPage />
    </QueryClientProvider>,
  )
}

describe('StockMovementsPage counting linkage', () => {
  beforeEach(() => {
    apiGet.mockReset()
    seedAuth({ tenantId: 'tenant-1', companyId: 'company-1' })
    useViewScopeStore.setState({ scope: ['location-1'] })
  })

  afterEach(() => {
    cleanup()
    resetAuth()
    useViewScopeStore.setState({ scope: 'all' })
  })

  it('links a count movement to its counting, labelled with the CNT number', async () => {
    apiGet.mockResolvedValue(body([makeMovement({})]))

    renderPage()

    const link = await screen.findByRole('link', { name: 'CNT-2026-0010' })
    expect(link).toHaveAttribute('href', '/inventory/counting/counting-1')
  })

  it('renders the reference as plain text when no source document is resolved', async () => {
    apiGet.mockResolvedValue(body([
      makeMovement({
        id: 'movement-unlinked',
        reference: 'COUNT_REPLAY',
        reference_id: null,
        source_document_id: null,
        source_document_type: null,
      }),
    ]))

    renderPage()

    expect(await screen.findByText('COUNT_REPLAY')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'COUNT_REPLAY' })).not.toBeInTheDocument()
  })

  it('still links a document-backed movement to its document route', async () => {
    apiGet.mockResolvedValue(body([
      makeMovement({
        id: 'movement-invoice',
        movement_type: 'issue',
        reason: null,
        reference: 'INV-2026-0042',
        reference_type: 'Document',
        reference_id: 'invoice-1',
        source_document_id: 'invoice-1',
        source_document_type: 'invoice',
      }),
    ]))

    renderPage()

    const link = await screen.findByRole('link', { name: 'INV-2026-0042' })
    expect(link).toHaveAttribute('href', '/sales/invoices/invoice-1')
  })
})
