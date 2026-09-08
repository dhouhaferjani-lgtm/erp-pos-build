import { describe, it, expect, vi, beforeEach } from 'vitest'
import { countingApi } from '../countingApi'
import type { DiscrepancyReport } from '../../types'

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
  },
  apiGet: vi.fn(),
  apiPatch: vi.fn(),
  apiPost: vi.fn(),
}))

import { api, apiGet, apiPost } from '@/lib/api'

const mockApi = api as unknown as { get: ReturnType<typeof vi.fn> }
const mockApiGet = vi.mocked(apiGet)
const mockApiPost = vi.mocked(apiPost)

describe('countingApi.finalize', () => {
  it('sends the explicit terminal-sync-risk acknowledgement', async () => {
    mockApiPost.mockResolvedValue(undefined)

    await countingApi.finalize('counting-1', true, 'health-signature')

    expect(mockApiPost).toHaveBeenCalledWith('/inventory/countings/counting-1/finalize', {
      acknowledge_terminal_sync_risk: true,
      terminal_sync_health_signature: 'health-signature',
    })
  })
})

describe('countingApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('returns the paginated envelope {data, meta} — not the bare array', async () => {
    // Real index response shape: {data: [...], meta: {...}} at the top level,
    // so axios wraps it once: response.data = {data, meta}.
    mockApi.get.mockResolvedValue({
      data: {
        data: [{ id: '019f3c39-6d21-7351-a2d9-66e8b00522b7', status: 'finalized' }],
        meta: { current_page: 1, last_page: 3, per_page: 15, total: 41 },
      },
    })

    const result = await countingApi.list({})

    expect(result.data).toHaveLength(1)
    expect(result.data[0].id).toBe('019f3c39-6d21-7351-a2d9-66e8b00522b7')
    expect(result.meta.total).toBe(41)
    expect(result.meta.last_page).toBe(3)
  })

  function requestedUrl(): string {
    const call = mockApi.get.mock.calls[0]
    return call[0] as string
  }

  it('sends an exact status verbatim as ?status=', async () => {
    mockApi.get.mockResolvedValue({ data: { data: [], meta: {} } })
    await countingApi.list({ status: 'finalized' })
    expect(requestedUrl()).toBe('/inventory/countings?status=finalized')
  })

  it('maps the "active" alias to ?status=active (backend resolves it to scopeActive)', async () => {
    mockApi.get.mockResolvedValue({ data: { data: [], meta: {} } })
    await countingApi.list({ status: 'active' })
    expect(requestedUrl()).toBe('/inventory/countings?status=active')
  })

  it('maps the "overdue" filter to ?overdue=true (never a bogus ?status=overdue)', async () => {
    mockApi.get.mockResolvedValue({ data: { data: [], meta: {} } })
    await countingApi.list({ status: 'overdue' })
    const url = requestedUrl()
    expect(url).toContain('overdue=true')
    expect(url).not.toContain('status=overdue')
  })

  it('omits the status param entirely for "all"', async () => {
    mockApi.get.mockResolvedValue({ data: { data: [], meta: {} } })
    await countingApi.list({ status: 'all' })
    expect(requestedUrl()).toBe('/inventory/countings')
  })
})

