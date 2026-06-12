import { describe, it, expect, vi } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { SettingsPage } from '../SettingsPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}))

describe('SettingsPage sections', () => {
  it('links to the POS refund policies and customer history audit pages (moved here from the sidebar bottom nav)', () => {
    renderWithProviders(<SettingsPage />)

    const refundCard = screen.getByRole('link', { name: /posRefundPolicies/i })
    expect(refundCard).toHaveAttribute('href', '/settings/pos-refund-policies')

    const auditCard = screen.getByRole('link', { name: /customerHistoryAudit/i })
    expect(auditCard).toHaveAttribute('href', '/settings/audit/customer-history')
  })
})
