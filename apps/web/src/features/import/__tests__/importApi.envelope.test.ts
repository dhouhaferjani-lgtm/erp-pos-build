import { describe, it, expect, beforeEach, vi } from 'vitest'

// ─── transport boundary mock ─────────────────────────────────────────────────

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockHelperGet = vi.hoisted(() => vi.fn())
const mockHelperPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  api: {
    get: mockApiGet,
    post: mockApiPost,
    delete: vi.fn(),
  },
  apiGet: mockHelperGet,
  apiPost: mockHelperPost,
  authenticatedDownload: vi.fn(),
}))

import { importApi } from '../api/importApi'

describe('importApi envelope handling', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('list() preserves the paginated {data, meta} envelope', async () => {
    // Backend returns { data: [...jobs], meta: {...} } — a single envelope.
    // apiGet would unwrap response.data.data and drop meta, so list() must
    // use the raw client and return response.data intact.
    mockApiGet.mockResolvedValue({
      data: {
        data: [{ id: 'job-1', status: 'completed' }],
        meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
      },
    })

    const result = await importApi.list()

    expect(Array.isArray(result.data)).toBe(true)
    expect(result.data[0]?.id).toBe('job-1')
    expect(result.meta.total).toBe(1)
  })

  it('getErrors() preserves the paginated {data, meta} envelope', async () => {
    mockApiGet.mockResolvedValue({
      data: {
        data: [{ row_number: 1, data: {}, errors: { name: ['required'] }, error_type: 'validation' }],
        meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      },
    })

    const result = await importApi.getErrors('job-1')

    expect(Array.isArray(result.data)).toBe(true)
    expect(result.data[0]?.row_number).toBe(1)
    expect(result.meta?.total).toBe(1)
  })

  it('executeImport() keeps the top-level import_result alongside the job', async () => {
    // The execute response nests the job under data but puts import_result
    // as a SIBLING of data — plain apiPost unwrapping drops it, leaving the
    // completion screen with stale zeros.
    mockApiPost.mockResolvedValue({
      data: {
        data: { id: 'job-1', status: 'completed', successful_rows: 2, failed_rows: 0 },
        import_result: {
          imported_count: 2,
          skipped_count: 0,
          execution_error_count: 0,
          total_rows: 2,
          failed_rows_csv_url: null,
        },
        message: '2 rows imported successfully.',
      },
    })

    const result = await importApi.executeImport('job-1')

    expect(result.status).toBe('completed')
    expect(result.import_result?.imported_count).toBe(2)
  })

  it('parseHeaders() posts the file as multipart and returns headers', async () => {
    mockApiPost.mockResolvedValue({
      data: { data: { headers: ['name', 'sku', 'sale_price'], row_count: 42 } },
    })

    const file = new File(['name;sku;sale_price\n'], 'produits.csv', { type: 'text/csv' })
    const result = await importApi.parseHeaders(file)

    expect(result.headers).toEqual(['name', 'sku', 'sale_price'])
    expect(result.row_count).toBe(42)

    const [url, body] = mockApiPost.mock.calls[0] as [string, FormData]
    expect(url).toBe('/migration-wizard/parse-headers')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('file')).toBe(file)
  })
})
