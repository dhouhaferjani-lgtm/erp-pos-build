import type { ReactNode } from 'react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import type { WithholdingCertificate } from './types'

// --- i18n: return the interpolation string when provided, else the key ---
vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

// --- router: stub useParams / useNavigate / Link ---
vi.mock('react-router-dom', () => ({
  useParams: () => ({ id: 'cert-1' }),
  useNavigate: () => vi.fn(),
  Link: ({ to, children }: { to: string; children: ReactNode }) => (
    <a href={typeof to === 'string' ? to : '#'}>{children}</a>
  ),
}))

// --- currency hook ---
vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({ decimals: 3 }),
}))

// --- toast ---
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

// --- api (download helpers) ---
vi.mock('./api/withholdingApi', () => ({
  downloadCertificatePDF: (id: string) => `/pdf/${id}`,
  downloadCertificateTEJXML: (id: string) => `/tej/${id}`,
}))

// --- data hooks ---
const mockUseWithholdingCertificate = vi.hoisted(() => vi.fn())
vi.mock('./hooks/useWithholding', () => ({
  useWithholdingCertificate: () => mockUseWithholdingCertificate() as unknown,
  useIssueWithholdingCertificate: () => ({ mutateAsync: vi.fn() }),
  useVoidWithholdingCertificate: () => ({ mutateAsync: vi.fn() }),
  useSubmitCertificateToTEJ: () => ({ mutateAsync: vi.fn() }),
  useDeleteWithholdingCertificate: () => ({ mutateAsync: vi.fn() }),
}))

import { WithholdingCertificateDetail } from './WithholdingCertificateDetail'

function makeCertificate(
  overrides: Partial<WithholdingCertificate> = {},
): WithholdingCertificate {
  return {
    id: 'cert-1',
    certificate_number: 'WHT-2026-0001',
    year: 2026,
    reference: 'REF-1',
    direction: 'purchase',
    direction_label: 'Purchase',
    status: 'issued',
    status_label: 'Issued',
    partner_id: 'p-1',
    partner: { id: 'p-1', name: 'Acme SARL', vat_number: 'TN123' },
    document_id: null,
    payment_id: null,
    currency: 'TND',
    gross_amount: '1000.000',
    withholding_rate: '0.015',
    withholding_amount: '15.000',
    net_amount: '985.000',
    rate_percentage: 1.5,
    withholding_rule_id: 'r-1',
    rule: { id: 'r-1', code: 'WHT-SVC', name: 'Services' },
    override_reason: null,
    is_manual_override: false,
    tej_reference: null,
    tej_submitted_at: null,
    is_submitted_to_tej: false,
    certificate_media_id: null,
    hash: null,
    previous_hash: null,
    chain_sequence: null,
    issued_at: '2026-06-14T10:00:00Z',
    issued_by: 'u-1',
    issuer: { id: 'u-1', name: 'Jane' },
    created_at: '2026-06-14T09:00:00Z',
    updated_at: '2026-06-14T09:30:00Z',
    can_be_modified: false,
    can_be_issued: false,
    can_be_submitted: true,
    can_be_voided: true,
    gl_account_code: '4452',
    ...overrides,
  }
}

describe('WithholdingCertificateDetail', () => {
  it('renders a single h1 with the certificate number', () => {
    mockUseWithholdingCertificate.mockReturnValue({
      data: makeCertificate(),
      isLoading: false,
    })

    render(<WithholdingCertificateDetail />)

    const headings = screen.getAllByRole('heading', { level: 1 })
    expect(headings).toHaveLength(1)
    expect(headings[0]).toHaveTextContent('WHT-2026-0001')
  })

  it('renders the status via a StatusBadge pill (rounded-full)', () => {
    mockUseWithholdingCertificate.mockReturnValue({
      data: makeCertificate({ status: 'issued' }),
      isLoading: false,
    })

    render(<WithholdingCertificateDetail />)

    // status label appears via t('status.issued')
    const badge = screen.getAllByText('status.issued')[0]
    expect(badge.className).toContain('rounded-full')
  })

  it('shows the not-found state when no certificate is returned', () => {
    mockUseWithholdingCertificate.mockReturnValue({
      data: undefined,
      isLoading: false,
    })

    render(<WithholdingCertificateDetail />)

    expect(screen.getByText('common:notFound')).toBeInTheDocument()
    expect(screen.queryByRole('heading', { level: 1 })).not.toBeInTheDocument()
  })
})
