import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { StatementUploadWizard } from './StatementUploadWizard'
import type { StatementPreview, StatementProfile } from './api'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const profile: StatementProfile = {
  id: 'profile-1',
  payment_repository_id: 'repo-1',
  name: 'BIAT CSV',
  is_active: true,
  parser_key: 'csv',
  column_map: { value_date: 'Date', amount: 'Amount', label: 'Label' },
  date_format: 'd/m/Y',
  decimal_format: 'comma_decimal',
  direction_convention: 'signed_amount',
  header_rows: 1,
  matching_window_days: 5,
}

const preview: StatementPreview = {
  preview_token: 'preview-token',
  source_file_sha256: 'abc',
  preview_lines: [{
    line_number: 3,
    value_date: '2026-07-02',
    booking_date: null,
    direction: 'in',
    amount: '125.500',
    reference: 'VIR-42',
    bank_transaction_id: null,
    label: 'Customer transfer',
    counterparty_hint: null,
  }],
  accepted_line_count: 3,
  duplicate_fingerprint_count: 2,
  dropped_zero_amount_rows: 1,
  unparseable_rows: [{ row: 7, reason: 'Invalid date' }],
  detected_opening: '100.000',
  detected_closing: '225.500',
}

describe('StatementUploadWizard', () => {
  const onPreview = vi.fn(async () => preview)
  const onConfirm = vi.fn(async () => ({ statementId: 'statement-1' }))

  beforeEach(() => {
    onPreview.mockClear()
    onConfirm.mockClear()
  })

  it('moves repository → file → profile and renders the complete preview report', async () => {
    render(
      <StatementUploadWizard
        repositories={[{ id: 'repo-1', name: 'BIAT TND', currency: 'TND' }]}
        profiles={[profile]}
        onPreview={onPreview}
        onConfirm={onConfirm}
        onImported={vi.fn()}
      />,
    )

    fireEvent.change(screen.getByLabelText('statements.upload.repository'), { target: { value: 'repo-1' } })
    fireEvent.click(screen.getByRole('button', { name: 'statements.upload.next' }))

    const file = new File(['Date;Amount\n02/07/2026;125,500'], 'biat.csv', { type: 'text/csv' })
    fireEvent.change(screen.getByLabelText('statements.upload.file'), { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'statements.upload.next' }))

    fireEvent.change(screen.getByLabelText('statements.upload.profile'), { target: { value: 'profile-1' } })
    fireEvent.click(screen.getByRole('button', { name: 'statements.upload.preview' }))

    await screen.findByText('Customer transfer')
    expect(screen.getByText('statements.upload.report.accepted')).toBeInTheDocument()
    expect(screen.getByText('statements.upload.report.duplicates')).toBeInTheDocument()
    expect(screen.getByText('statements.upload.report.droppedZero')).toBeInTheDocument()
    expect(screen.getByText('statements.upload.report.unparseable')).toBeInTheDocument()
    expect(screen.getByText(/Invalid date/)).toBeInTheDocument()
    expect(onPreview).toHaveBeenCalledWith({ repositoryId: 'repo-1', profileId: 'profile-1', file })
  })

  it('requires an explicit empty-import acknowledgment before confirm', async () => {
    onPreview.mockResolvedValueOnce({ ...preview, accepted_line_count: 0 })
    render(
      <StatementUploadWizard
        repositories={[{ id: 'repo-1', name: 'BIAT TND', currency: 'TND' }]}
        profiles={[profile]}
        onPreview={onPreview}
        onConfirm={onConfirm}
        onImported={vi.fn()}
      />,
    )

    fireEvent.change(screen.getByLabelText('statements.upload.repository'), { target: { value: 'repo-1' } })
    fireEvent.click(screen.getByRole('button', { name: 'statements.upload.next' }))
    fireEvent.change(screen.getByLabelText('statements.upload.file'), {
      target: { files: [new File(['x'], 'empty.csv', { type: 'text/csv' })] },
    })
    fireEvent.click(screen.getByRole('button', { name: 'statements.upload.next' }))
    fireEvent.change(screen.getByLabelText('statements.upload.profile'), { target: { value: 'profile-1' } })
    fireEvent.click(screen.getByRole('button', { name: 'statements.upload.preview' }))

    const confirm = await screen.findByRole('button', { name: 'statements.upload.confirm' })
    fireEvent.change(screen.getByLabelText('statements.upload.periodStart'), { target: { value: '2026-07-01' } })
    fireEvent.change(screen.getByLabelText('statements.upload.periodEnd'), { target: { value: '2026-07-31' } })
    expect(confirm).toBeDisabled()

    const emptyGate = screen.getByRole('group', { name: 'statements.upload.emptyGate' })
    fireEvent.click(within(emptyGate).getByRole('checkbox'))
    expect(confirm).toBeEnabled()
    fireEvent.click(confirm)

    await waitFor(() => expect(onConfirm).toHaveBeenCalledWith(expect.objectContaining({
      previewToken: 'preview-token',
      repositoryId: 'repo-1',
      acknowledgeEmpty: true,
    })))
  })
})