describe('countingApi.getReport', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('returns the real report contract with money values as strings', async () => {
    const report = {
      report_id: '0f8fad5b-d9cb-469f-a165-70867728950e',
      generated_at: '2026-07-08T10:00:00+00:00',
      generated_by: { id: 'user-1', name: 'Report Admin' },
      counting: {
        id: 'counting-1',
        company_id: 1,
        scope_type: 'location',
        scope_filters: { location_id: 'location-1' },
        execution_mode: 'parallel',
        status: 'finalized',
        scheduled_start: null,
        scheduled_end: null,
        requires_count_2: false,
        requires_count_3: false,
        allow_unexpected_items: true,
        instructions: null,
        // Counting mode — always present on the report/show/index payloads
        // (InventoryCountingController::transformCounting).
        block_sales: true,
        ambiguity_window_minutes: 15,
        created_on_mobile: false,
        title: null,
        last_modified_at: null,
        last_modified_by: null,
        count_1_user: { id: 2, name: 'Counter One', email: 'counter@example.com' },
        count_2_user: null,
        count_3_user: null,
        created_by: { id: 1, name: 'Report Admin', email: 'admin@example.com' },
        progress: {
          count_1: { counted: 2, total: 2, percentage: 100 },
          count_2: null,
          count_3: null,
          overall: 100,
        },
        created_at: '2026-07-08T09:00:00+00:00',
        updated_at: '2026-07-08T10:00:00+00:00',
        activated_at: '2026-07-08T09:05:00+00:00',
        finalized_at: '2026-07-08T09:55:00+00:00',
        cancelled_at: null,
        cancellation_reason: null,
      },
      summary: {
        total_items_counted: 2,
        items_no_variance: 1,
        items_with_variance: 1,
        variance_breakdown: {
          auto_all_match: 1,
          auto_counters_agree: 1,
          third_count_decisive: 0,
          manual_override: 0,
        },
        total_variance_value: {
          positive: '5.000',
          negative: '0.000',
          net: '5.000',
          currency: 'TND',
        },
        late_sales_corrections: 1,
        opening_items: 1,
        opening_value: '12.000',
        // W4-6: the three partition total_items_counted; applied/not-applied
        // count VARYING lines only.
        items_agreeing: 1,
        items_applied: 1,
        items_not_applied: 0,
      },
      items: [
        {
          id: 'item-1',
          product: { id: 1, name: 'Bandage', sku: 'BAND', barcode: null, image_url: null, quantity_decimals: 4 },
          variant: null,
          location: { id: 1, code: 'WH-1', name: 'Warehouse' },
          warehouse: { id: 1, name: 'Warehouse' },
          theoretical_qty: '10.0000',
          count_1: { qty: '12.0000', at: '2026-07-08T09:30:00+00:00', notes: null },
          count_2: null,
          count_3: null,
          final_qty: '12.0000',
          variance: 2,
          variance_percentage: null,
          resolution_method: 'auto_counters_agree',
          resolution_notes: null,
          is_flagged: true,
          flag_reason: 'variance_from_theoretical',
          expected_qty_at_apply: '11.0000',
          replay_audit: {
            windowFrom: '2026-07-08T09:30:00+00:00',
            windowTo: '2026-07-08T10:00:00+00:00',
            replayedDelta: '-1.0000',
            onHandAtApply: '9.0000',
            expectedAtApply: '11.0000',
          },
          replay_preview: null,
          flag_reasons: ['normalized_agreement'],
          opening_unit_cost: '4.000000',
          will_post_as_opening: true,
          opening_cost_missing: false,
          // W4-6 report columns: expected is on-hand AS OF the count instant
          // (onHandAtApply − replayedDelta = 9 − (−1) = 10), counted 12,
          // variance +2, and it reached stock.
          expected_qty: '10.0000',
          counted_qty: '12.0000',
          variance_qty: '2.0000',
          variance_applied: true,
          not_applied_reason: null,
        },
      ],
      flagged_items: [],
      counter_performance: [
        {
          user: { id: 'user-2', name: 'Counter One' },
          items_counted: 2,
          matched_other_counter: 0,
          matched_theoretical: 1,
          times_proven_wrong_by_3rd: 0,
          accuracy_rate: 50,
        },
      ],
      late_sync_residuals: [],
    } satisfies DiscrepancyReport

    mockApiGet.mockResolvedValue(report)

    const result = await countingApi.getReport('counting-1')

    expect(mockApiGet).toHaveBeenCalledWith('/inventory/countings/counting-1/report')
    expect(result.summary.total_variance_value.positive).toBe('5.000')
    expect(result.summary.total_variance_value.net).toBe('5.000')
    expect(result.summary.late_sales_corrections).toBe(1)
    expect(result.summary.opening_value).toBe('12.000')
  })
})
