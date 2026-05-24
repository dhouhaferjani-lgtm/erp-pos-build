import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { QuarantineResolveAssistPage } from '../QuarantineResolveAssistPage'

const mockBestEffortParseQuarantine = vi.hoisted(() => vi.fn())
const mockResolveParseFailure = vi.hoisted(() => vi.fn())

vi.mock('../../api/quarantineResolutionApi', () => ({
  bestEffortParseQuarantine: mockBestEffortParseQuarantine,
  resolveParseFailure: mockResolveParseFailure,
}))

describe('QuarantineResolveAssistPage', () => {
  beforeEach(() => {
    mockBestEffortParseQuarantine.mockReset()
    mockResolveParseFailure.mockReset()
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
    expect(screen.getAllByText('payload.seller').length).toBeGreaterThan(0)
    expect(screen.getByLabelText(/Parsed payload JSON/i)).toHaveDisplayValue(/"total": "5.350"/)
    expect(screen.getByLabelText(/Corrected value for payload.seller/i)).toHaveDisplayValue(/TN-INVALID/)
  })

  it('submits corrected payload for in-table fiscal events only', async () => {
    mockBestEffortParseQuarantine.mockResolvedValue({
      id: 'event-1',
      source: 'fiscal_events',
      fiscal_event_id: 'event-1',
      event_type: 'SALE_RECEIPT',
      parsed: {
        total: '5.350',
        seller: { tax_number: 'TN-INVALID', name: 'Default Seller' },
      },
      defects: [
        {
          path: 'payload.seller.tax_number',
          code: 'payload_tax_number_country_mismatch',
          message: 'seller.tax_number failed TN validation',
        },
      ],
    })
    mockResolveParseFailure.mockResolvedValue({ id: 'event-1', status: 'resolved' })

    const user = userEvent.setup()
    renderWithProviders(<QuarantineResolveAssistPage />)

    await user.type(screen.getByLabelText(/Quarantine or fiscal event id/i), 'event-1')
    await user.click(screen.getByRole('button', { name: /Parse/i }))

    const correction = await screen.findByLabelText(/Corrected value for payload.seller.tax_number/i)
    await user.clear(correction)
    await user.type(correction, '1234567AM000')
    await user.click(screen.getByRole('button', { name: /Submit correction/i }))

    await waitFor(() => {
      expect(mockResolveParseFailure).toHaveBeenCalled()
    })
    expect(mockResolveParseFailure.mock.calls[0]?.[0]).toBe('event-1')
    expect(mockResolveParseFailure.mock.calls[0]?.[1]).toMatchObject({
      total: '5.350',
      seller: { tax_number: '1234567AM000', name: 'Default Seller' },
    })
    expect(await screen.findByText(/Parse failure resolved/i)).toBeInTheDocument()
  })

  it('does not reintroduce omitted extra payload fields on submit', async () => {
    mockBestEffortParseQuarantine.mockResolvedValue({
      id: 'event-1',
      source: 'fiscal_events',
      fiscal_event_id: 'event-1',
      event_type: 'SALE_RECEIPT',
      parsed: {
        total: '5.350',
        seller: { tax_number: '1234567AM000' },
      },
      defects: [
        {
          path: 'payload.legacy_hash',
          code: 'payload_extra_field',
          message: 'Payload field is not part of the canonical contract.',
        },
      ],
    })
    mockResolveParseFailure.mockResolvedValue({ id: 'event-1', status: 'resolved' })

    const user = userEvent.setup()
    renderWithProviders(<QuarantineResolveAssistPage />)

    await user.type(screen.getByLabelText(/Quarantine or fiscal event id/i), 'event-1')
    await user.click(screen.getByRole('button', { name: /Parse/i }))

    expect(await screen.findByText('payload.legacy_hash')).toBeInTheDocument()
    expect(screen.queryByLabelText(/Corrected value for payload.legacy_hash/i)).not.toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: /Submit correction/i }))

    await waitFor(() => {
      expect(mockResolveParseFailure).toHaveBeenCalled()
    })
    expect(mockResolveParseFailure.mock.calls[0]?.[1]).toEqual({
      total: '5.350',
      seller: { tax_number: '1234567AM000' },
    })
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
