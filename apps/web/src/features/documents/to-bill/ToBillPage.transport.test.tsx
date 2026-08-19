/**
 * Transport-seam regression for the To-bill queue (M3 bridge round 1, findings 1 and 7).
 *
 * Every other To-bill test mocks a layer above the wire: the page test mocks the
 * hooks, the tenant-scope test mocks `getToBillQueue`, the API test mocks
 * `api`/`apiGet`. That is exactly why a real bug shipped 74/74 green — the queue
 * endpoints return a TOP-LEVEL `{data, meta, summary, scope}` body which `apiGet`
 * (`response.data.data`) silently reduces to the group array, so the page threw a
 * TypeError on `summary.buckets` on its very first successful load.
 *
 * This test therefore mocks NOTHING between the page and the wire: the real
 * `api` axios instance is used, with only its ADAPTER replaced so a verbatim
 * server body (copied from the backend's own assertions in
 * `apps/api/tests/Feature/Document/DeliveryNoteToBillQueueTest.php`) travels
 * through the real interceptors, the real api module, the real query hooks and
 * into the real page.
 */
import type { AxiosAdapter, AxiosRequestConfig } from 'axios'
import { screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { api } from '@/lib/api'
import { useLocationStore, type Location } from '@/stores/locationStore'
import { resetAuth, seedAuth } from '@/test/seedAuth'
import { renderWithProviders } from '@/test/renderWithProviders'
import { ToBillPage } from './ToBillPage'

/** Verbatim shape of `GET /api/v1/delivery-notes/uninvoiced` — top level, not enveloped. */
const serverBody = {
  data: [
    {
      partner_id: 'partner-old',
      partner_name: 'Atlas Periodic',
      partner_code: 'ATLAS',
      delivery_note_count: 2,
      total: '150.000',
      currency: 'TND',
      oldest_document_date: '2026-05-01',
      aging_bucket: '90_plus',
      is_periodic: true,
    },
  ],
  meta: { current_page: 1, last_page: 2, total: 2, per_page: 1 },
  scope: { location_id: 'location-1', can_view_all_locations: false },
  summary: {
    buckets: [
      { bucket: '0_30', count: 0, total: '0.000' },
      { bucket: '31_60', count: 0, total: '0.000' },
      { bucket: '61_90', count: 0, total: '0.000' },
      { bucket: '90_plus', count: 1, total: '150.000' },
    ],
    grand_total: '150.000',
    grand_count: 1,
    currency: 'TND',
  },
}

const location: Location = {
  id: 'location-1',
  companyId: 'company-1',
  name: 'Tunis',
  code: 'TUN',
  type: 'shop',
  phone: null,
  email: null,
  addressStreet: null,
  addressCity: null,
  addressPostalCode: null,
  addressCountry: null,
  isDefault: true,
  isActive: true,
  posEnabled: false,
  createdAt: '2026-08-01T00:00:00Z',
  updatedAt: '2026-08-01T00:00:00Z',
}

const originalAdapter = api.defaults.adapter
const requestedUrls: string[] = []

beforeEach(() => {
  requestedUrls.length = 0
  seedAuth({ tenantId: 'tenant-1', companyId: 'company-1' })
  useLocationStore.setState({
    locations: [location],
    currentLocationId: 'location-1',
    isLoading: false,
  })

  const adapter: AxiosAdapter = vi.fn(async (config: AxiosRequestConfig) => {
    requestedUrls.push(config.url ?? '')

    return {
      data: serverBody,
      status: 200,
      statusText: 'OK',
      headers: {},
      config,
    }
  }) as unknown as AxiosAdapter
  api.defaults.adapter = adapter
})

afterEach(() => {
  api.defaults.adapter = originalAdapter
  resetAuth()
  useLocationStore.getState().reset()
})

describe('ToBillPage over the real transport seam', () => {
  it('renders a verbatim server body without losing meta or summary', async () => {
    renderWithProviders(<ToBillPage />)

    // From `data` — proves the group array survived.
    expect(await screen.findByRole('article', { name: /Atlas Periodic/ })).toBeInTheDocument()

    // From `summary` — the exact field whose loss threw a TypeError on every load.
    await waitFor(() => {
      expect(screen.getAllByText('1 customer group').length).toBeGreaterThan(0)
    })

    // From `meta` — pagination only renders when last_page survived the transport.
    expect(screen.getByRole('button', { name: 'Next' })).toBeInTheDocument()

    expect(requestedUrls).toContain('/delivery-notes/uninvoiced')
  })
})
