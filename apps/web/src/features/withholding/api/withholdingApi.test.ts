import { describe, expect, it, vi } from 'vitest'

/**
 * P1 (docs/superpowers/tickets/2026-08-03-w5a-withholding-defects.md #4,
 * §178-220): `fetchWithholdingCertificates()` declared a `{data, meta,
 * links}` return type but implemented it with `apiGet(url)` — and `apiGet`
 * ALREADY unwraps `response.data.data` (lib/api.ts), so the promise resolved
 * to the certificate ARRAY, not the envelope its type claimed.
 * `WithholdingCertificatesList` then read `data?.data ?? []`, and
 * `array.data` is always `undefined` -> the list page rendered its empty
 * state regardless of how many certificates existed.
 *
 * The endpoint is genuinely cursor-paginated (`meta` + `links`), so the fix
 * (per docs/conventions/01-API-RESPONSES.md) is to call `api.get` directly
 * and return `response.data` — the paginated payload IS `response.data`, not
 * nested under `response.data.data`. `apiGet` and `api.get` are mocked with
 * DELIBERATELY DIFFERENT, distinguishable payloads below so the assertions
 * pin exactly which of the two code paths `fetchWithholdingCertificates`
 * takes, rather than merely whether *a* network call resolves.
 */

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiGetMethod = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    apiGet: mockApiGet,
    api: {
      ...actual.api,
      get: mockApiGetMethod,
    },
  }
})

const CERT_ROW = {
  id: 'c1',
  certificate_number: 'WHT-2026-0001',
  gross_amount: '1000.000',
  withholding_amount: '15.000',
}
const ENVELOPE = {
  data: [CERT_ROW],
  meta: { per_page: 20, has_more: false },
  links: { next: null, prev: null },
}

describe('fetchWithholdingCertificates', () => {
  it('resolves to the full {data, meta, links} envelope via api.get, NOT the double-unwrapped array apiGet would produce', async () => {
    // apiGet's real behaviour is to already return `response.data.data` —
    // simulated here as the bare certificate array (the pre-fix defect).
    mockApiGet.mockResolvedValue([CERT_ROW])
    // api.get is the raw axios call; its response.data IS the envelope.
    mockApiGetMethod.mockResolvedValue({ data: ENVELOPE })

    const { fetchWithholdingCertificates } = await import('./withholdingApi')
    const result = await fetchWithholdingCertificates()

    // The regression this pins: if the implementation still calls
    // `apiGet(url)`, `result` would be the bare array (`mockApiGet`'s
    // resolved value) and none of these would hold.
    expect(mockApiGetMethod, 'must call api.get directly, not apiGet').toHaveBeenCalled()
    expect(mockApiGet, 'must NOT call the double-unwrapping apiGet helper').not.toHaveBeenCalled()
    expect(result).toEqual(ENVELOPE)
    expect(result.data).toHaveLength(1)
    expect(result.data.at(0)?.certificate_number).toBe('WHT-2026-0001')
    expect(result.meta).toEqual({ per_page: 20, has_more: false })
    expect(result.links).toEqual({ next: null, prev: null })
  })

  it('forwards filters as query params on the request URL', async () => {
    mockApiGet.mockResolvedValue([])
    mockApiGetMethod.mockResolvedValue({ data: ENVELOPE })

    const { fetchWithholdingCertificates } = await import('./withholdingApi')
    await fetchWithholdingCertificates({ direction: 'purchase', year: 2026 })

    expect(mockApiGetMethod).toHaveBeenCalledWith(
      expect.stringContaining('direction=purchase'),
    )
    expect(mockApiGetMethod).toHaveBeenCalledWith(
      expect.stringContaining('year=2026'),
    )
  })
})
