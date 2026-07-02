import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, it, expect, vi } from 'vitest'
import type { AgedReceivablesData } from '../types'
import { AgedReceivablesPage } from './AgedReceivablesPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (k: string, s?: unknown) => (typeof s === 'string' ? s : k),
  }),
}))

const { mockUseAgedReceivables } = vi.hoisted(() => ({
  mockUseAgedReceivables: vi.fn(),
}))

vi.mock('../hooks/useAgedReceivables', () => ({
  useAgedReceivables: mockUseAgedReceivables,
}))

const fixture: AgedReceivablesData = {
  lines: [
    {
      customer_id: '00000000-0000-4000-8000-000000000001',
      customer_name: 'ACME Corp',
      current: '1000.00',
      days_30: '500.00',
      days_60: '200.00',
      days_90: '100.00',
      over_90: '50.00',
      total: '1850.00',
    },
  ],
  total_current: '1000.00',
  total_days_30: '500.00',
  total_days_60: '200.00',
  total_days_90: '100.00',
  total_over_90: '50.00',
  grand_total: '1850.00',
  as_of_date: '2026-06-14',
}

describe('AgedReceivablesPage', () => {
  function renderPage() {
    return render(
      <MemoryRouter>
        <AgedReceivablesPage />
      </MemoryRouter>,
    )
  }

  it('renders exactly one h1', () => {
    mockUseAgedReceivables.mockReturnValue({
      data: fixture,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { container } = renderPage()

    expect(container.querySelectorAll('h1').length).toBe(1)
  })

  it('renders the export control as a real button', () => {
    mockUseAgedReceivables.mockReturnValue({
      data: fixture,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    renderPage()

    expect(screen.getByRole('button')).toBeInTheDocument()
  })

  it('right-aligns numeric money cells with tabular figures', () => {
    mockUseAgedReceivables.mockReturnValue({
      data: fixture,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { container } = renderPage()

    const moneyCell = Array.from(container.querySelectorAll('td')).find((td) =>
      td.textContent.includes('1,000.00')
    )
    expect(moneyCell).toBeDefined()
    expect(moneyCell).toHaveClass('tabular-nums')
    expect(moneyCell).toHaveClass('text-end')
  })

  it('links customer names to customer detail pages', () => {
    mockUseAgedReceivables.mockReturnValue({
      data: fixture,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    renderPage()

    expect(screen.getByRole('link', { name: 'ACME Corp' })).toHaveAttribute(
      'href',
      '/sales/customers/00000000-0000-4000-8000-000000000001',
    )
  })
})
