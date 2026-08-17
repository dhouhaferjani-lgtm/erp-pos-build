import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { fireEvent } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
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

describe('ProvenanceSection', () => {
  it('navigates refund provenance through the registered receipt detail route', () => {
    const provenance: RefundProvenance = {
      source_receipt_id: 'r1',
      source_receipt_number: 'REC-2026-001',
      credit_note_link: null,
    }
    render(
      <MemoryRouter initialEntries={['/pos/vouchers/v1']}>
        <Routes>
          <Route path="/pos/vouchers/:id" element={<ProvenanceSection voucherSource="refund" provenance={provenance} />} />
          <Route path="/pos/receipts/:id" element={<h1>Receipt detail destination</h1>} />
        </Routes>
      </MemoryRouter>,
    )

    fireEvent.click(screen.getByRole('link', { name: 'REC-2026-001' }))
    expect(screen.getByRole('heading', { name: 'Receipt detail destination' })).toBeInTheDocument()
  })

  it('renders source receipt link for ExchangeSurplus provenance', () => {
    const provenance: RefundProvenance = {
      source_receipt_id: 'r2',
      source_receipt_number: 'REC-2026-002',
      credit_note_link: null,
    }
    render(
      <MemoryRouter>
        <ProvenanceSection voucherSource="exchange_surplus" provenance={provenance} />
      </MemoryRouter>,
    )
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
