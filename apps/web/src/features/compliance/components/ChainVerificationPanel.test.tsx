import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { I18nextProvider } from 'react-i18next'
import i18n from '@/lib/i18n'
import { useCompanyStore } from '../../../stores/companyStore'
import { ChainVerificationPanel } from './ChainVerificationPanel'
import {
  verifyChains,
  type ChainVerificationResponse,
  type ChainVerificationResult,
} from '../api/complianceApi'

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
  receipt_chain: {
    is_valid: false,
    total_receipts: 10,
    verified: 6,
    failed_at_sequence: 7,
    // VERBATIM backend string (Nf525DataProvider.php:481)
    error: 'Fiscal hash mismatch: receipt data may have been tampered with',
  },
  z_report_chain: { is_valid: true, total_reports: 3, verified: 3, failed_at_z_number: null, error: null },
  is_valid: false,
}

/**
 * A Phase-1 terminal: every receipt carries a `fiscal_event_id`, so the legacy
 * arm queried by Nf525DataProvider::verifyReceiptChain (`whereNull('fiscal_event_id')`,
 * :390-394) is EMPTY and the method early-returns isValid:true / totalRows:0
 * (:409-418) — even for a terminal holding thousands of sealed receipts.
 *
 * This is the NORMAL post-Phase-1 shape, NOT an anomaly: the fiscal-events arm
 * already ran and PASSED at :396-407 (ReceiptHashService::verifyTerminalChainFiscalArm
 * -> inspectFiscalEventsArm, ReceiptHashService.php:267-340, which rehashes every
 * fiscal_events row) before that early return was reached. Only the COUNT is
 * legacy-scoped; the verification is not.
 */
const phase1Terminal: ChainVerificationResult = {
  terminal_id: 't-3',
  terminal_code: 'CAISSE-03',
  terminal_name: 'Caisse 3',
  receipt_chain: { is_valid: true, total_receipts: 0, verified: 0, failed_at_sequence: null, error: null },
  z_report_chain: { is_valid: true, total_reports: 4, verified: 4, failed_at_z_number: null, error: null },
  is_valid: true,
}

function envelope(
  terminals: ChainVerificationResult[],
  overrides: Partial<ChainVerificationResponse> = {},
): ChainVerificationResponse {
  return {
    company_id: 'company-1',
    terminals,
    all_chains_valid: terminals.every((row) => row.is_valid),
    verified_at: '2026-09-04T10:00:00+00:00',
    ...overrides,
  }
}

function renderPanel() {
  return render(
    <I18nextProvider i18n={i18n}>
      <ChainVerificationPanel />
    </I18nextProvider>,
  )
}

function rowFor(terminalCode: string): HTMLTableRowElement {
  const row = screen.getByText(terminalCode).closest('tr')
  if (row === null) throw new Error(`no table row for terminal ${terminalCode}`)
  return row
}

/** The receipt-chain verdict cell is the 2nd column, the legacy-rows count the 3rd. */
function receiptCells(terminalCode: string): {
  verdict: HTMLElement
  count: HTMLElement
} {
  const cells = within(rowFor(terminalCode)).getAllByRole('cell')
  expect(cells).toHaveLength(5)
  return { verdict: cells[1], count: cells[2] }
}

