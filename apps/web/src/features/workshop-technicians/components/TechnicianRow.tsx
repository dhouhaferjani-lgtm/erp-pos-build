import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
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
      className="flex flex-col gap-2 rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition-colors hover:border-sky-300 hover:bg-sky-50/40 sm:flex-row sm:items-center sm:justify-between"
    >
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="truncate text-sm font-semibold text-slate-900">
            {profile.user_display_name}
          </span>
          <SkillLevelBadge level={profile.skill_level} />
          <EmploymentStatusBadge status={profile.employment_status} />
          {!profile.is_active ? (
            <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600">
              {t('labels.inactive')}
            </span>
          ) : null}
        </div>
        {profile.user_email !== null ? (
          <div className="mt-0.5 truncate text-xs text-slate-500">{profile.user_email}</div>
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
        <div className="text-right text-xs text-slate-500">
          <div className="font-medium text-slate-700">
            {profile.hourly_billing_rate} {profile.currency}
          </div>
          <div>{t('labels.hourlyBillingRate')}</div>
        </div>
      ) : null}
    </Link>
  )
}
