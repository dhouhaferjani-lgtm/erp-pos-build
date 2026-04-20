import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Users } from 'lucide-react'
import { useTechnicians } from '../hooks/useTechnicians'
import type { SpecialtyCode, TechnicianListFilters } from '../api/types'
import { TechnicianRow } from '../components/TechnicianRow'

const SPECIALTY_FILTERS: SpecialtyCode[] = [
  'engine_mechanical',
  'engine_diagnostic',
  'transmission',
  'electrical',
  'electronic',
  'suspension',
  'brakes',
  'ac_climate',
  'tires',
  'alignment',
  'bodywork',
  'paint',
  'hybrid_ev',
  'diesel',
  'pre_control',
  'general_service',
]

export function TeamListPage() {
  const { t } = useTranslation('workshop-technicians')
  const [activeOnly, setActiveOnly] = useState(true)
  const [specialty, setSpecialty] = useState<SpecialtyCode | ''>('')

  const filters = useMemo<TechnicianListFilters>(() => {
    const f: TechnicianListFilters = {}
    if (activeOnly) f.active_only = true
    if (specialty) f.specialty = specialty
    return f
  }, [activeOnly, specialty])

  const { data, isLoading, isError, error } = useTechnicians(filters)

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold text-slate-900">
            <Users className="h-6 w-6 text-slate-500" aria-hidden />
            {t('team.title')}
          </h1>
          <p className="mt-1 text-sm text-slate-600">{t('team.subtitle')}</p>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-4 rounded-lg border border-slate-200 bg-white p-4">
        <label className="inline-flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            checked={activeOnly}
            onChange={(e) => {
              setActiveOnly(e.target.checked)
            }}
            className="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500"
          />
          {t('filters.activeOnly')}
        </label>
        <label className="inline-flex items-center gap-2 text-sm text-slate-700">
          <span>{t('filters.specialty')}</span>
          <select
            value={specialty}
            onChange={(e) => {
              setSpecialty(e.target.value as SpecialtyCode | '')
            }}
            className="rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500"
          >
            <option value="">{t('filters.anySpecialty')}</option>
            {SPECIALTY_FILTERS.map((s) => (
              <option key={s} value={s}>
                {t(`specialties.${s}`)}
              </option>
            ))}
          </select>
        </label>
      </div>

      {isLoading ? (
        <div className="rounded-lg border border-slate-200 bg-white p-6 text-sm text-slate-500">
          {t('team.loading')}
        </div>
      ) : isError ? (
        <div
          role="alert"
          className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
        >
          {t('team.errorLoading')}: {error instanceof Error ? error.message : String(error)}
        </div>
      ) : data && data.length > 0 ? (
        <div className="space-y-2">
          {data.map((p) => (
            <TechnicianRow key={p.id} profile={p} />
          ))}
        </div>
      ) : (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white p-12 text-center">
          <Users className="mx-auto h-10 w-10 text-slate-300" aria-hidden />
          <h3 className="mt-3 text-sm font-semibold text-slate-900">{t('team.empty.title')}</h3>
          <p className="mt-1 text-sm text-slate-500">{t('team.empty.body')}</p>
        </div>
      )}
    </div>
  )
}
