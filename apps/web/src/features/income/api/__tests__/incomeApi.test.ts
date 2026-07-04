// API-contract test for the income client (TD-013).
//
// Mirrors crm/api/__tests__/contactApi.test.ts: mocks the shared `@/lib/api`
// axios instance (NOT a hand-fed incomeApi), so it verifies the REAL unwrap
// behaviour. The list endpoint is paginated ({ data, meta }) and MUST use
// `api.get` returning `response.data` — NOT `apiGet`, which would drop `meta`
// AND double-unwrap the array, leaving the list page stuck with `data.data`
// undefined.
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { incomeApi } from '../incomeApi'

vi.mock('@/lib/api', () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  },
}))

import { api } from '@/lib/api'

const mockApi = api as unknown as {
  get: ReturnType<typeof vi.fn>
  post: ReturnType<typeof vi.fn>
  patch: ReturnType<typeof vi.fn>
  delete: ReturnType<typeof vi.fn>
}

describe('incomeApi.list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('returns the FULL paginated envelope ({ data, meta }) — not double-unwrapped', async () => {
    // Shape of a real IncomeResource::collection() paginated response.
    const envelope = {
      data: [
        { id: 'inc-1', type: 'income', status: 'posted', document_number: 'INC-1' },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
    }
    mockApi.get.mockResolvedValue({ data: envelope })

    const result = await incomeApi.list()

    expect(mockApi.get).toHaveBeenCalledWith('/income', { params: undefined })
    // The list page reads `data?.data` for rows and `data?.meta` for pagination;
    // both must survive. A double-unwrap would return the inner array instead.
    expect(result).toEqual(envelope)
    expect(result.data).toHaveLength(1)
    expect(result.meta?.total).toBe(1)
  })

  it('forwards filters as axios params', async () => {
    mockApi.get.mockResolvedValue({ data: { data: [], meta: {} } })

    await incomeApi.list({ status: 'posted', search: 'scrap', per_page: 50 })

    expect(mockApi.get).toHaveBeenCalledWith('/income', {
      params: { status: 'posted', search: 'scrap', per_page: 50 },
    })
  })
})

describe('incomeApi.get', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('unwraps the single-resource envelope (response.data.data)', async () => {
    const income = { id: 'inc-9', type: 'income', document_number: 'INC-9' }
    mockApi.get.mockResolvedValue({ data: { data: income } })

    const result = await incomeApi.get('inc-9')

    expect(mockApi.get).toHaveBeenCalledWith('/income/inc-9')
    expect(result).toEqual(income)
  })
})
