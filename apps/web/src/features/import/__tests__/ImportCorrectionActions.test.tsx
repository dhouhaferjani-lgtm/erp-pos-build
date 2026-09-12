import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ImportCorrectionActions } from '../components/ImportCorrectionActions'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const toastError = vi.fn()
vi.mock('sonner', () => ({ toast: { error: (...args: unknown[]) => { toastError(...args) } } }))

const authenticatedDownload = vi.fn()
vi.mock('@/lib/api', () => ({
  authenticatedDownload: (...args: unknown[]) => authenticatedDownload(...args) as Promise<void>,
}))

/** A coded 404 as axios delivers it under `responseType: 'blob'`. */
function codedBlob404(code: string) {
  return {
    isAxiosError: true,
    response: { status: 404, data: new Blob([JSON.stringify({ error: { code, message: code } })]) },
  }
}

function renderActions(canDownloadRows = true, layout: 'panel' | 'row' = 'panel') {
  return render(
    <MemoryRouter>
      <ImportCorrectionActions jobId="job-1" type="products" canDownloadRows={canDownloadRows} layout={layout} />
    </MemoryRouter>,
  )
}

describe('ImportCorrectionActions error mapping', () => {
  beforeEach(() => {
    toastError.mockReset()
    authenticatedDownload.mockReset()
  })

  it.each([
    ['no_rows_to_fix', 'correction.noRows'],
    ['import_not_found', 'correction.importNotFound'],
    ['correction_export_unavailable', 'correction.downloadError'],
  ])('maps the %s body code of a rows-to-fix 404 to its own sentence', async (code, key) => {
    authenticatedDownload.mockRejectedValue(codedBlob404(code))
    renderActions()

    await userEvent.click(screen.getByRole('button', { name: 'correction.download' }))

    await waitFor(() => { expect(toastError).toHaveBeenCalledWith(key) })
  })

  it('does not tell the operator there are no rows to fix when the full report is missing', async () => {
    authenticatedDownload.mockRejectedValue(codedBlob404('no_rows_to_fix'))
    renderActions()

    await userEvent.click(screen.getByRole('button', { name: 'correction.fullReport' }))

    await waitFor(() => { expect(toastError).toHaveBeenCalledWith('correction.reportUnavailable') })
    expect(toastError).not.toHaveBeenCalledWith('correction.noRows')
  })

  it('maps a full-report import_not_found to the same missing-import sentence', async () => {
    authenticatedDownload.mockRejectedValue(codedBlob404('import_not_found'))
    renderActions()

    await userEvent.click(screen.getByRole('button', { name: 'correction.fullReport' }))

    await waitFor(() => { expect(toastError).toHaveBeenCalledWith('correction.importNotFound') })
  })

  it('falls back to the generic download error for a non-404 failure', async () => {
    authenticatedDownload.mockRejectedValue({ isAxiosError: true, response: { status: 500, data: new Blob(['<html>']) } })
    renderActions()

    await userEvent.click(screen.getByRole('button', { name: 'correction.download' }))

    await waitFor(() => { expect(toastError).toHaveBeenCalledWith('correction.downloadError') })
  })
})

describe('ImportCorrectionActions dead-control gating', () => {
  it('hides the rows-to-fix control when the job has nothing to fix, keeping the full report', () => {
    renderActions(false)

    expect(screen.queryByRole('button', { name: 'correction.download' })).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox', { name: 'correction.format' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'correction.fullReport' })).toBeInTheDocument()
  })
})

describe('ImportCorrectionActions layouts', () => {
  it('states the caveat on the wizard completion panel, where it appears once', () => {
    renderActions(true, 'panel')

    expect(screen.getByText('correction.caveat')).toBeInTheDocument()
  })

  it('omits the caveat on a history row, where the table states it once above', () => {
    renderActions(true, 'row')

    expect(screen.queryByText('correction.caveat')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'correction.download' })).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'correction.format' })).toBeInTheDocument()
  })
})
