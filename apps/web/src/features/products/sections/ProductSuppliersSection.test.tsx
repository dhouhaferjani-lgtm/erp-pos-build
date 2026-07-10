import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { ProductSuppliersSection } from './ProductSuppliersSection'
import type { ProductSectionMode } from './types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('ProductSuppliersSection', () => {
  it.each<ProductSectionMode>(['view', 'edit'])(
    'renders the same managed-in-Purchases section in %s mode',
    (mode) => {
      const { container } = render(
        <MemoryRouter>
          <ProductSuppliersSection adapter={{ mode }} />
        </MemoryRouter>,
      )

      expect(container.querySelectorAll('#section-suppliers')).toHaveLength(1)
      expect(screen.getByText('catalog:editor.suppliers.managedHint')).toBeInTheDocument()
      expect(screen.getByRole('link', { name: 'catalog:editor.suppliers.manageLink' }))
        .toHaveAttribute('href', '/purchases/suppliers')
      expect(container.querySelector('input, select, textarea')).toBeNull()
    },
  )
})
