import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { I18nextProvider } from 'react-i18next'
import i18n from '@/lib/i18n'
import { useCompanyStore } from '../../../stores/companyStore'
import { ChainVerificationPanel } from './ChainVerificationPanel'
import { verifyChains, type ChainVerificationResult } from '../api/complianceApi'

vi.mock('../api/complianceApi', () => ({
  verifyChains: vi.fn(),
}))

const mockedVerify = vi.mocked(verifyChains)

// Backend field names (Nf525ExportController::verifyChains) — the regression guard:
// rows must render off these, not the old chain_length/broken_at_* names.
const validTerminal: ChainVerificationResult = {
  terminal_id: 't-1',
  terminal_code: 'CAISSE-01',
  terminal_name: 'Caisse 1',
  receipt_chain: { is_valid: true, total_receipts: 42, verified: 42, failed_at_sequence: null, error: null },
  z_report_chain: { is_valid: true, total_reports: 5, verified: 5, failed_at_z_number: null, error: null },
  is_valid: true,
}

const brokenTerminal: ChainVerificationResult = {
  terminal_id: 't-2',
  terminal_code: 'CAISSE-02',
  terminal_name: 'Caisse 2',
  receipt_chain: { is_valid: false, total_receipts: 10, verified: 6, failed_at_sequence: 7, error: 'hash mismatch at #7' },
  z_report_chain: { is_valid: true, total_reports: 3, verified: 3, failed_at_z_number: null, error: null },
  is_valid: false,
}

function renderPanel() {
  return render(
    <I18nextProvider i18n={i18n}>
      <ChainVerificationPanel />
    </I18nextProvider>,
  )
}

describe('ChainVerificationPanel', () => {
  beforeEach(() => {
    mockedVerify.mockReset()
    useCompanyStore.setState({ currentCompanyId: 'company-1' })
  })

  it('renders a row per terminal from the real backend payload without crashing', async () => {
    mockedVerify.mockResolvedValueOnce([validTerminal, brokenTerminal])
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    expect(await screen.findByText('CAISSE-01')).toBeInTheDocument()
    expect(screen.getByText('CAISSE-02')).toBeInTheDocument()
    // chain length column reflects the backend total_* fields
    expect(screen.getByText('42')).toBeInTheDocument()
  })

  it('shows the failing terminal with its failed sequence and error', async () => {
    mockedVerify.mockResolvedValueOnce([brokenTerminal])
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-02')
    expect(screen.getByText(/#7/)).toBeInTheDocument()
    expect(screen.getByText(/hash mismatch at #7/)).toBeInTheDocument()
  })

  it('degrades gracefully on an empty payload (no TypeError, shows no-terminals)', async () => {
    mockedVerify.mockResolvedValueOnce([])
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    expect(await screen.findByText(i18n.t('compliance:chainVerification.noTerminals'))).toBeInTheDocument()
  })
})
