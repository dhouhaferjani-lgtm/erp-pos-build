import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, it, expect, vi } from 'vitest'
import { formatCurrency } from '../../../lib/format'
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

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({
    currentCompany: { currency: 'TND', locale: 'fr_TN' },
  }),
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
  buckets_by_location: [{ location_id: 'loc-a', location_name: 'Store A', total: '1200.00' }],
}

function normalizeSpaces(value: string): string {
  return value.replace(/\s/g, ' ')
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

  it('renders the location breakdown and attribution caveat', () => {
    mockUseAgedReceivables.mockReturnValue({ data: fixture, isLoading: false, error: null, refetch: vi.fn() })

    renderPage()

    expect(screen.getByText('finance:reports.defaultAttributedCaveat')).toBeInTheDocument()
    expect(screen.getByText('Store A')).toBeInTheDocument()
  })

  it('right-aligns numeric money cells with tabular figures', () => {
    mockUseAgedReceivables.mockReturnValue({
      data: fixture,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    const { container } = renderPage()
    const formattedCurrent = normalizeSpaces(formatCurrency('1000.00', {
      currency: 'TND',
      locale: 'fr-TN',
    }))

    const moneyCell = Array.from(container.querySelectorAll('td')).find((td) =>
      normalizeSpaces(td.textContent).includes(formattedCurrent)
    )
    expect(moneyCell).toBeDefined()
    expect(moneyCell).toHaveClass('tabular-nums')
    expect(moneyCell).toHaveClass('text-end')
  })

  it('formats money with the selected company currency without US formatting', () => {
    mockUseAgedReceivables.mockReturnValue({
      data: fixture,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
    })

    renderPage()

    const formattedCurrent = formatCurrency('1000.00', {
      currency: 'TND',
      locale: 'fr-TN',
    })
    expect(normalizeSpaces(document.body.textContent)).toContain(
      normalizeSpaces(formattedCurrent)
    )
    expect(screen.queryByText('1,000.00')).not.toBeInTheDocument()
  })

  it('defaults the as-of date to today', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-15T12:00:00.000Z'))
    try {
      mockUseAgedReceivables.mockReturnValue({
        data: fixture,
        isLoading: false,
        error: null,
        refetch: vi.fn(),
      })

      renderPage()

      expect(screen.getByLabelText('finance:reports.common.asOfDate')).toHaveValue('2026-07-15')
    } finally {
      vi.useRealTimers()
    }
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
