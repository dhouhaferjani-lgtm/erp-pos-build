import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { ImportHistoryPage } from '../pages/ImportHistoryPage'
import type { ImportJob } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const jobs: ImportJob[] = []
const listParams = vi.fn()
let meta = { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null as number | null, to: null as number | null }

vi.mock('../api/queries', () => ({
  useImportJobs: (params: unknown) => {
    listParams(params)

    return { isLoading: false, data: { data: jobs, meta: { ...meta, total: jobs.length } } }
  },
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

describe('ImportHistoryPage server-side status filter and pagination', () => {
  it('asks the server for the selected status instead of filtering the first page', async () => {
    listParams.mockClear()
    renderWith(job({}))

    await userEvent.click(screen.getByRole('button', { name: 'status.partially_completed' }))

    expect(listParams).toHaveBeenLastCalledWith(
      expect.objectContaining({ status: 'partially_completed', page: 1 }),
    )
  })

  it('renders the server pagination when there is more than one page', () => {
    meta = { current_page: 1, last_page: 3, per_page: 20, total: 50, from: 1, to: 20 }
    renderWith(job({}))
    meta = { current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null }

    // The server's own meta drives the control; there is no client-side slice.
    expect(screen.getByRole('button', { name: 'pagination.next' })).toBeInTheDocument()
    expect(screen.getByText('pagination.page 1 pagination.of 3')).toBeInTheDocument()
  })
})

describe('ImportHistoryPage one main element per screen', () => {
  it('states the correction caveat once for the table, not once per row', () => {
    renderWith(
      job({ id: 'a', failed_rows: 1 }),
      job({ id: 'b', failed_rows: 2 }),
    )

    expect(screen.getAllByText('correction.caveat')).toHaveLength(1)
    // The row action itself keeps its accessible name, so the operator (and the
    // browser gate) can still reach it.
    expect(screen.getAllByRole('button', { name: 'correction.download' })).toHaveLength(2)
  })
})

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
