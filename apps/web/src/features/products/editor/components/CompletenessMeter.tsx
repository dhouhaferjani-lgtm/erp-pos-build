import React from 'react'
import { useTranslation } from 'react-i18next'
import { colors, textColors } from '@/lib/designTokens'

interface CompletenessMeterProps {
  percent: number
}

/**
 * CompletenessMeter — "Completeness N%" label + slim bar at the bottom of the
 * SectionNav card. Matches the mock:
 * - Row: "Completeness" 12px gray (gray-500) + "N%" 700 GREEN.
 * - Bar: 6px tall, fully rounded, track gray-100, fill brand GREEN.
 *
 * Brand green = `--theme-success` (#1F8A5B). It's read via the theme CSS var
 * (`bg-[var(--theme-success)]`) so it stays theme-driven rather than a
 * hardcoded hex; no semantic green token resolves to this exact brand value.
 */
export function CompletenessMeter({ percent }: CompletenessMeterProps): React.JSX.Element {
  const { t } = useTranslation('catalog')

  const clamped = Math.min(100, Math.max(0, Math.round(percent)))

  return (
    <div className="px-3 pt-1.5">
      {/* Label row */}
      <div className="mb-1.5 flex items-center justify-between text-xs">
        <span className={textColors.tertiary}>{t('editor.complete')}</span>
        {/* Brand green via theme var (see component doc) */}
        <span className="font-bold text-[var(--theme-success)]">{clamped}%</span>
      </div>

      {/* Bar track */}
      <div className={`h-1.5 w-full overflow-hidden rounded-full ${colors.neutral[100]}`}>
        {/* Bar fill — brand green via theme var */}
        <div
          data-testid="completeness-fill"
          className="h-full rounded-full bg-[var(--theme-success)] transition-all duration-300"
          style={{ width: `${clamped}%` }}
        />
      </div>
    </div>
  )
}
