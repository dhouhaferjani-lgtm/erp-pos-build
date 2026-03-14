import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useCompanyStore } from '../../../stores/companyStore'
import { exportJetXml } from '../api/complianceApi'

export function JetExportForm() {
  const { t } = useTranslation('compliance')
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleExport = async () => {
    if (!currentCompanyId || !from || !to) return
    setLoading(true)
    setError(null)
    try {
      const blob = await exportJetXml(currentCompanyId, from, to)
      const url = window.URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `jet-export-${from}-${to}.xml`
      a.click()
      window.URL.revokeObjectURL(url)
    } catch {
      setError(t('exportError'))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-6">
      <h3 className="text-lg font-semibold text-gray-900 mb-4">
        {t('jetExport.title')}
      </h3>
      <p className="text-sm text-gray-600 mb-4">
        {t('jetExport.description')}
      </p>
      <div className="flex gap-4 items-end flex-wrap">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('jetExport.from')}
          </label>
          <input
            type="date"
            value={from}
            onChange={(e) => { setFrom(e.target.value) }}
            className="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            {t('jetExport.to')}
          </label>
          <input
            type="date"
            value={to}
            onChange={(e) => { setTo(e.target.value) }}
            className="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
          />
        </div>
        <button
          type="button"
          onClick={() => { void handleExport() }}
          disabled={loading || !from || !to}
          className="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {loading ? t('jetExport.exporting') : t('jetExport.export')}
        </button>
      </div>
      {error && <p className="mt-2 text-sm text-red-600">{error}</p>}
    </div>
  )
}
