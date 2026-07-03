import { describe, expect, it } from 'vitest'
import { screen } from '@testing-library/react'
import { renderWithProviders } from '@/test/renderWithProviders'
import { EntityLink } from './EntityLink'

describe('EntityLink', () => {
  it('renders a router link for an entity id', () => {
    renderWithProviders(
      <EntityLink type="document" id="invoice-1" documentType="invoice" label="INV-001" />,
    )

    const link = screen.getByRole('link', { name: 'INV-001' })
    expect(link).toHaveAttribute('href', '/sales/invoices/invoice-1')
  })

  it('renders fallback text when no id is available', () => {
    renderWithProviders(
      <EntityLink type="product" id={null} label="Uncatalogued item" />,
    )

    expect(screen.queryByRole('link', { name: 'Uncatalogued item' })).not.toBeInTheDocument()
    expect(screen.getByText('Uncatalogued item')).toBeInTheDocument()
  })
})
