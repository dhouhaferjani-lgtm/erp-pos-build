import type { ReactNode } from 'react'
import { render } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import type { SalesWithholdingTrackingRecord } from '../types'

// --- i18n: return the interpolation string when provided, else the key ---
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// --- router: stub Link ---
vi.mock('react-router-dom', () => ({
  Link: ({ to, children }: { to: string; children: ReactNode }) => (
    <a href={typeof to === 'string' ? to : '#'}>{children}</a>
  ),
}))

// --- currency hook ---
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
}))

// --- data hooks ---
const mockUseSalesWithholdingTracking = vi.hoisted(() => vi.fn())
vi.mock('../hooks/useWithholding', () => ({
  useSalesWithholdingTracking: () => mockUseSalesWithholdingTracking() as unknown,
  useMarkCertificateReceived: () => ({ mutate: vi.fn(), isPending: false }),
}))

import { SalesWithholdingTrackingPage } from './SalesWithholdingTrackingPage'

function makeRecord(
  overrides: Partial<SalesWithholdingTrackingRecord> = {},
): SalesWithholdingTrackingRecord {
  return {
    id: 'rec-1',
    tenantId: 'tenant-1',
    companyId: 'company-1',
    documentId: 'doc-1',
    paymentId: null,
    customerId: 'cust-1',
    customerName: 'Acme Corp',
    invoiceAmount: '1000.000',
    withholdingRate: '0.015',
    withholdingAmount: '15.000',
    expectedReceivable: '985.000',
    certificateNumber: null,
    certificateReceived: false,
    certificateReceivedAt: null,
    notes: null,
    createdAt: '2026-06-01T10:00:00Z',
    updatedAt: '2026-06-01T10:00:00Z',
    ...overrides,
  }
}

describe('SalesWithholdingTrackingPage', () => {
  it('renders exactly one <h1>', () => {
    mockUseSalesWithholdingTracking.mockReturnValue({
      data: [makeRecord()],
      isLoading: false,
    })

    const { container } = render(<SalesWithholdingTrackingPage />)

    expect(container.querySelectorAll('h1')).toHaveLength(1)
  })

  it('renders the certificate status cell as a StatusBadge pill', () => {
    mockUseSalesWithholdingTracking.mockReturnValue({
      data: [makeRecord({ certificateReceived: true })],
      isLoading: false,
    })

    const { container } = render(<SalesWithholdingTrackingPage />)

    const pill = container.querySelector('span.rounded-full')
    expect(pill).not.toBeNull()
  })
})
