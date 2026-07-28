import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { BatchPreview } from './BatchPreview'
import type { PostPreview } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

describe('BatchPreview', () => {
  it('formats inventory quantities with each mapped products served scale', () => {
    const preview: PostPreview = {
      batch: {
        cutover_date: '2026-07-01',
        description: 'Precision Preview',
        is_historical: true,
      },
      lines: [{
        row_number: 1,
        product_sku: 'SKU-1',
        product_name: 'Precision product',
        location_name: 'Warehouse',
        quantity: '1.25',
        quantity_decimals: 3,
        unit_cost: '2.000',
        line_value: '2.500',
      }],
      totals: {
        total_lines: 1,
        total_quantity: '1.2500',
        total_value: '2.500',
      },
    }

    render(<BatchPreview preview={preview} batchType="INVENTORY" />)

    expect(screen.getByText('1.250')).toBeInTheDocument()
  })
})
