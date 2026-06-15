import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Users } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { PageHeader } from '@/components/molecules'
import { Select } from '@/components/atoms'
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

/** Type guard matching a SpecialtyCode without a type assertion. */
function isSpecialtyCode(value: string): value is SpecialtyCode {
  return (SPECIALTY_FILTERS as string[]).includes(value)
}

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
      <PageHeader title={t('team.title')} subtitle={t('team.subtitle')} />

      <div
        className={cn(
          'flex flex-wrap items-center gap-4 rounded-lg border bg-white p-4',
          borderColors.light,
        )}
      >
        <label className={cn('inline-flex items-center gap-2 text-sm', textColors.secondary)}>
          <input
            type="checkbox"
            checked={activeOnly}
            onChange={(e) => {
              setActiveOnly(e.target.checked)
            }}
            className={tokens.checkbox.base}
          />
          {t('filters.activeOnly')}
        </label>
        <label className={cn('inline-flex items-center gap-2 text-sm', textColors.secondary)}>
          <span>{t('filters.specialty')}</span>
          <Select
            value={specialty}
            onChange={(e) => {
              const value = e.target.value
              setSpecialty(value === '' || isSpecialtyCode(value) ? value : '')
            }}
            className="mt-0 w-auto"
          >
            <option value="">{t('filters.anySpecialty')}</option>
            {SPECIALTY_FILTERS.map((s) => (
              <option key={s} value={s}>
                {t(`specialties.${s}`)}
              </option>
            ))}
          </Select>
        </label>
      </div>

      {isLoading ? (
        <div
          className={cn(
            'rounded-lg border bg-white p-6 text-sm',
            borderColors.light,
            textColors.tertiary,
          )}
        >
          {t('team.loading')}
        </div>
      ) : isError ? (
        <div role="alert" className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('team.errorLoading')}: {error instanceof Error ? error.message : String(error)}
        </div>
      ) : data && data.length > 0 ? (
        <div className="space-y-2">
          {data.map((p) => (
            <TechnicianRow key={p.id} profile={p} />
          ))}
        </div>
      ) : (
        <div
          className={cn(
            'rounded-lg border border-dashed bg-white p-12 text-center',
            borderColors.default,
          )}
        >
          <Users className={cn('mx-auto h-10 w-10', textColors.disabled)} aria-hidden />
          <h3 className={cn('mt-3 text-sm font-semibold', textColors.primary)}>
            {t('team.empty.title')}
          </h3>
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>{t('team.empty.body')}</p>
        </div>
      )}
    </div>
  )
}
