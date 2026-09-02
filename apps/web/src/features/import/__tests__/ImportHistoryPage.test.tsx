import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { ImportHistoryPage } from '../pages/ImportHistoryPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

vi.mock('../api/queries', () => ({
  useImportJobs: () => ({
    isLoading: false,
    data: {
      data: [{
        id: 'job-1',
        type: 'products',
        status: 'completed',
        original_filename: 'products.csv',
        total_rows: 2,
        processed_rows: 2,
        successful_rows: 2,
        skipped_rows: 0,
        failed_rows: 0,
        warning_rows: 0,
        warning_summary: { enriched: 1, enrichment_not_found: 1, enrichment_barcode_missing: 2 },
        progress_percentage: 100,
        options: { enrichment_enabled: true },
        error_message: null,
        started_at: '2026-08-29T10:00:00Z',
        completed_at: '2026-08-29T10:00:01Z',
        created_at: '2026-08-29T09:59:59Z',
      }],
    },
  }),
}))

describe('ImportHistoryPage enrichment summary', () => {
  it('renders durable enrichment success and warning counts', () => {
    render(<MemoryRouter><ImportHistoryPage /></MemoryRouter>)

    expect(screen.getByText('history.enriched')).toBeInTheDocument()
    expect(screen.getByText('warnings.enrichment_not_found')).toBeInTheDocument()
    expect(screen.getByText('warnings.enrichment_barcode_missing')).toBeInTheDocument()
    expect(screen.queryByText('warnings.enriched')).not.toBeInTheDocument()
  })
})
