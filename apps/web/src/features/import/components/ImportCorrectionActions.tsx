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

export function ImportCorrectionActions({ jobId, type }: { jobId: string; type: ImportType }) {
  const { t } = useTranslation('import')
  const formatId = useId()
  const [format, setFormat] = useState<'csv' | 'xlsx'>('csv')

  const download = async (url: string, filename: string) => {
    try {
      await authenticatedDownload(url, filename)
    } catch (error) {
      toast.error(t(axios.isAxiosError(error) && error.response?.status === 404 ? 'correction.noRows' : 'correction.downloadError'))
    }
  }

  return (
    <div className="flex max-w-sm flex-col gap-2 text-start">
      <div className="flex flex-wrap items-center gap-2">
        <label className="sr-only" htmlFor={formatId}>{t('correction.format')}</label>
        <Select id={formatId} value={format} onChange={(event) => { setFormat(event.target.value === 'xlsx' ? 'xlsx' : 'csv') }} className={`rounded border ${colors.border.default} ${colors.surface.base} px-2 py-1 text-sm`}>
          <option value="csv">{t('correction.csv')}</option>
          <option value="xlsx">{t('correction.xlsx')}</option>
        </Select>
        <Button data-testid="import-complete-download-rows_export_csv" type="button" onClick={() => void download(importApi.downloadFailedRowsUrl(jobId, format), `import-${jobId}-rows-to-fix.${format}`)} className={`inline-flex items-center gap-2 rounded px-3 py-2 text-sm font-medium ${colors.intent.primary.bgStrong} ${colors.text.inverse}`}>
          <Download className="h-4 w-4" />
          {t('correction.download')}
        </Button>
      </div>
      <p className={`whitespace-normal text-xs ${colors.text.subtle}`}>{t('correction.caveat')}</p>
      <div className="flex flex-wrap gap-3 text-sm">
        <Link to={`/settings/import/${type}?reimport_of=${jobId}`} className={colors.intent.primary.text}>{t('correction.reupload')}</Link>
        <Button variant="ghost" size="sm" data-testid="import-complete-download-workbook" type="button" onClick={() => void download(importApi.downloadResultWorkbookUrl(jobId), `import-${jobId}-result.xlsx`)} className={colors.text.subtle}>{t('correction.fullReport')}</Button>
      </div>
    </div>
  )
}
