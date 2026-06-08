import { describe, it, expect, vi, beforeEach } from 'vitest'

// Spy on the shared api layer so we can assert the exact wire payload
// updateUnitPrecision sends. The backend RoundingMethod enum is snake_case
// backed; sending the PascalCase UI value would 422 on every save.
vi.mock('../../../lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiPut: vi.fn().mockResolvedValue({}),
  apiDelete: vi.fn(),
}))

import { apiPut } from '../../../lib/api'
import { updateUnitPrecision } from './uomApi'

describe('updateUnitPrecision wire mapping', () => {
  beforeEach(() => {
    vi.mocked(apiPut).mockClear()
  })

  it.each([
    ['HalfUp', 'half_up'],
    ['Floor', 'floor'],
    ['Ceil', 'ceil'],
  ] as const)('maps UI %s to snake_case %s on the wire', async (ui, wire) => {
    await updateUnitPrecision('unit-1', { decimal_places: 3, rounding_method: ui })

    expect(apiPut).toHaveBeenCalledWith('/uom/units/unit-1', {
      decimal_places: 3,
      rounding_method: wire,
    })
  })
})
