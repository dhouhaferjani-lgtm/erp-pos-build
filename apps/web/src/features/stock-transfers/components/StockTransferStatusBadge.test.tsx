import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
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
  it.each(statuses)('renders the %s status label', (status, label) => {
    renderWithProviders(<StockTransferStatusBadge status={status} />)
    expect(screen.getByText(label)).toBeInTheDocument()
  })
})
