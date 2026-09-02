import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'

import { UnknownUnitSummary } from './UnknownUnitSummary'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, values?: Record<string, unknown>) => (
      key === 'unitErrors.line'
        ? `${values?.['count']} rows use unknown unit '${values?.['unit']}' — map it in Settings → Units or correct the file`
        : key
    ),
  }),
}))

describe('UnknownUnitSummary', () => {
  it('renders one aggregated operator action per distinct source text', () => {
    render(<UnknownUnitSummary summary={{
      unknown_units: [
        { text: 'pcs', count: 3, accepted: ['kg', 'pc'] },
        { text: 'piece', count: 856, accepted: ['kg', 'pc'] },
      ],
    }} />)

    expect(screen.getByText("3 rows use unknown unit 'pcs' — map it in Settings → Units or correct the file")).toBeInTheDocument()
    expect(screen.getByText("856 rows use unknown unit 'piece' — map it in Settings → Units or correct the file")).toBeInTheDocument()
  })

  it('renders nothing when there are no unknown units', () => {
    const { container } = render(<UnknownUnitSummary summary={{ unknown_units: [] }} />)

    expect(container).toBeEmptyDOMElement()
  })
})
