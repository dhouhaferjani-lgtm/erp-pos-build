import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { ColumnMapper } from './ColumnMapper'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) => {
      if (key === 'mapping.skippedColumnsNotice') {
        return `Skipped columns: ${String(options?.['columns'])}`
      }
      return key
    },
  }),
}))

describe('ColumnMapper', () => {
  it('does not mark unsupported suggestions as mapped', () => {
    render(
      <ColumnMapper
        sourceColumns={['address']}
        targetColumns={[
          { name: 'name', required: true },
          { name: 'type', required: true },
        ]}
        suggestions={{ address: 'address' }}
        mapping={{}}
        onMappingChange={vi.fn()}
      />,
    )

    expect(screen.getByRole('combobox')).toHaveValue('')
    expect(screen.queryByText('mapping.suggested')).not.toBeInTheDocument()
    expect(screen.queryByTestId('mapped-status-address')).not.toBeInTheDocument()
    expect(screen.getByTestId('skipped-status-address')).toBeInTheDocument()
  })

  it('shows a skipped-column notice for unmapped source columns', () => {
    render(
      <ColumnMapper
        sourceColumns={['name', 'city']}
        targetColumns={[
          { name: 'name', required: true },
          { name: 'type', required: true },
          { name: 'city', required: false },
        ]}
        suggestions={{ name: 'name' }}
        mapping={{ name: 'name' }}
        onMappingChange={vi.fn()}
      />,
    )

    expect(screen.getByText('Skipped columns: city')).toBeInTheDocument()
  })
})
