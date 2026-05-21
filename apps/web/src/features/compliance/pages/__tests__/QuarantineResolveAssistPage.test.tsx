import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { QuarantineResolveAssistPage } from '../QuarantineResolveAssistPage'

const mockBestEffortParseQuarantine = vi.hoisted(() => vi.fn())

vi.mock('../../api/quarantineResolutionApi', () => ({
  bestEffortParseQuarantine: mockBestEffortParseQuarantine,
}))

describe('QuarantineResolveAssistPage', () => {
  beforeEach(() => {
    mockBestEffortParseQuarantine.mockReset()
  })

  it('submits the quarantine id and renders parsed payload plus defects', async () => {
    mockBestEffortParseQuarantine.mockResolvedValue({
      id: 'event-1',
      source: 'fiscal_events',
      fiscal_event_id: 'event-1',
      event_type: 'SALE_RECEIPT',
      parsed: {
        total: '5.350',
        seller: { tax_number: 'TN-INVALID' },
      },
      defects: [
        {
          path: 'payload.seller',
          code: 'payload_tax_number_country_mismatch',
          message: 'seller.tax_number failed TN validation',
        },
      ],
    })

    const user = userEvent.setup()
    renderWithProviders(<QuarantineResolveAssistPage />)

    await user.type(screen.getByLabelText(/Quarantine or fiscal event id/i), 'event-1')
    await user.click(screen.getByRole('button', { name: /Parse/i }))

    await waitFor(() => {
      expect(mockBestEffortParseQuarantine).toHaveBeenCalled()
    })
    expect(mockBestEffortParseQuarantine.mock.calls[0]?.[0]).toBe('event-1')
    expect(await screen.findByText('SALE_RECEIPT')).toBeInTheDocument()
    expect(screen.getByText('payload.seller')).toBeInTheDocument()
    expect(screen.getByLabelText(/Parsed payload JSON/i)).toHaveDisplayValue(/"total": "5.350"/)
  })

  it('shows an error message when parsing fails', async () => {
    mockBestEffortParseQuarantine.mockRejectedValue(new Error('not found'))

    const user = userEvent.setup()
    renderWithProviders(<QuarantineResolveAssistPage />)

    await user.type(screen.getByLabelText(/Quarantine or fiscal event id/i), 'missing')
    await user.click(screen.getByRole('button', { name: /Parse/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent('not found')
  })
})
