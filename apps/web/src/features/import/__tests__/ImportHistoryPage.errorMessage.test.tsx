import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'

import { ImportHistoryPage } from '../pages/ImportHistoryPage'
import type { ImportJob } from '../types'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const RAW_SERVER_MESSAGE =
  'Job failed: SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "products_sku_company_unique" DETAIL: Key (sku, company_id)=(SKU-1, 018f) already exists. (Connection: tenant, SQL: insert into "products" ...) in /var/www/app/Modules/Import/Services/ImportService.php:318'

function job(overrides: Partial<ImportJob>): ImportJob {
  return {
    id: 'job-1',
    type: 'products',
    status: 'failed',
    original_filename: 'products.csv',
    total_rows: 2,
    processed_rows: 2,
    successful_rows: 0,
    skipped_rows: 0,
    failed_rows: 2,
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

const jobs: ImportJob[] = []

vi.mock('../api/queries', () => ({
  useImportJobs: () => ({ isLoading: false, data: { data: jobs } }),
}))

function renderWith(...rows: ImportJob[]) {
  jobs.splice(0, jobs.length, ...rows)

  return render(<MemoryRouter><ImportHistoryPage /></MemoryRouter>)
}

describe('ImportHistoryPage operator error message', () => {
  it('renders the translated error code and never the raw server message', () => {
    renderWith(job({ error_code: 'internal_error', error_message: RAW_SERVER_MESSAGE }))

    expect(screen.getByRole('alert')).toHaveTextContent('errors.internal_error')
    expect(screen.queryByText(/SQLSTATE/)).not.toBeInTheDocument()
    expect(screen.queryByText(/ImportService\.php/)).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain('SQLSTATE')
  })

  it('falls back to a generic sentence when the code is absent or unknown', () => {
    renderWith(
      job({ id: 'job-legacy', error_code: null, error_message: RAW_SERVER_MESSAGE }),
      job({ id: 'job-future', error_code: 'not_a_real_code' as ImportJob['error_code'], error_message: 'boom' }),
    )

    const alerts = screen.getAllByRole('alert')
    expect(alerts).toHaveLength(2)
    for (const alert of alerts) expect(alert).toHaveTextContent('errors.unknown')
    expect(document.body.textContent).not.toContain('SQLSTATE')
    expect(document.body.textContent).not.toContain('boom')
  })

  it('renders no alert when the job carries neither a code nor a message', () => {
    renderWith(job({ status: 'completed', successful_rows: 2, failed_rows: 0 }))

    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})
