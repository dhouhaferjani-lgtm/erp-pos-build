import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
import { PageHeader } from '@/components/molecules'
import { StatusBadge } from '@/components/atoms'
import { useTechnician } from '../hooks/useTechnicians'
import { SkillLevelBadge } from '../components/SkillLevelBadge'
import { SpecialtyChip } from '../components/SpecialtyChip'
import { EmploymentStatusBadge } from '../components/EmploymentStatusBadge'
import { WeeklyScheduleView } from '../components/WeeklyScheduleView'
import { CertificationsTab } from '../components/CertificationsTab'
import { TimeOffTab } from '../components/TimeOffTab'
import { TimeEntriesTab } from '../components/TimeEntriesTab'

type AuthoringTab = 'overview' | 'certifications' | 'timeOff' | 'timeEntries'

export function TechnicianDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation('workshop-technicians')
  const { data, isLoading, isError, error } = useTechnician(id)
  const [activeTab, setActiveTab] = useState<AuthoringTab>('overview')

  if (isLoading) {
    return <div className={cn('p-6 text-sm', textColors.tertiary)}>{t('team.loading')}</div>
  }
  if (isError || !data) {
    return (
      <div className="p-6">
        <div role="alert" className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('team.errorLoading')}: {error instanceof Error ? error.message : t('detail.notFound')}
        </div>
      </div>
    )
  }

  const showPay =
    data.hourly_billing_rate !== undefined || data.hourly_cost_rate !== undefined
  const showPii =
    data.national_id !== undefined ||
    data.personal_address !== undefined ||
    data.personal_phone !== undefined

  return (
    <div className="space-y-6 p-6">
      <div className="flex items-center gap-3">
        <Link
          to="/workshop/technicians"
          className={cn(
            'inline-flex items-center gap-1 text-sm',
            textColors.tertiary,
            textColors.hoverPrimary,
          )}
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          {t('detail.backToTeam')}
        </Link>
      </div>

      <header className={cn('rounded-lg border bg-white p-5', borderColors.light)}>
        <PageHeader
          title={data.user_display_name}
          actions={
            <>
              <SkillLevelBadge level={data.skill_level} />
              <EmploymentStatusBadge status={data.employment_status} />
              {!data.is_active ? (
                <StatusBadge tone="neutral" className="uppercase">
                  {t('labels.inactive')}
                </StatusBadge>
              ) : null}
            </>
          }
          className="mb-0"
        />
        {data.user_email !== null ? (
          <p className={cn('mt-1 text-sm', textColors.tertiary)}>{data.user_email}</p>
        ) : null}
        {data.specialties.length > 0 ? (
          <div className="mt-3 flex flex-wrap gap-1.5">
            {data.specialties.map((s) => (
              <SpecialtyChip key={s} code={s} />
            ))}
          </div>
        ) : null}
      </header>

      <nav aria-label={t('authoring.tabsAriaLabel')} className={cn('border-b', borderColors.light)}>
        <ul className="-mb-px flex gap-4 text-sm">
          {(['overview', 'certifications', 'timeOff', 'timeEntries'] as const).map((tab) => (
            <li key={tab}>
              <button
                type="button"
                onClick={() => {
                  setActiveTab(tab)
                }}
                className={
                  activeTab === tab
                    ? cn(
                        'border-b-2 px-1 py-2 font-medium',
                        borderColors.dark,
                        textColors.primary,
                      )
                    : cn('px-1 py-2', textColors.tertiary, textColors.hoverPrimary)
                }
              >
                {t(`authoring.tabs.${tab}`)}
              </button>
            </li>
          ))}
        </ul>
      </nav>

      {activeTab === 'certifications' ? (
        <CertificationsTab technicianId={data.id} />
      ) : activeTab === 'timeOff' ? (
        <TimeOffTab technicianId={data.id} />
      ) : activeTab === 'timeEntries' ? (
        <TimeEntriesTab technicianId={data.id} />
      ) : (
        <OverviewSections data={data} showPay={showPay} showPii={showPii} />
      )}
    </div>
  )
}

