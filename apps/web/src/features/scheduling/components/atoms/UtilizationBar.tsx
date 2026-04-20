import { textColors, tokens } from '@/lib/designTokens'

interface UtilizationBarProps {
  /** Value between 0 and 100 inclusive; clamped if outside range. */
  percent: number
  /** Optional accessible label — defaults to the numeric value. */
  ariaLabel?: string
}

/**
 * Thin horizontal progress bar that reflects a utilization ratio.
 *
 * Colors reflect intent (see `tokens.utilizationBar`):
 * - 0–60%:  low    (under-utilized, plenty of headroom)
 * - 60–90%: medium (healthy load)
 * - >90%:   high   (overbooked / crunched)
 *
 * Atom: presentational only, no hooks or side effects.
 */
export function UtilizationBar({ percent, ariaLabel }: UtilizationBarProps) {
  const clamped = Math.max(0, Math.min(100, percent))
  let color = tokens.utilizationBar.low
  if (clamped > 90) color = tokens.utilizationBar.high
  else if (clamped >= 60) color = tokens.utilizationBar.medium

  return (
    <div
      className={`h-2 w-full overflow-hidden rounded-full ${tokens.utilizationBar.track}`}
      role="progressbar"
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={Math.round(clamped)}
      aria-label={ariaLabel ?? `${String(Math.round(clamped))}%`}
    >
      <div
        className={`h-full ${color} transition-all`}
        style={{ width: `${String(clamped)}%` }}
      />
      <span className={`sr-only ${textColors.secondary}`}>{Math.round(clamped)}%</span>
    </div>
  )
}
