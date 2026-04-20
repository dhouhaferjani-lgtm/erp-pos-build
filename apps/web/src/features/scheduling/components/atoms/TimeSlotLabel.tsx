import { textColors } from '@/lib/designTokens'

interface TimeSlotLabelProps {
  start: string
  end: string
  /** Display mode — "full" includes the date, "time" just HH:MM–HH:MM. */
  mode?: 'time' | 'full'
}

function pad(n: number): string {
  return n < 10 ? `0${String(n)}` : String(n)
}

function formatHM(iso: string): string {
  const d = new Date(iso)
  return `${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function formatFull(iso: string): string {
  const d = new Date(iso)
  return `${String(d.getFullYear())}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/**
 * Formats a start/end ISO pair as `HH:MM – HH:MM` (or full date-time when
 * requested). Atom: no state, no data fetching.
 *
 * Uses the browser's local timezone — the backend stores timestamps as
 * `TIMESTAMPTZ` and the company timezone is applied at response serialisation
 * time by `CompanyContext`, so by the time values reach this atom they
 * already render correctly in the user's locale.
 */
export function TimeSlotLabel({ start, end, mode = 'time' }: TimeSlotLabelProps) {
  const formatter = mode === 'full' ? formatFull : formatHM
  return (
    <span className={`inline-flex items-center gap-1 text-sm ${textColors.secondary}`}>
      <span className="font-medium">{formatter(start)}</span>
      <span aria-hidden="true">–</span>
      <span className="font-medium">{formatter(end)}</span>
    </span>
  )
}
