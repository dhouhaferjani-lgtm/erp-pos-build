import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { ImportHistoryPage } from '../pages/ImportHistoryPage'
import type { ImportJob } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const jobs: ImportJob[] = []

vi.mock('../api/queries', () => ({
  useImportJobs: () => ({ isLoading: false, data: { data: jobs, meta: { current_page: 1, last_page: 1, per_page: 20, total: jobs.length } } }),
}))

function job(overrides: Partial<ImportJob>): ImportJob {
  return {
    id: 'job-1',
    type: 'products',
    status: 'completed',
    original_filename: 'products.csv',
    total_rows: 10,
    processed_rows: 10,
    successful_rows: 10,
    skipped_rows: 0,
    failed_rows: 0,
    warning_rows: 0,
    warning_summary: null,
    error_summary: { unknown_units: [] },
    progress_percentage: 100,
    error_code: null,
    error_message: null,
    started_at: '2026-09-12T10:00:00Z',
    completed_at: '2026-09-12T10:00:01Z',
    created_at: '2026-09-12T09:59:59Z',
    ...overrides,
  }
}

function renderWith(...rows: ImportJob[]) {
  jobs.splice(0, jobs.length, ...rows)

  return render(<MemoryRouter><ImportHistoryPage /></MemoryRouter>)
}

describe('ImportHistoryPage rows-to-fix gating', () => {
  it('renders no rows-to-fix control for a clean import, but keeps the full report', () => {
    renderWith(job({}))

    expect(screen.queryByRole('button', { name: 'correction.download' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'correction.fullReport' })).toBeInTheDocument()
  })

  it('renders the rows-to-fix control when rows failed', () => {
    renderWith(job({ id: 'job-failed', status: 'partially_completed', failed_rows: 2, successful_rows: 8 }))

    expect(screen.getByRole('button', { name: 'correction.download' })).toBeInTheDocument()
  })

  it('renders the rows-to-fix control for a warning-only import, mirroring the server predicate', () => {
    renderWith(job({ id: 'job-warned', warning_rows: 3 }))

    expect(screen.getByRole('button', { name: 'correction.download' })).toBeInTheDocument()
  })
})
