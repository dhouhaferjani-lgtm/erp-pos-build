import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { useTechnician } from '../hooks/useTechnicians'
import { SkillLevelBadge } from '../components/SkillLevelBadge'
import { SpecialtyChip } from '../components/SpecialtyChip'
import { EmploymentStatusBadge } from '../components/EmploymentStatusBadge'
import { WeeklyScheduleView } from '../components/WeeklyScheduleView'

export function TechnicianDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { t } = useTranslation('workshop-technicians')
  const { data, isLoading, isError, error } = useTechnician(id)

  if (isLoading) {
    return <div className="p-6 text-sm text-slate-500">{t('team.loading')}</div>
  }
  if (isError || !data) {
    return (
      <div className="p-6">
        <div
          role="alert"
          className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
        >
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
          className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
          {t('detail.backToTeam')}
        </Link>
      </div>

      <header className="rounded-lg border border-slate-200 bg-white p-5">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-slate-900">{data.user_display_name}</h1>
          <SkillLevelBadge level={data.skill_level} />
          <EmploymentStatusBadge status={data.employment_status} />
          {!data.is_active ? (
            <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-600">
              {t('labels.inactive')}
            </span>
          ) : null}
        </div>
        {data.user_email !== null ? (
          <p className="mt-1 text-sm text-slate-600">{data.user_email}</p>
        ) : null}
        {data.specialties.length > 0 ? (
          <div className="mt-3 flex flex-wrap gap-1.5">
            {data.specialties.map((s) => (
              <SpecialtyChip key={s} code={s} />
            ))}
          </div>
        ) : null}
      </header>

      <section aria-labelledby="schedule-heading" className="space-y-3">
        <h2 id="schedule-heading" className="text-sm font-semibold text-slate-900">
          {t('detail.weeklySchedule')}
        </h2>
        <WeeklyScheduleView schedule={data.weekly_schedule} />
      </section>

      <section aria-labelledby="employment-heading" className="space-y-3">
        <h2 id="employment-heading" className="text-sm font-semibold text-slate-900">
          {t('detail.employment')}
        </h2>
        <dl className="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2">
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">
              {t('fields.hireDate')}
            </dt>
            <dd className="mt-0.5 text-sm text-slate-800">
              {data.hire_date ?? t('labels.notSet')}
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">
              {t('fields.employeeCode')}
            </dt>
            <dd className="mt-0.5 text-sm text-slate-800">
              {data.employee_code ?? t('labels.notSet')}
            </dd>
          </div>
        </dl>
      </section>

      {showPay ? (
        <section aria-labelledby="pay-heading" className="space-y-3">
          <h2 id="pay-heading" className="text-sm font-semibold text-slate-900">
            {t('detail.pay')}
          </h2>
          <dl className="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2">
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">
                {t('fields.hourlyCostRate')}
              </dt>
              <dd className="mt-0.5 text-sm text-slate-800">
                {data.hourly_cost_rate ?? t('labels.notSet')} {data.currency}
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">
                {t('fields.hourlyBillingRate')}
              </dt>
              <dd className="mt-0.5 text-sm text-slate-800">
                {data.hourly_billing_rate ?? t('labels.notSet')} {data.currency}
              </dd>
            </div>
          </dl>
        </section>
      ) : null}

      {showPii ? (
        <section aria-labelledby="pii-heading" className="space-y-3">
          <h2 id="pii-heading" className="text-sm font-semibold text-slate-900">
            {t('detail.personal')}
          </h2>
          <dl className="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-slate-200 bg-white p-4 sm:grid-cols-2">
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">
                {t('fields.nationalId')}
              </dt>
              <dd className="mt-0.5 text-sm text-slate-800">
                {data.national_id ?? t('labels.notSet')}
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">
                {t('fields.personalPhone')}
              </dt>
              <dd className="mt-0.5 text-sm text-slate-800">
                {data.personal_phone ?? t('labels.notSet')}
              </dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-xs uppercase tracking-wide text-slate-500">
                {t('fields.personalAddress')}
              </dt>
              <dd className="mt-0.5 text-sm text-slate-800">
                {data.personal_address ?? t('labels.notSet')}
              </dd>
            </div>
          </dl>
        </section>
      ) : null}

      {data.notes !== null && data.notes.length > 0 ? (
        <section aria-labelledby="notes-heading" className="space-y-3">
          <h2 id="notes-heading" className="text-sm font-semibold text-slate-900">
            {t('fields.notes')}
          </h2>
          <p className="whitespace-pre-wrap rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700">
            {data.notes}
          </p>
        </section>
      ) : null}
    </div>
  )
}
