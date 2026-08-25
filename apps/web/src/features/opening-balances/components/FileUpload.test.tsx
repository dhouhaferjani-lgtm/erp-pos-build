import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { Mock } from 'vitest'
import { FileUpload } from './FileUpload'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

/**
 * W4-2 / treasury gate r1 F-1.
 *
 * `repository_code` is an OPTIONAL ACCOUNTING column. It was briefly added to
 * `GL_COLUMNS`, which `FileUpload` uses as the REQUIRED-header set, so every
 * sheet written before the column existed — the four-column shape this repo
 * documented until 2026-08-24 — failed with "missing columns: repository_code"
 * and could not be uploaded at all. Nothing caught it: there was no test here.
 *
 * These cases pin both directions: a legacy four-column CSV parses and uploads,
 * and a five-column CSV carrying the new column parses and passes it through.
 */
/**
 * jsdom's File does not implement `Blob.text()`, which is what `handleFile`
 * calls. Stub it on the instance rather than polyfilling globally, so the test
 * exercises the component's real code path and nothing else.
 */
function csvFile(name: string, content: string): File {
  const file = new File([content], name, { type: 'text/csv' })
  Object.defineProperty(file, 'text', {
    value: () => Promise.resolve(content),
    writable: true,
  })
  return file
}

/**
 * The rows the component handed to `onUpload`, narrowed by a real type guard
 * rather than an `as` assertion off the mock's `any`.
 */
function uploadedRows(mock: Mock): Record<string, string>[] {
  const first: unknown = mock.mock.calls[0]?.[0]
  if (!Array.isArray(first)) {
    throw new Error('onUpload was not called with an array of rows')
  }

  return first.map((row: unknown): Record<string, string> => {
    if (typeof row !== 'object' || row === null) {
      throw new Error('onUpload row is not an object')
    }

    const cells: Record<string, string> = {}
    for (const [key, value] of Object.entries(row)) {
      cells[key] = typeof value === 'string' ? value : String(value)
    }

    return cells
  })
}

async function selectFile(file: File): Promise<void> {
  const input = document.querySelector('input[type="file"]')
  if (!(input instanceof HTMLInputElement)) {
    throw new Error('FileUpload rendered no file input')
  }
  await userEvent.upload(input, file)
}

const LEGACY_GL_CSV =
  'account_code,debit,credit,reference\n' +
  '53,1200.000,0.000,Opening cash\n' +
  '119,0.000,1200.000,Opening balance equity'

const GL_CSV_WITH_REPOSITORY =
  'account_code,debit,credit,reference,repository_code\n' +
  '53,200.000,0.000,Opening drawer float,CASH-01\n' +
  '53,1000.000,0.000,Opening safe float,SAFE-01\n' +
  '119,0.000,1200.000,Opening balance equity,'

describe('FileUpload — ACCOUNTING required vs optional columns', () => {
  it('accepts a legacy four-column CSV that carries no repository_code', async () => {
    const onUpload = vi.fn().mockResolvedValue(undefined)
    render(<FileUpload batchType="ACCOUNTING" onUpload={onUpload} isUploading={false} />)

    await selectFile(csvFile('legacy.csv', LEGACY_GL_CSV))

    await waitFor(() => {
      expect(screen.getByText('openingBalances.upload.rowsFound')).toBeInTheDocument()
    })
    expect(screen.queryByText('openingBalances.upload.errors.missingColumns')).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'openingBalances.upload.uploadButton' }))

    await waitFor(() => {
      expect(onUpload).toHaveBeenCalledTimes(1)
    })
    expect(onUpload.mock.calls[0]?.[0]).toEqual([
      { account_code: '53', debit: '1200.000', credit: '0.000', reference: 'Opening cash' },
      { account_code: '119', debit: '0.000', credit: '1200.000', reference: 'Opening balance equity' },
    ])
  })

  it('parses a CSV that does carry repository_code and passes it through', async () => {
    const onUpload = vi.fn().mockResolvedValue(undefined)
    render(<FileUpload batchType="ACCOUNTING" onUpload={onUpload} isUploading={false} />)

    await selectFile(csvFile('with-repos.csv', GL_CSV_WITH_REPOSITORY))

    await waitFor(() => {
      expect(screen.getByText('openingBalances.upload.rowsFound')).toBeInTheDocument()
    })

    await userEvent.click(screen.getByRole('button', { name: 'openingBalances.upload.uploadButton' }))

    await waitFor(() => {
      expect(onUpload).toHaveBeenCalledTimes(1)
    })
    const rows = uploadedRows(onUpload)
    expect(rows[0]?.['repository_code']).toBe('CASH-01')
    expect(rows[1]?.['repository_code']).toBe('SAFE-01')
    expect(rows[2]?.['repository_code']).toBe('')
  })

  it('still refuses a CSV missing a genuinely required column', async () => {
    const onUpload = vi.fn().mockResolvedValue(undefined)
    render(<FileUpload batchType="ACCOUNTING" onUpload={onUpload} isUploading={false} />)

    await selectFile(csvFile('broken.csv', 'account_code,debit\n53,1200.000'))

    await waitFor(() => {
      expect(screen.getByText('openingBalances.upload.errors.missingColumns')).toBeInTheDocument()
    })
    expect(onUpload).not.toHaveBeenCalled()
  })
})
