import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { FieldComparisonRow } from '../components/FieldComparisonRow'

describe('FieldComparisonRow', () => {
  it('renders user and enriched values', () => {
    render(
      <FieldComparisonRow
        label="Name"
        userValue="User"
        enrichedValue="Enriched"
        checked={false}
        onToggle={() => {}}
      />,
    )
    expect(screen.getByText('User')).toBeInTheDocument()
    expect(screen.getByText('Enriched')).toBeInTheDocument()
  })

  it('renders dash for null values', () => {
    render(
      <FieldComparisonRow
        label="X"
        userValue={null}
        enrichedValue={null}
        checked={false}
        onToggle={() => {}}
      />,
    )
    expect(screen.getAllByText('—')).toHaveLength(2)
  })

  it('calls onToggle on checkbox click', () => {
    const fn = vi.fn()
    render(
      <FieldComparisonRow
        label="X"
        userValue="A"
        enrichedValue="B"
        checked={false}
        onToggle={fn}
      />,
    )
    fireEvent.click(screen.getByRole('checkbox'))
    expect(fn).toHaveBeenCalledOnce()
  })

  it('checkbox reflects checked state', () => {
    render(
      <FieldComparisonRow
        label="X"
        userValue="A"
        enrichedValue="B"
        checked={true}
        onToggle={() => {}}
      />,
    )
    expect(screen.getByRole('checkbox')).toBeChecked()
  })
})