interface OverviewSectionsProps {
  data: ReturnType<typeof useTechnician>['data']
  showPay: boolean
  showPii: boolean
}

/** Section `<h2>` treatment shared by every overview panel. */
const sectionHeadingClass = cn('text-sm font-semibold', textColors.primary)
/** Card-style `<dl>` grid shared by the employment/pay/PII panels. */
const detailGridClass = cn(
  'grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border bg-white p-4 sm:grid-cols-2',
  borderColors.light,
)
/** `<dt>` label treatment. */
const dtClass = cn('text-xs uppercase tracking-wide', textColors.tertiary)
/** `<dd>` value treatment. */
const ddClass = cn('mt-0.5 text-sm', textColors.secondary)

function OverviewSections({ data, showPay, showPii }: OverviewSectionsProps) {
  const { t } = useTranslation('workshop-technicians')
  if (data === undefined) return null
  return (
    <>
      <section aria-labelledby="schedule-heading" className="space-y-3">
        <h2 id="schedule-heading" className={sectionHeadingClass}>
          {t('detail.weeklySchedule')}
        </h2>
        <WeeklyScheduleView schedule={data.weekly_schedule} />
      </section>

      <section aria-labelledby="employment-heading" className="space-y-3">
        <h2 id="employment-heading" className={sectionHeadingClass}>
          {t('detail.employment')}
        </h2>
        <dl className={detailGridClass}>
          <div>
            <dt className={dtClass}>{t('fields.hireDate')}</dt>
            <dd className={ddClass}>{data.hire_date ?? t('labels.notSet')}</dd>
          </div>
          <div>
            <dt className={dtClass}>{t('fields.employeeCode')}</dt>
            <dd className={ddClass}>{data.employee_code ?? t('labels.notSet')}</dd>
          </div>
        </dl>
      </section>

      {showPay ? (
        <section aria-labelledby="pay-heading" className="space-y-3">
          <h2 id="pay-heading" className={sectionHeadingClass}>
            {t('detail.pay')}
          </h2>
          <dl className={detailGridClass}>
            <div>
              <dt className={dtClass}>{t('fields.hourlyCostRate')}</dt>
              <dd className={cn(ddClass, 'tabular-nums')}>
                {data.hourly_cost_rate ?? t('labels.notSet')} {data.currency}
              </dd>
            </div>
            <div>
              <dt className={dtClass}>{t('fields.hourlyBillingRate')}</dt>
              <dd className={cn(ddClass, 'tabular-nums')}>
                {data.hourly_billing_rate ?? t('labels.notSet')} {data.currency}
              </dd>
            </div>
          </dl>
        </section>
      ) : null}

      {showPii ? (
        <section aria-labelledby="pii-heading" className="space-y-3">
          <h2 id="pii-heading" className={sectionHeadingClass}>
            {t('detail.personal')}
          </h2>
          <dl className={detailGridClass}>
            <div>
              <dt className={dtClass}>{t('fields.nationalId')}</dt>
              <dd className={ddClass}>{data.national_id ?? t('labels.notSet')}</dd>
            </div>
            <div>
              <dt className={dtClass}>{t('fields.personalPhone')}</dt>
              <dd className={ddClass}>{data.personal_phone ?? t('labels.notSet')}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className={dtClass}>{t('fields.personalAddress')}</dt>
              <dd className={ddClass}>{data.personal_address ?? t('labels.notSet')}</dd>
            </div>
          </dl>
        </section>
      ) : null}

      {data.notes !== null && data.notes.length > 0 ? (
        <section aria-labelledby="notes-heading" className="space-y-3">
          <h2 id="notes-heading" className={sectionHeadingClass}>
            {t('fields.notes')}
          </h2>
          <p
            className={cn(
              'whitespace-pre-wrap rounded-lg border bg-white p-4 text-sm',
              borderColors.light,
              textColors.secondary,
            )}
          >
            {data.notes}
          </p>
        </section>
      ) : null}
    </>
  )
}
