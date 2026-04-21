import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Download } from 'lucide-react'
import { api } from '@/lib/api'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { useTechnicians } from '../hooks/useTechnicians'

function defaultPeriodStart(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

function defaultPeriodEnd(): string {
  const d = new Date()
  const last = new Date(d.getFullYear(), d.getMonth() + 1, 0)
  return `${last.getFullYear()}-${String(last.getMonth() + 1).padStart(2, '0')}-${String(last.getDate()).padStart(2, '0')}`
}

/**
 * Payroll export page — generate a CSV for a selected pay period and
 * (optionally) a filtered set of technicians. The backend streams CSV
 * which we consume via responseType: 'blob' and trigger a download.
 */
export function PayrollExportPage() {
  const { t } = useTranslation('workshop-technicians')
  const { data: technicians } = useTechnicians({ active_only: true })

  const [start, setStart] = useState<string>(defaultPeriodStart())
  const [end, setEnd] = useState<string>(defaultPeriodEnd())
  const [selectedIds, setSelectedIds] = useState<string[]>([])
  const [isGenerating, setIsGenerating] = useState(false)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  function toggleTech(id: string): void {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
    )
  }

  async function handleGenerate(): Promise<void> {
    setErrorMessage(null)
    setIsGenerating(true)
    try {
      const payload: Record<string, unknown> = {
        pay_period_start: start,
        pay_period_end: end,
      }
      if (selectedIds.length > 0) {
        payload['technician_ids'] = selectedIds
      }
      const response = await api.post('/workshop/payroll-exports', payload, {
        responseType: 'blob',
      })
      const blob = new Blob([response.data as BlobPart], { type: 'text/csv' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `payroll-${start}-to-${end}.csv`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(url)
    } catch {
      setErrorMessage(t('authoring.errors.generic'))
    } finally {
      setIsGenerating(false)
    }
  }

  return (
    <div className="space-y-6 p-6">
      <header className={`rounded-lg border ${borderColors.light} bg-white p-5`}>
        <h1 className={`text-2xl font-semibold ${textColors.primary}`}>
          {t('navigation.payrollExports')}
        </h1>
        <p className={`mt-1 text-sm ${textColors.tertiary}`}>
          {t('authoring.timeEntries.title')}
        </p>
      </header>

      <section className="space-y-4">
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={tokens.label.base}>
              {t('authoring.timeOff.fields.startsAt')}
            </label>
            <input
              type="date"
              className={tokens.input.base}
              value={start}
              onChange={(e) => {
                setStart(e.target.value)
              }}
            />
          </div>
          <div>
            <label className={tokens.label.base}>
              {t('authoring.timeOff.fields.endsAt')}
            </label>
            <input
              type="date"
              className={tokens.input.base}
              value={end}
              onChange={(e) => {
                setEnd(e.target.value)
              }}
            />
          </div>
        </div>

        {technicians !== undefined && technicians.length > 0 ? (
          <fieldset
            className={`rounded-lg border ${borderColors.light} bg-white p-4`}
          >
            <legend className={`px-1 text-sm font-medium ${textColors.primary}`}>
              {t('team.title')}
            </legend>
            <p className={`mb-2 text-xs ${textColors.tertiary}`}>
              {selectedIds.length === 0
                ? t('filters.anySpecialty')
                : `${selectedIds.length} / ${technicians.length}`}
            </p>
            <ul className="max-h-48 space-y-1 overflow-y-auto">
              {technicians.map((tech) => (
                <li key={tech.id}>
                  <label className="flex items-center gap-2 text-sm">
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(tech.id)}
                      onChange={() => {
                        toggleTech(tech.id)
                      }}
                    />
                    <span>{tech.user_display_name}</span>
                    {tech.employee_code !== null ? (
                      <span className={`text-xs ${textColors.tertiary}`}>
                        ({tech.employee_code})
                      </span>
                    ) : null}
                  </label>
                </li>
              ))}
            </ul>
          </fieldset>
        ) : null}

        {errorMessage !== null ? (
          <div className={`${tokens.alert.base} ${tokens.alert.error}`}>{errorMessage}</div>
        ) : null}

        <div className="flex items-center justify-end">
          <button
            type="button"
            onClick={() => {
              void handleGenerate()
            }}
            disabled={isGenerating}
            className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md} inline-flex items-center gap-2`}
            data-testid="payroll-generate-button"
          >
            <Download className="h-4 w-4" />
            {isGenerating
              ? t('authoring.certifications.modal.saving')
              : t('navigation.payrollExports')}
          </button>
        </div>
      </section>
    </div>
  )
}
