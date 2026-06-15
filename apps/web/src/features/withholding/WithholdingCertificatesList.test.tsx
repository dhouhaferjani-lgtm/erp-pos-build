import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import type { ReactNode } from 'react'
import { WithholdingCertificatesList } from './WithholdingCertificatesList'
import type { WithholdingCertificate } from './types'

// i18n: echo interpolation strings, otherwise return the key
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// Router: Link renders a plain anchor so we can read its href
vi.mock('react-router-dom', () => ({
  Link: ({ to, children }: { to: string; children: ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

// Currency hook
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
}))

// API: spy on the batch export download
const downloadBatchTEJXML = vi.fn(() => 'https://example.test/batch.xml')
vi.mock('./api/withholdingApi', () => ({
  downloadCertificatePDF: vi.fn(() => 'pdf'),
  downloadCertificateTEJXML: vi.fn(() => 'tej'),
  downloadBatchTEJXML: () => downloadBatchTEJXML(),
}))

// Data + mutation hooks
interface CertificatesQueryResult {
  data: { data: WithholdingCertificate[] } | undefined
  isLoading: boolean
}
const useWithholdingCertificates = vi.fn<() => CertificatesQueryResult>()
vi.mock('./hooks/useWithholding', () => ({
  useWithholdingCertificates: () => useWithholdingCertificates(),
  useIssueWithholdingCertificate: () => ({ mutateAsync: vi.fn() }),
  useVoidWithholdingCertificate: () => ({ mutateAsync: vi.fn() }),
}))

const certificate: WithholdingCertificate = {
  id: 'c1',
  certificate_number: 'WC-0001',
  year: 2026,
  reference: 'ref',
  direction: 'purchase',
  direction_label: 'Purchase',
  status: 'issued',
  status_label: 'Issued',
  partner_id: 'p1',
  partner: { id: 'p1', name: 'ACME', vat_number: null },
  document_id: null,
  payment_id: null,
  currency: 'TND',
  gross_amount: '1000.000',
  withholding_rate: '0.015',
  withholding_amount: '15.000',
  net_amount: '985.000',
  rate_percentage: 1.5,
  withholding_rule_id: null,
  override_reason: null,
  is_manual_override: false,
  tej_reference: null,
  tej_submitted_at: null,
  is_submitted_to_tej: false,
  certificate_media_id: null,
  hash: null,
  previous_hash: null,
  chain_sequence: null,
  issued_at: null,
  issued_by: null,
  created_at: '2026-01-01',
  updated_at: '2026-01-01',
  can_be_modified: false,
  can_be_issued: false,
  can_be_submitted: false,
  can_be_voided: true,
  gl_account_code: '4321',
}

beforeEach(() => {
  vi.clearAllMocks()
  useWithholdingCertificates.mockReturnValue({
    data: { data: [certificate] },
    isLoading: false,
  })
})

describe('WithholdingCertificatesList', () => {
  it('renders exactly one h1', () => {
    const { container } = render(<WithholdingCertificatesList />)
    expect(container.querySelectorAll('h1')).toHaveLength(1)
  })

  it('renders the status as a StatusBadge pill', () => {
    const { container } = render(<WithholdingCertificatesList />)
    const pill = Array.from(container.querySelectorAll('span')).find((el) =>
      el.className.includes('rounded-full'),
    )
    expect(pill).toBeTruthy()
  })

  it('links the certificate number to its detail route', () => {
    render(<WithholdingCertificatesList />)
    const link = screen
      .getAllByRole('link')
      .find((a) => a.getAttribute('href') === '/treasury/withholding-certificates/c1')
    expect(link).toBeTruthy()
  })
})
