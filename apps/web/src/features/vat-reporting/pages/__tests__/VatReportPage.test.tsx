import { screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { VatReportPage } from '../VatReportPage'
import type { VatPeriod, VatReportSummary } from '../../types'

const mockGetVatReportSummary = vi.hoisted(() => vi.fn())
const mockGetVatPeriod = vi.hoisted(() => vi.fn())
const mockGetVatExportFormats = vi.hoisted(() => vi.fn())

vi.mock('../../api', () => ({
  getVatReportSummary: mockGetVatReportSummary,
  getVatPeriod: mockGetVatPeriod,
  getVatExportFormats: mockGetVatExportFormats,
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')

  return { ...actual, useParams: () => ({ id: 'period-1' }) }
})

/**
 * The REAL payload of `GET /vat/reports/{periodId}/summary`, transcribed from
 * VatSummaryData::toArray() and the CLOSED/FILED snapshot branch of
 * VatReportController::periodSummary(), and pinned by
 * `apps/api/tests/Feature/Taxation/VatReportSummaryContractTest.php`.
 *
 * Note what is NOT here: a `period` key. The declaration header (label, status,
 * id) lives on `GET /vat/periods/{id}`, a different endpoint.
 */
const summaryFixture: VatReportSummary = {
  output_vat: {
    total_base: '38400.000',
    total_vat: '7296.000',
    breakdowns: [
      {
        direction: 'OUTPUT',
        tax_rate: '19.00',
        base_amount: '38400.000',
        vat_amount: '7296.000',
        document_count: 1,
        is_recoverable: false,
        tax_configuration_id: null,
      },
    ],
  },
  input_vat: { total_base: '0.000', total_vat: '0.000', breakdowns: [] },
  net_vat: '7296.000',
  credit_brought_forward: '0.000',
  credit_carried_forward: '0.000',
  amount_payable: '7296.000',
  // captured live 2026-08-24 from the campaign tenant: `declaration` carries
  // form_reference + fields, and NO country_code — the page used to read
  // `declaration['country_code']` and always got undefined
  special_items: {
    stamp_duty_count: 2,
    stamp_duty_total: '2.000',
    retenue_source_total: '0.000',
  },
  declaration: {
    form_reference: 'DGI',
    fields: { total_output_vat: '7296.000', total_deductible_vat: '0.000' },
  },
}

/** The REAL payload of `GET /vat/periods/{id}` (VatPeriodData::toArray()). */
const periodFixture: VatPeriod = {
  id: 'period-1',
  country_code: 'TN',
  label: 'Janvier 2026',
  period_type: 'MONTHLY',
  period_start: '2026-01-01',
  period_end: '2026-01-31',
  status: 'OPEN',
  total_output_vat: '7296.000',
  total_input_vat: '0.000',
  net_vat: '7296.000',
  credit_brought_forward: '0.000',
  credit_carried_forward: '0.000',
  amount_payable: '7296.000',
  closed_at: null,
  filed_at: null,
  filing_reference: null,
}

describe('VatReportPage', () => {
  beforeEach(() => {
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'User',
        email: 'u@example.test',
        tenant_id: 'tenant-1',
        roles: [],
        email_verified_at: null,
      },
      token: 'token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
    mockGetVatReportSummary.mockReset()
    mockGetVatReportSummary.mockResolvedValue(summaryFixture)
    mockGetVatPeriod.mockReset()
    mockGetVatPeriod.mockResolvedValue(periodFixture)
    mockGetVatExportFormats.mockReset()
    mockGetVatExportFormats.mockResolvedValue([])
  })

  // N-4: this render used to throw
  // "Cannot read properties of undefined (reading 'label')" and take the whole
  // declaration screen to the ErrorBoundary, because the summary payload has
  // never carried a `period` key.
  it('renders the period header from the period endpoint, not from the summary payload', async () => {
    renderWithProviders(<VatReportPage />, { route: '/finance/vat-reports/period-1' })

    expect(await screen.findByText('Janvier 2026')).toBeInTheDocument()
    await waitFor(() => {
      expect(mockGetVatPeriod).toHaveBeenCalledWith('period-1')
    })
  })

  it('renders the output VAT breakdown that the summary payload actually carries', async () => {
    renderWithProviders(<VatReportPage />, { route: '/finance/vat-reports/period-1' })

    expect(await screen.findByText('Janvier 2026')).toBeInTheDocument()
    expect(screen.getAllByText(/19/).length).toBeGreaterThan(0)
  })

  // The special-items panel keys on a country code the page took from
  // `report.declaration['country_code']` — a key the declaration payload has
  // never carried, so `countryItemConfigs['']` was undefined and the TN stamp
  // duty / retenue-a-la-source block never rendered for any tenant. The country
  // code lives on the period.
  it('renders the TN special items panel using the period country code', async () => {
    renderWithProviders(<VatReportPage />, { route: '/finance/vat-reports/period-1' })

    expect(await screen.findByText('Janvier 2026')).toBeInTheDocument()
    expect(screen.getByText('Special Items')).toBeInTheDocument()
    expect(screen.getByText('Timbre Fiscal Count')).toBeInTheDocument()
  })
})
