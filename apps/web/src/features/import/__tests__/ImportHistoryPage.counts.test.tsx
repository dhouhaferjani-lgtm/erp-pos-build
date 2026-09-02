import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { ImportHistoryPage } from '../pages/ImportHistoryPage'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, options?: { count?: number }) => `${key}${options?.count == null ? '' : ` ${String(options.count)}`}` }),
}))
vi.mock('../api/queries', () => ({
  useImportJobs: () => ({
    isLoading: false,
    data: {
      data: [{
        id: 'job-1', type: 'products', status: 'completed', original_filename: 'products.csv',
        total_rows: 10, processed_rows: 10, successful_rows: 6, skipped_rows: 3, failed_rows: 1,
        warning_rows: 0, warning_summary: null, error_summary: { unknown_units: [] }, progress_percentage: 100,
        options: null, error_message: null, started_at: null, completed_at: null, created_at: '2026-09-01T00:00:00Z',
      }],
    },
  }),
}))
vi.mock('../api/importApi', () => ({ importApi: { downloadResultWorkbookUrl: vi.fn(), downloadFailedRowsUrl: vi.fn() } }))
vi.mock('@/lib/api', () => ({ authenticatedDownload: vi.fn() }))

describe('ImportHistoryPage counts', () => {
  it('labels imported, skipped, failed, and total independently', () => {
    render(<MemoryRouter><ImportHistoryPage /></MemoryRouter>)

    expect(screen.getByTestId('import-history-count-imported-job-1')).toHaveTextContent('6')
    expect(screen.getByTestId('import-history-count-skipped-job-1')).toHaveTextContent('3')
    expect(screen.getByTestId('import-history-count-failed-job-1')).toHaveTextContent('1')
    expect(screen.getByTestId('import-history-count-total-job-1')).toHaveTextContent('10')
  })
})
