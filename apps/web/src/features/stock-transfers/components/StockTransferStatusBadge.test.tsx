import { describe, expect, it } from 'vitest'
import { act, screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import i18n from '@/lib/i18n'
import { StockTransferStatusBadge } from './StockTransferStatusBadge'
import type { StockTransferStatus } from '../types'

const statuses: [StockTransferStatus, string][] = [
  ['draft', 'Draft'],
  ['in_transit', 'In Transit'],
  ['partially_received', 'Partially received'],
  ['completed', 'Completed'],
  ['closed_with_writeoff', 'Closed with write-off'],
  ['closed_returned', 'Closed with return'],
  ['cancelled', 'Cancelled'],
]

describe('StockTransferStatusBadge', () => {
  it('loads the new Arabic status labels', async () => {
    await act(async () => { await i18n.changeLanguage('ar') })
    try {
      renderWithProviders(<StockTransferStatusBadge status="partially_received" />)
      expect(screen.getByText('مستلم جزئيًا')).toBeInTheDocument()
    } finally {
      await act(async () => { await i18n.changeLanguage('en') })
    }
  })

  it.each(statuses)('renders the %s status label', (status, label) => {
    renderWithProviders(<StockTransferStatusBadge status={status} />)
    expect(screen.getByText(label)).toBeInTheDocument()
  })
})
