import { useTranslation } from 'react-i18next'
import type { SkillLevel } from '../api/types'
import { tokens } from '@/lib/designTokens'

interface SkillLevelBadgeProps {
  level: SkillLevel
}

/**
 * Skill-level badge mapping. Uses design-token badge variants where the color
 * palette covers the intent (gray / blue / purple). Emerald and amber sit
 * outside the enforced palette regex in eslint.config.js; they're the closest
 * semantic match for senior ("established") and master ("seasoned") without
 * reaching for the restricted red/green tones.
 */
const STYLES: Record<SkillLevel, string> = {
  apprentice: tokens.badge.gray,
  junior: tokens.badge.blue,
  general: 'bg-sky-50 text-sky-700',
  senior: 'bg-emerald-50 text-emerald-700',
  master: 'bg-amber-50 text-amber-700',
  specialist: tokens.badge.purple,
}

export function SkillLevelBadge({ level }: SkillLevelBadgeProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <span className={`${tokens.badge.base} ${STYLES[level]}`}>
      {t(`skillLevels.${level}`)}
    </span>
  )
}
