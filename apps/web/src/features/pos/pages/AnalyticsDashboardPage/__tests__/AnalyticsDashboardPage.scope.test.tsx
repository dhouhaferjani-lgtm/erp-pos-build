import { render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { AnalyticsDashboardPage } from '../AnalyticsDashboardPage'

const mocks = vi.hoisted(() => ({ useSalesSummary: vi.fn(() => ({ data: undefined })), useSalesByCategory: vi.fn(() => ({ data: undefined })), useSalesByProduct: vi.fn(() => ({ data: undefined })), useSalesByPeriod: vi.fn(() => ({ data: [] })), useCashierPerformance: vi.fn(() => ({ data: undefined })), useDiscountAnalysis: vi.fn(() => ({ data: undefined })), useCustomerAnalytics: vi.fn(() => ({ data: undefined })), useFnbMetrics: vi.fn(() => ({ data: undefined })) }))

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }))
vi.mock('@/contexts', () => ({ useCompanyConfig: () => ({ config: { vertical: 'retail' } }) }))
vi.mock('@/features/locations/hooks/useViewScope', () => ({ useViewScope: () => ({ scope: ['l1'], effectiveLocationIds: ['l1'], isAll: false, setScope: vi.fn() }) }))
vi.mock('../../../hooks/useAnalytics', () => mocks)

describe('AnalyticsDashboardPage scope wiring', () => {
  it('passes effective location ids to every analytics query', () => {
    render(<AnalyticsDashboardPage />)
    expect(mocks.useSalesSummary).toHaveBeenCalledWith(expect.objectContaining({ location_ids: ['l1'] }))
    expect(mocks.useSalesByProduct).toHaveBeenCalledWith(expect.objectContaining({ location_ids: ['l1'] }))
    expect(mocks.useFnbMetrics).toHaveBeenCalledWith(expect.objectContaining({ location_ids: ['l1'] }))
  })
})