describe('ChainVerificationPanel', () => {
  beforeEach(() => {
    mockedVerify.mockReset()
    useCompanyStore.setState({ currentCompanyId: 'company-1' })
  })

  it('renders a row per terminal from the real backend payload without crashing', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([validTerminal, brokenTerminal]))
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    expect(await screen.findByText('CAISSE-01')).toBeInTheDocument()
    expect(screen.getByText('CAISSE-02')).toBeInTheDocument()
    // the legacy-rows column reflects the backend verified/total pair
    expect(receiptCells('CAISSE-01').count.textContent).toBe('42 / 42')
    expect(receiptCells('CAISSE-02').count.textContent).toBe('6 / 10')
  })

  it('shows the failing terminal with its failed sequence', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([brokenTerminal]))
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-02')
    expect(screen.getByText(/#7/)).toBeInTheDocument()
    expect(
      within(receiptCells('CAISSE-02').verdict).getByText(
        i18n.t('compliance:chainVerification.broken'),
      ),
    ).toBeInTheDocument()
  })

  it('translates a known backend diagnostic instead of printing its English text', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([brokenTerminal]))
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-02')
    // RED if the raw string is rendered: rule 11 forbids untranslated backend prose
    expect(
      screen.queryByText(/receipt data may have been tampered with/),
    ).toBeNull()
    expect(
      screen.getByText(i18n.t('compliance:chainVerification.errors.fiscalHashMismatch')),
    ).toBeInTheDocument()
  })

  it('marks an UNKNOWN diagnostic as a labelled technical detail, not operator copy', async () => {
    const unknown: ChainVerificationResult = {
      ...brokenTerminal,
      receipt_chain: {
        ...brokenTerminal.receipt_chain,
        error: 'Brand new backend failure: some_internal_flag was NULL',
      },
    }
    mockedVerify.mockResolvedValueOnce(envelope([unknown]))
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-02')
    const detail = screen.getByText('Brand new backend failure: some_internal_flag was NULL')
    // rendered as monospace technical detail behind a translated label
    expect(detail.tagName).toBe('CODE')
    expect(detail.className).toContain('font-mono')
    expect(
      screen.getByText(`${i18n.t('compliance:chainVerification.technicalDetail')}:`),
    ).toBeInTheDocument()
  })

  it('renders a zero-legacy-row terminal as VERIFIED, not as an anomaly', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([phase1Terminal]))
    const { container } = renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-03')
    const { verdict, count } = receiptCells('CAISSE-03')

    // The backend returned a PASS (the event arm ran and verified first), so the
    // badge is the plain success verdict. RED against fix round 1, which showed a
    // caution "Not covered" badge here and denied the verification outright.
    expect(
      within(verdict).getByText(i18n.t('compliance:chainVerification.valid')),
    ).toBeInTheDocument()
    // no amber anywhere on this panel: a verified fleet must not read as an alarm
    expect(container.querySelector('[class*="amber"]')).toBeNull()
    // the count column reports "none" for the legacy figure, without a verdict claim
    expect(count.textContent).toBe(i18n.t('compliance:chainVerification.noLegacyRows'))
    // the caveat is scoped to the COUNT and sits once under the table
    expect(
      screen.getByText(i18n.t('compliance:chainVerification.legacyRowsCountNote')),
    ).toBeInTheDocument()
    // and the fleet banner stays the plain green pass
    expect(
      screen.getByText(i18n.t('compliance:chainVerification.allValid')),
    ).toBeInTheDocument()
  })

  it('never claims the event-chain arm went unverified', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([phase1Terminal]))
    const { container } = renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-03')
    // Nf525DataProvider.php:396-407 runs (and passes) the fiscal-events arm BEFORE
    // the legacy early-return at :409-418, so any copy asserting the event chain was
    // not covered is factually false. Guard the whole rendered panel, not one node.
    const text = container.textContent
    expect(text).not.toMatch(/not covered/i)
    expect(text).not.toMatch(/covers the legacy arm only/i)
  })

  it('keeps the plain all-valid banner when every receipt arm actually had rows', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([validTerminal]))
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-01')
    expect(
      screen.getByText(i18n.t('compliance:chainVerification.allValid')),
    ).toBeInTheDocument()
  })

  it('renders the verification timestamp as an "as of" stamp', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([validTerminal]))
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    await screen.findByText('CAISSE-01')
    // RED before the fix: verified_at was dropped, so an integrity verdict
    // carried no audit timestamp at all.
    expect(
      screen.getByText(
        new RegExp(i18n.t('compliance:chainVerification.verifiedAt', { timestamp: '.*' })),
      ),
    ).toBeInTheDocument()
  })

  it('degrades gracefully on an empty payload (no TypeError, shows no-terminals)', async () => {
    mockedVerify.mockResolvedValueOnce(envelope([]))
    renderPanel()

    await userEvent.click(screen.getByRole('button'))

    expect(await screen.findByText(i18n.t('compliance:chainVerification.noTerminals'))).toBeInTheDocument()
  })
})
