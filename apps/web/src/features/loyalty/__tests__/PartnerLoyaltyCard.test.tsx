import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { PartnerLoyaltyCard } from '../components/PartnerLoyaltyCard'
import type { PartnerLoyaltySummary } from '../api/partnerLoyaltyApi'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const mockMutate = vi.fn()
let mockUsePartnerLoyaltyReturn: { data: PartnerLoyaltySummary | undefined; isLoading: boolean }
let mockUseEnrollPartnerReturn: { mutate: typeof mockMutate; isPending: boolean }

vi.mock('../hooks/usePartnerLoyalty', () => ({
  usePartnerLoyalty: () => mockUsePartnerLoyaltyReturn,
  useEnrollPartner: () => mockUseEnrollPartnerReturn,
}))

const mockHasPermission = vi.fn((_permission: string): boolean => true)
vi.mock('@/hooks/usePermissions', () => ({
  usePermissions: () => ({
    hasPermission: (p: string) => mockHasPermission(p),
  }),
}))

const notMemberFixture: PartnerLoyaltySummary = {
  is_member: false,
  member_id: null,
  phone: null,
  first_name: null,
  enrollments: [],
}

const memberFixture: PartnerLoyaltySummary = {
  is_member: true,
  member_id: 'member-1',
  phone: '+33612345678',
  first_name: 'Jane',
  enrollments: [
    {
      enrollment_id: 'enrollment-1',
      program_id: 'program-1',
      program_name: 'Gold Rewards',
      balance: '150',
      tier: 'Gold',
      status: 'active',
    },
  ],
}

describe('PartnerLoyaltyCard', () => {
  beforeEach(() => {
    mockMutate.mockReset()
    mockHasPermission.mockReset()
    mockHasPermission.mockImplementation(() => true)
    mockUsePartnerLoyaltyReturn = { data: notMemberFixture, isLoading: false }
    mockUseEnrollPartnerReturn = { mutate: mockMutate, isPending: false }
  })

  it('shows the Enroll button when the partner is not a member and the user has loyalty.enroll', () => {
    render(<PartnerLoyaltyCard partnerId="partner-1" partnerPhone="+33600000000" />)

    expect(screen.getByText('loyalty:partnerCard.notMember')).toBeInTheDocument()
    expect(screen.getByText('loyalty:partnerCard.enroll')).toBeInTheDocument()
  })

  it('shows balance, program name, and tier when the partner is a member', () => {
    mockUsePartnerLoyaltyReturn = { data: memberFixture, isLoading: false }

    render(<PartnerLoyaltyCard partnerId="partner-1" partnerPhone="+33612345678" />)

    expect(screen.getByText('Gold Rewards')).toBeInTheDocument()
    expect(screen.getByText('Gold')).toBeInTheDocument()
    expect(screen.getByText(/150/)).toBeInTheDocument()
  })

  it('hides the Enroll button without loyalty.enroll but still shows the balance', () => {
    mockUsePartnerLoyaltyReturn = { data: memberFixture, isLoading: false }
    mockHasPermission.mockImplementation(() => false)

    render(<PartnerLoyaltyCard partnerId="partner-1" partnerPhone="+33612345678" />)

    expect(screen.queryByText('loyalty:partnerCard.enroll')).not.toBeInTheDocument()
    expect(screen.getByText('Gold Rewards')).toBeInTheDocument()
    expect(screen.getByText(/150/)).toBeInTheDocument()
  })

  it('calls the enroll mutation with the typed phone number', () => {
    render(<PartnerLoyaltyCard partnerId="partner-1" partnerPhone="+33600000000" />)

    fireEvent.click(screen.getByText('loyalty:partnerCard.enroll'))

    const phoneInput = screen.getByTestId('loyalty-enroll-phone-input')
    expect(phoneInput).toHaveValue('+33600000000')

    fireEvent.change(phoneInput, { target: { value: '+33698765432' } })
    fireEvent.click(screen.getByTestId('loyalty-enroll-submit'))

    expect(mockMutate).toHaveBeenCalledTimes(1)
    expect(mockMutate.mock.calls[0][0]).toEqual({ phone: '+33698765432' })
  })
})
