import React from 'react'
import { useTranslation } from 'react-i18next'
import { colors, textColors } from '@/lib/designTokens'

interface CompletenessMeterProps {
  percent: number
}

/**
 * CompletenessMeter — slim progress bar + "complete N%" label shown at the
 * bottom of the SectionNav rail.
 *
 * Visual rules (Direction A):
 * - Track: bg-gray-200  → colors.neutral[200]
 * - Fill:  bg-secondary-500 (ONE orange brand accent — theme-bridged, brief-mandated)
 * - Label: mono percent, textColors.secondary for the value
 */
export function CompletenessMeter({ percent }: CompletenessMeterProps): React.JSX.Element {
  const { t } = useTranslation('catalog')

  const clamped = Math.min(100, Math.max(0, Math.round(percent)))

  return (
    <div className="mt-auto pt-4">
      {/* Bar track */}
      <div className={`h-1 w-full overflow-hidden rounded-full ${colors.neutral[200]}`}>
        {/* Bar fill — ONE orange accent (theme-bridged secondary-500) */}
        <div
          data-testid="completeness-fill"
          className="h-full rounded-full bg-secondary-500 transition-all duration-300"
          style={{ width: `${clamped}%` }}
        />
      </div>

      {/* Label */}
      <p className={`mt-1.5 text-[11px] ${textColors.tertiary}`}>
        <span>{t('editor.complete')}</span>{' '}
        <span className={`font-mono ${textColors.secondary}`}>{clamped}%</span>
      </p>
    </div>
  )
}
