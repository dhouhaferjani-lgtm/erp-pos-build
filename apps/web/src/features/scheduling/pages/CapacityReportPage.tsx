import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { UtilizationBar } from '../components/atoms/UtilizationBar'
import { BayBadge } from '../components/atoms/BayBadge'
import { useBays, useDayView } from '../hooks/useScheduling'
import type { BookedEntryDTO } from '../types'

function formatDate(d: Date): string {
  const pad = (n: number): string => (n < 10 ? `0${String(n)}` : String(n))
  return `${String(d.getFullYear())}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

interface BayUtilization {
  id: string
  name: string
  code: string
  bookedMinutes: number
  availableMinutes: number
  utilizationPercent: number
}

function computeMinutes(entries: BookedEntryDTO[]): number {
  return entries.reduce((sum, e) => {
    const start = new Date(e.start).getTime()
    const end = new Date(e.end).getTime()
    const minutes = Math.max(0, Math.round((end - start) / 60000))
    return sum + minutes
  }, 0)
}

function computeAvailableMinutes(operatingHours: Record<string, { start: string; end: string }[]>, date: Date): number {
  const dayKeys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const
  const key = dayKeys[date.getDay()]
  const windows = operatingHours[key] ?? []
  return windows.reduce((sum, w) => {
    const startParts = w.start.split(':')
    const endParts = w.end.split(':')
    if (startParts.length < 2 || endParts.length < 2) return sum
    const sh = Number(startParts[0])
    const sm = Number(startParts[1])
    const eh = Number(endParts[0])
    const em = Number(endParts[1])
    if (Number.isNaN(sh) || Number.isNaN(sm) || Number.isNaN(eh) || Number.isNaN(em)) return sum
    const minutes = Math.max(0, (eh - sh) * 60 + (em - sm))
    return sum + minutes
  }, 0)
}

/**
 * Capacity report — per-bay utilization for the selected day.
 *
 * Page: owns date state, composes `useBays` + `useDayView`, and renders
 * atoms (`BayBadge`, `UtilizationBar`). No data fetching is done in the
 * render tree of an atom or molecule here.
 */
export function CapacityReportPage() {
  const { t } = useTranslation('scheduling')
  const [date, setDate] = useState<string>(formatDate(new Date()))
  const bays = useBays()
  const day = useDayView(date)

  const utilization = useMemo<BayUtilization[]>(() => {
    if (!bays.data) return []
    const booked = day.data?.booked ?? {}
    const target = new Date(date)
    return bays.data
      .filter((b) => b.is_active)
      .map((bay): BayUtilization => {
        const bookedMinutes = computeMinutes(booked[bay.id] ?? [])
        const availableMinutes = computeAvailableMinutes(bay.operating_hours, target)
        const utilizationPercent =
          availableMinutes === 0 ? 0 : Math.round((bookedMinutes / availableMinutes) * 100)
        return {
          id: bay.id,
          name: bay.name,
          code: bay.code,
          bookedMinutes,
          availableMinutes,
          utilizationPercent,
        }
      })
  }, [bays.data, day.data, date])

  return (
    <div className="flex flex-col gap-6 p-4 sm:p-6">
      <header className="flex flex-col gap-1">
        <h1 className={`text-2xl font-bold ${textColors.primary}`}>
          {t('capacity.title')}
        </h1>
        <p className={`text-sm ${textColors.tertiary}`}>{t('capacity.subtitle')}</p>
      </header>

      <div className="flex items-center gap-3">
        <label className="flex items-center gap-2">
          <span className={`text-sm font-medium ${textColors.secondary}`}>
            {t('scheduler.viewDay')}
          </span>
          <input
            type="date"
            className={`${tokens.input.base} w-48`}
            value={date}
            onChange={(e) => { setDate(e.target.value) }}
          />
        </label>
      </div>

      {utilization.length === 0 ? (
        <p className={`text-sm ${textColors.tertiary}`}>{t('capacity.empty')}</p>
      ) : (
        <div className="flex flex-col gap-3">
          {utilization.map((row) => (
            <article key={row.id} className={`${tokens.card.base} flex flex-col gap-2`}>
              <header className="flex items-center justify-between">
                <BayBadge name={row.name} code={row.code} />
                <span className={`text-sm font-semibold ${textColors.primary}`}>
                  {String(row.utilizationPercent)}%
                </span>
              </header>
              <UtilizationBar
                percent={row.utilizationPercent}
                ariaLabel={`${row.name} ${String(row.utilizationPercent)}%`}
              />
              <dl className="flex flex-wrap gap-4 text-xs">
                <div>
                  <dt className={textColors.tertiary}>{t('capacity.bookedMinutes')}</dt>
                  <dd className={`${textColors.primary} font-medium`}>
                    {String(row.bookedMinutes)}
                  </dd>
                </div>
                <div>
                  <dt className={textColors.tertiary}>{t('capacity.availableMinutes')}</dt>
                  <dd className={`${textColors.primary} font-medium`}>
                    {String(row.availableMinutes)}
                  </dd>
                </div>
              </dl>
            </article>
          ))}
        </div>
      )}
    </div>
  )
}
