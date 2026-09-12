import { useId, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { toast } from 'sonner'
import { Button } from '@/components/atoms/Button/Button'
import { Select } from '@/components/atoms/Select/Select'
import { Download } from 'lucide-react'
import { authenticatedDownload } from '@/lib/api'
import { semanticColorTokens as colors } from '@/lib/designTokens'
import { importApi } from '../api/importApi'
import type { ImportType } from '../types'

/**
 * `failed-rows.{format}` answers 404 with TWO different codes and the result
 * workbook with a third set, so branching on the status alone tells a purged or
 * foreign job "there are no rows to fix", which is false. The body carries the
 * code; under `responseType: 'blob'` it has to be read out of the Blob.
 */
const ROWS_ERROR_KEYS: Record<string, string> = {
  no_rows_to_fix: 'correction.noRows',
  import_not_found: 'correction.importNotFound',
  correction_export_unavailable: 'correction.downloadError',
}

const REPORT_ERROR_KEYS: Record<string, string> = {
  import_not_found: 'correction.importNotFound',
}

/** jsdom's Blob has no `text()`, so the FileReader path keeps this testable. */
function readBlob(blob: Blob): Promise<string> {
  const text = (blob as { text?: () => Promise<string> }).text
  if (typeof text === 'function') {
    return text.call(blob)
  }

  return new Promise<string>((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => { resolve(typeof reader.result === 'string' ? reader.result : '') }
    reader.onerror = () => { reject(reader.error ?? new Error('Unable to read the error body.')) }
    reader.readAsText(blob)
  })
}

async function codedError(error: unknown): Promise<string | null> {
  if (!axios.isAxiosError(error)) {
    return null
  }
  const body: unknown = error.response?.data
  if (!(body instanceof Blob)) {
    return null
  }
  try {
    const parsed: unknown = JSON.parse(await readBlob(body))
    const code = (parsed as { error?: { code?: unknown } }).error?.code

    return typeof code === 'string' ? code : null
  } catch {
    return null
  }
}

interface ImportCorrectionActionsProps {
  jobId: string
  type: ImportType
  /**
   * Mirrors `ImportRowExportService::generate()`'s row predicate. When nothing
   * matches it the endpoint answers a guaranteed 404, so the control is hidden
   * rather than shipped dead (owner ruling OQ-11).
   */
  canDownloadRows: boolean
}

export function ImportCorrectionActions({ jobId, type, canDownloadRows }: ImportCorrectionActionsProps) {
  const { t } = useTranslation('import')
  const formatId = useId()
  const [format, setFormat] = useState<'csv' | 'xlsx'>('csv')

  const download = async (
    url: string,
    filename: string,
    keys: Record<string, string>,
    fallback: string,
  ) => {
    try {
      await authenticatedDownload(url, filename)
    } catch (error) {
      const code = await codedError(error)
      const key = code !== null ? keys[code] : undefined
      toast.error(t(key ?? fallback))
    }
  }

  return (
    <div className="flex max-w-sm flex-col gap-2 text-start">
      {canDownloadRows && (
        <div className="flex flex-wrap items-center gap-2">
          <label className="sr-only" htmlFor={formatId}>{t('correction.format')}</label>
          <Select id={formatId} value={format} onChange={(event) => { setFormat(event.target.value === 'xlsx' ? 'xlsx' : 'csv') }} className={`rounded border ${colors.border.default} ${colors.surface.base} px-2 py-1 text-sm`}>
            <option value="csv">{t('correction.csv')}</option>
            <option value="xlsx">{t('correction.xlsx')}</option>
          </Select>
          <Button
            data-testid="import-complete-download-rows_export_csv"
            type="button"
            onClick={() => void download(
              importApi.downloadFailedRowsUrl(jobId, format),
              `import-${jobId}-rows-to-fix.${format}`,
              ROWS_ERROR_KEYS,
              'correction.downloadError',
            )}
            className="gap-2"
          >
            <Download className="h-4 w-4" />
            {t('correction.download')}
          </Button>
        </div>
      )}
      {canDownloadRows && <p className={`whitespace-normal text-xs ${colors.text.subtle}`}>{t('correction.caveat')}</p>}
      <div className="flex flex-wrap gap-3 text-sm">
        <Link to={`/settings/import/${type}?reimport_of=${jobId}`} className={colors.intent.primary.text}>{t('correction.reupload')}</Link>
        <Button
          variant="ghost"
          size="sm"
          data-testid="import-complete-download-workbook"
          type="button"
          onClick={() => void download(
            importApi.downloadResultWorkbookUrl(jobId),
            `import-${jobId}-result.xlsx`,
            REPORT_ERROR_KEYS,
            'correction.reportUnavailable',
          )}
        >
          {t('correction.fullReport')}
        </Button>
      </div>
    </div>
  )
}
