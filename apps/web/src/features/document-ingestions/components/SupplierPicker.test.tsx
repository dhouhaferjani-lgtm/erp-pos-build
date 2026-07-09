import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { SupplierCandidate } from '../types'
import { SupplierPicker } from './SupplierPicker'

describe('SupplierPicker', () => {
  it('shows a new-supplier hint when there are no matching candidates', () => {
    render(
      <SupplierPicker candidates={[]} value="" onChange={() => {}} onCreateSupplier={() => {}} />,
    )

    const hint = screen.getByTestId('new-supplier-hint')
    expect(hint).toBeInTheDocument()
    expect(hint).toHaveTextContent('No match found — this looks like a new supplier. Use + to create it.')
  })

  it('does not show the new-supplier hint when candidates are present', () => {
    const candidates: SupplierCandidate[] = [{ id: 's1', name: 'ACME', vat: null, score: '1' }]

    render(
      <SupplierPicker candidates={candidates} value="" onChange={() => {}} onCreateSupplier={() => {}} />,
    )

    expect(screen.queryByTestId('new-supplier-hint')).not.toBeInTheDocument()
  })
})
