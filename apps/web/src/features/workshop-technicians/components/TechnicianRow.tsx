import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { StatusBadge } from '@/components/atoms'
import type { TechnicianProfile } from '../api/types'
import { SkillLevelBadge } from './SkillLevelBadge'
import { SpecialtyChip } from './SpecialtyChip'
import { EmploymentStatusBadge } from './EmploymentStatusBadge'

interface TechnicianRowProps {
  profile: TechnicianProfile
}

export function TechnicianRow({ profile }: TechnicianRowProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <Link
      to={`/workshop/technicians/${profile.id}`}
      className={cn(
        'flex flex-col gap-2 rounded-lg border bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between',
        borderColors.light,
        tokens.card.hoverPrimary,
        tokens.card.hover,
      )}
    >
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className={cn('truncate text-sm font-semibold', textColors.primary)}>
            {profile.user_display_name}
          </span>
          <SkillLevelBadge level={profile.skill_level} />
          <EmploymentStatusBadge status={profile.employment_status} />
          {!profile.is_active ? (
            <StatusBadge tone="neutral" className="uppercase">
              {t('labels.inactive')}
            </StatusBadge>
          ) : null}
        </div>
        {profile.user_email !== null ? (
          <div className={cn('mt-0.5 truncate text-xs', textColors.tertiary)}>
            {profile.user_email}
          </div>
        ) : null}
        {profile.specialties.length > 0 ? (
          <div className="mt-2 flex flex-wrap gap-1.5">
            {profile.specialties.map((s) => (
              <SpecialtyChip key={s} code={s} />
            ))}
          </div>
        ) : null}
      </div>
      {profile.hourly_billing_rate !== undefined && profile.hourly_billing_rate !== null ? (
        <div className={cn('text-right text-xs', textColors.tertiary)}>
          <div className={cn('font-medium tabular-nums', textColors.secondary)}>
            {profile.hourly_billing_rate} {profile.currency}
          </div>
          <div>{t('labels.hourlyBillingRate')}</div>
        </div>
      ) : null}
    </Link>
  )
}
