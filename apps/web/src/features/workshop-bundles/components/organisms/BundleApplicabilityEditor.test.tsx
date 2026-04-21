import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { BundleApplicabilityEditor } from './BundleApplicabilityEditor'
import type { ServiceBundleData } from '../../types'

const mockApiPut = vi.hoisted(() => vi.fn())
const mockApiGet = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: { put: mockApiPut, get: mockApiGet },
  }
})

function buildBundle(overrides: Partial<ServiceBundleData> = {}): ServiceBundleData {
  return {
    id: 'bundle-1',
    tenant_id: 't1',
    company_id: 'c1',
    code: 'APPL-TEST',
    name: 'Applicability test',
    description: null,
    pricing_mode: 'standard',
    base_price: null,
    currency: 'TND',
    tax_rate: '19.000',
    estimated_labor_hours: null,
    service_interval_km: null,
    service_interval_months: null,
    is_active: true,
    components: [],
    vehicle_applicabilities: [],
    created_at: '2026-04-20T00:00:00Z',
    updated_at: null,
    ...overrides,
  }
}

describe('BundleApplicabilityEditor', () => {
  beforeEach(() => {
    mockApiPut.mockReset()
    mockApiPut.mockResolvedValue({ data: { data: [] } })
  })

  it('renders the empty state with an Add rule button', () => {
    renderWithProviders(<BundleApplicabilityEditor bundle={buildBundle()} />)
    expect(screen.getByTestId('bundle-applicability-add-btn')).toBeInTheDocument()
  })

  it('opens the add-rule modal and submits a PUT with the new rule appended', async () => {
    const user = userEvent.setup()
    renderWithProviders(<BundleApplicabilityEditor bundle={buildBundle()} />)

    await user.click(screen.getByTestId('bundle-applicability-add-btn'))
    await user.selectOptions(screen.getByTestId('bundle-applicability-vehicle-type'), 'pc')
    await user.type(screen.getByTestId('bundle-applicability-vehicle-display'), 'Peugeot 308')

    fireEvent.click(screen.getByTestId('bundle-applicability-save-btn'))

    await waitFor(() => {
      expect(mockApiPut).toHaveBeenCalled()
    })
    const [url, payload] = mockApiPut.mock.calls[0] as [
      string,
      { applicabilities: Array<Record<string, unknown>> },
    ]
    expect(url).toContain('/workshop/bundles/bundle-1/vehicle-applicabilities')
    expect(payload.applicabilities.length).toBe(1)
    const first = payload.applicabilities[0]
    expect(first.vehicle_type).toBe('pc')
    expect(first.vehicle_display).toBe('Peugeot 308')
    expect(first.platform_vehicle_id).toBeNull()
  })

  it('enforces the both-null-or-both-non-null invariant before calling the API', async () => {
    const user = userEvent.setup()
    renderWithProviders(<BundleApplicabilityEditor bundle={buildBundle()} />)

    await user.click(screen.getByTestId('bundle-applicability-add-btn'))
    // vehicle_type set but vehicle_display left empty — should be blocked.
    await user.selectOptions(screen.getByTestId('bundle-applicability-vehicle-type'), 'pc')

    fireEvent.click(screen.getByTestId('bundle-applicability-save-btn'))

    expect(
      screen.getByText(/Vehicle type and label must either both be empty/i),
    ).toBeInTheDocument()
    expect(mockApiPut).not.toHaveBeenCalled()
  })

  it('removes an existing rule by PUTting the list without it (replace semantics)', async () => {
    const bundle = buildBundle({
      vehicle_applicabilities: [
        {
          id: 'app-1',
          bundle_id: 'bundle-1',
          platform_vehicle_id: null,
          vehicle_type: 'pc',
          vehicle_display: 'Peugeot 308',
          year_from: 2015,
          year_to: 2020,
        },
        {
          id: 'app-2',
          bundle_id: 'bundle-1',
          platform_vehicle_id: null,
          vehicle_type: 'cv',
          vehicle_display: 'Renault Kangoo',
          year_from: null,
          year_to: null,
        },
      ],
    })
    renderWithProviders(<BundleApplicabilityEditor bundle={bundle} />)

    fireEvent.click(screen.getByTestId('bundle-applicability-remove-app-1'))

    await waitFor(() => {
      expect(mockApiPut).toHaveBeenCalled()
    })
    const [, payload] = mockApiPut.mock.calls[0] as [
      string,
      { applicabilities: Array<Record<string, unknown>> },
    ]
    expect(payload.applicabilities.length).toBe(1)
    expect(payload.applicabilities[0].vehicle_display).toBe('Renault Kangoo')
  })
})
