import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BeforePublishChecklist } from './BeforePublishChecklist'

// i18n is initialised globally in src/test/setup.ts (imports ../lib/i18n)

describe('BeforePublishChecklist', () => {
  it('renders a "Before publish" heading and one row per check', () => {
    render(
      <BeforePublishChecklist
        items={[
          { key: 'name', satisfied: true },
          { key: 'sku', satisfied: false },
          { key: 'salePrice', satisfied: false },
          { key: 'tax', satisfied: false },
        ]}
      />,
    )
    expect(screen.getByRole('heading', { name: /before publish/i })).toBeInTheDocument()
    expect(screen.getAllByRole('listitem')).toHaveLength(4)
  })

  it('marks satisfied rows as done and unsatisfied rows as pending', () => {
    render(
      <BeforePublishChecklist
        items={[
          { key: 'name', satisfied: true },
          { key: 'sku', satisfied: false },
        ]}
      />,
    )
    const rows = screen.getAllByRole('listitem')
    expect(rows[0]).toHaveAttribute('data-satisfied', 'true')
    expect(rows[1]).toHaveAttribute('data-satisfied', 'false')
  })
})
