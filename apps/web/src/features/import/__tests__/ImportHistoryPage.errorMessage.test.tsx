import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ImportHistoryPage } from '../pages/ImportHistoryPage'
import { ValidationResults } from '../components/ValidationResults'
import { GlobalImportProgress } from '@/components/organisms/GlobalImportProgress/GlobalImportProgress'
import { useImportProgressStore } from '@/stores/importProgressStore'
import type { ImportErrorSummary, ImportJob } from '../types'

vi.mock('react-i18next', () => ({
  // Interpolation is echoed for the one job-scoped key that carries data, so a
  // test can prove the missing-column list actually reaches the sentence.
  useTranslation: () => ({
    t: (key: string, options?: Record<string, unknown>) =>
      options !== undefined && typeof options['columns'] === 'string'
        ? `${key}:${options['columns']}`
        : key,
  }),
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

    // Job scope: the catalogue's errors.internal_error is row copy ("The row …"),
    // so a whole-file failure reads the job-scoped sentence for the same code.
    expect(screen.getByRole('alert')).toHaveTextContent('errors.job.internal_error')
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

function errorSummary(overrides: Partial<ImportErrorSummary> = {}): ImportErrorSummary {
  return {
    total_errors: 1,
    validation_errors: 1,
    execution_errors: 0,
    has_errors: true,
    job_error_message: RAW_SERVER_MESSAGE,
    job_error_code: 'internal_error',
    job_error_detail: null,
    error_summary: { unknown_units: [] },
    ...overrides,
  }
}

describe('ValidationResults job-level failure', () => {
  it('renders the coded job sentence and never the raw server message', () => {
    render(<ValidationResults summary={errorSummary()} totalRows={2} />)

    expect(screen.getByRole('alert')).toHaveTextContent('errors.job.internal_error')
    expect(document.body.textContent).not.toContain('SQLSTATE')
    expect(document.body.textContent).not.toContain('ImportService.php')
  })

  it('interpolates the missing-column list a header failure carries', () => {
    render(
      <ValidationResults
        summary={errorSummary({
          job_error_code: 'validation_failed',
          job_error_detail: { missing_columns: ['name', 'sku'] },
          job_error_message: 'Missing required columns: name, sku',
        })}
        totalRows={2}
      />,
    )

    expect(screen.getByRole('alert')).toHaveTextContent('errors.job.missing_columns:name, sku')
  })
})

describe('GlobalImportProgress completion card', () => {
  beforeEach(() => {
    useImportProgressStore.setState({ activeImports: new Map(), completedImportIds: new Set() })
  })

  function complete(overrides: { error_message?: string; error_code?: App.Modules.Import.Domain.Enums.ImportErrorCode }) {
    useImportProgressStore.getState().completeImport({
      import_job_id: 'job-widget',
      status: 'failed',
      total_rows: 2,
      successful_rows: 0,
      failed_rows: 2,
      import_type: 'products',
      original_filename: 'products.csv',
      completed_at: '2026-09-12T10:00:01Z',
      is_success: false,
      is_partial_success: false,
      ...overrides,
    })
  }

  it('renders a translated sentence, never the raw broadcast text', () => {
    complete({ error_message: RAW_SERVER_MESSAGE })
    render(<GlobalImportProgress />)

    expect(document.body.textContent).not.toContain('SQLSTATE')
    expect(document.body.textContent).not.toContain('ImportService.php')
    expect(document.body.textContent).toContain('errors.unknown')
  })

  it('renders the coded sentence when the feeder carried a code', () => {
    complete({ error_code: 'internal_error', error_message: RAW_SERVER_MESSAGE })
    render(<GlobalImportProgress />)

    expect(document.body.textContent).toContain('errors.job.internal_error')
    expect(document.body.textContent).not.toContain('SQLSTATE')
  })
})

describe('ImportHistoryPage job-scoped copy', () => {
  it('uses the job-scoped sentence and the missing-column list', () => {
    renderWith(
      job({
        error_code: 'validation_failed',
        error_detail: { missing_columns: ['name', 'sku'] },
        error_message: 'Missing required columns: name, sku',
      }),
    )

    expect(screen.getByRole('alert')).toHaveTextContent('errors.job.missing_columns:name, sku')
  })
})
