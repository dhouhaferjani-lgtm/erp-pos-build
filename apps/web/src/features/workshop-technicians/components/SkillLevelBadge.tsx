import { useTranslation } from 'react-i18next'
import type { SkillLevel } from '../api/types'
import { tokens } from '@/lib/designTokens'

interface SkillLevelBadgeProps {
  level: SkillLevel
}

/**
 * Skill-level badge. Each level maps to an on-theme `tokens.badge.*` variant —
 * no off-theme palettes. Progression reads gray → blue → green for the
 * apprentice→master track, with purple reserved for the cross-cutting
 * specialist role.
 */
const STYLES: Record<SkillLevel, string> = {
  apprentice: tokens.badge.gray,
  junior: tokens.badge.blue,
  general: tokens.badge.blue,
  senior: tokens.badge.green,
  master: tokens.badge.green,
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
