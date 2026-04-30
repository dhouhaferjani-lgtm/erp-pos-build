import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { ProvenanceSection } from '../ProvenanceSection'
import type {
  RefundProvenance,
  GoodwillProvenance,
  LoyaltyCreditProvenance,
  OtherProvenance,
} from '../../types/voucher'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('react-router-dom', () => ({
  Link: ({ to, children }: { to: string; children: React.ReactNode }) => (
    <a href={to}>{children}</a>
  ),
}))

describe('ProvenanceSection', () => {
  it('renders source receipt link for Refund provenance', () => {
    const provenance: RefundProvenance = {
      source_receipt_id: 'r1',
      source_receipt_number: 'REC-2026-001',
      credit_note_link: null,
    }
    render(<ProvenanceSection voucherSource="refund" provenance={provenance} />)
    expect(screen.getByText('REC-2026-001')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'REC-2026-001' })).toHaveAttribute(
      'href',
      '/pos/receipts/r1',
    )
  })

  it('renders source receipt link for ExchangeSurplus provenance', () => {
    const provenance: RefundProvenance = {
      source_receipt_id: 'r2',
      source_receipt_number: 'REC-2026-002',
      credit_note_link: null,
    }
    render(<ProvenanceSection voucherSource="exchange_surplus" provenance={provenance} />)
    expect(screen.getByText('REC-2026-002')).toBeInTheDocument()
  })

  it('renders issuedBy, notes, and override_reason for Goodwill provenance', () => {
    const provenance: GoodwillProvenance = {
      issued_by_user_name: 'Alice Admin',
      authorized_by_user_id: null,
      override_reason: 'Exception approved',
      notes: 'Customer complaint resolved',
    }
    render(<ProvenanceSection voucherSource="goodwill" provenance={provenance} />)
    expect(screen.getByText('Alice Admin')).toBeInTheDocument()
    expect(screen.getByText('Customer complaint resolved')).toBeInTheDocument()
    expect(screen.getByText('Exception approved')).toBeInTheDocument()
  })

  it('renders Phase 1.5 placeholder for LoyaltyCredit without transaction id', () => {
    const provenance: LoyaltyCreditProvenance = {
      source_loyalty_transaction_id: null,
    }
    render(<ProvenanceSection voucherSource="loyalty_credit" provenance={provenance} />)
    expect(screen.getByText('provenance.loyaltyPhase15Placeholder')).toBeInTheDocument()
  })

  it('renders Phase 2 placeholder for GiftCard provenance', () => {
    const provenance: OtherProvenance = {}
    render(<ProvenanceSection voucherSource="gift_card_purchase" provenance={provenance} />)
    expect(screen.getByText('provenance.phase2Placeholder')).toBeInTheDocument()
  })

  it('renders Phase 2 placeholder for Promotional provenance', () => {
    const provenance: OtherProvenance = {}
    render(<ProvenanceSection voucherSource="promotional" provenance={provenance} />)
    expect(screen.getByText('provenance.phase2Placeholder')).toBeInTheDocument()
  })

  it('renders nothing for null provenance', () => {
    const { container } = render(
      <ProvenanceSection voucherSource="refund" provenance={null} />,
    )
    expect(container).toBeEmptyDOMElement()
  })
})
