import { useState } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { AppointmentStatusBadge } from '../components/atoms/AppointmentStatusBadge'
import { AppointmentSourceBadge } from '../components/atoms/AppointmentSourceBadge'
import { AppointmentTypeChip } from '../components/atoms/AppointmentTypeChip'
import { TimeSlotLabel } from '../components/atoms/TimeSlotLabel'
import {
  useAppointment,
  useCancelAppointment,
  useCheckInAppointment,
  useConfirmAppointment,
  useConvertAppointment,
} from '../hooks/useScheduling'

/**
 * Read + action page for a single appointment.
 *
 * Page-level orchestration: composes atoms + owns transition mutations via
 * hooks. Most transition logic lives in the backend's transition endpoints;
 * this page just exposes the right buttons and surfaces any 422 error.
 */
export function AppointmentDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { t } = useTranslation('scheduling')

  const appt = useAppointment(id)
  const confirm = useConfirmAppointment(id ?? '')
  const checkIn = useCheckInAppointment(id ?? '')
  const cancel = useCancelAppointment(id ?? '')
  const convert = useConvertAppointment(id ?? '')

  const [cancelReason, setCancelReason] = useState<string>('')
  const [actionError, setActionError] = useState<string | null>(null)

  if (appt.isLoading) {
    return <p className={`p-6 text-sm ${textColors.tertiary}`}>{t('scheduler.loading')}</p>
  }
  if (appt.isError || !appt.data) {
    return (
      <div className={`m-6 ${tokens.alert.base} ${tokens.alert.error}`} role="alert">
        {t('detail.notFound')}
      </div>
    )
  }

  const a = appt.data

  const onConfirm = (): void => {
    setActionError(null)
    confirm.mutate(undefined, {
      onError: () => { setActionError(t('errors.invalidTransition')) },
    })
  }

  const onCheckIn = (): void => {
    setActionError(null)
    checkIn.mutate({}, {
      onError: () => { setActionError(t('errors.invalidTransition')) },
    })
  }

  const onConvert = (): void => {
    setActionError(null)
    convert.mutate(undefined, {
      onSuccess: (result) => {
        void navigate(`/workshop/work-orders/${result.work_order_id}`)
      },
      onError: () => { setActionError(t('errors.notConvertible')) },
    })
  }

  const onCancel = (): void => {
    setActionError(null)
    cancel.mutate(
      { reason_code: cancelReason === '' ? null : cancelReason },
      { onError: () => { setActionError(t('errors.invalidTransition')) } },
    )
  }

  return (
    <div className="flex flex-col gap-6 p-4 sm:p-6">
      <header className="flex flex-col gap-2">
        <button
          type="button"
          onClick={() => { void navigate('/scheduling') }}
          className={`${tokens.button.base} ${tokens.button.ghost} ${tokens.button.sizes.sm} self-start`}
        >
          ← {t('actions.back')}
        </button>
        <div className="flex flex-wrap items-center gap-3">
          <h1 className={`text-2xl font-bold ${textColors.primary}`}>
            {a.appointment_number}
          </h1>
          <AppointmentStatusBadge status={a.status} />
          <AppointmentSourceBadge source={a.source} />
          <AppointmentTypeChip type={a.appointment_type} />
        </div>
      </header>

      {actionError !== null ? (
        <div className={`${tokens.alert.base} ${tokens.alert.error}`} role="alert">
          {actionError}
        </div>
      ) : null}

      <section className={`${tokens.card.base} flex flex-col gap-3`}>
        <h2 className={`text-sm font-semibold ${textColors.primary}`}>
          {t('drawer.sectionSchedule')}
        </h2>
        <TimeSlotLabel start={a.scheduled_start} end={a.scheduled_end} mode="full" />
        <dl className="grid grid-cols-1 gap-2 md:grid-cols-2">
          <div>
            <dt className={`text-xs ${textColors.tertiary}`}>{t('fields.estimatedDuration')}</dt>
            <dd className={`text-sm ${textColors.secondary}`}>{a.estimated_duration_minutes}</dd>
          </div>
          <div>
            <dt className={`text-xs ${textColors.tertiary}`}>{t('fields.waitType')}</dt>
            <dd className={`text-sm ${textColors.secondary}`}>{t(`waitType.${a.wait_type}`)}</dd>
          </div>
        </dl>
      </section>

      <section className={`${tokens.card.base} flex flex-col gap-2`}>
        <h2 className={`text-sm font-semibold ${textColors.primary}`}>
          {t('drawer.sectionCustomer')}
        </h2>
        <p className={`text-sm ${textColors.secondary}`}>
          {a.customer_name ?? '—'} · {a.customer_phone ?? '—'}
        </p>
      </section>

      <section className={`${tokens.card.base} flex flex-col gap-2`}>
        <h2 className={`text-sm font-semibold ${textColors.primary}`}>
          {t('drawer.sectionVehicle')}
        </h2>
        <p className={`text-sm ${textColors.secondary}`}>
          {a.vehicle_plate ?? '—'} · {a.vehicle_description ?? '—'}
        </p>
      </section>

      {a.services_summary !== null ? (
        <section className={`${tokens.card.base} flex flex-col gap-2`}>
          <h2 className={`text-sm font-semibold ${textColors.primary}`}>
            {t('detail.plannedServicesTitle')}
          </h2>
          <p className={`text-sm ${textColors.secondary}`}>{a.services_summary}</p>
        </section>
      ) : null}

      {a.work_order_id !== null ? (
        <section className={`${tokens.card.base} flex flex-col gap-2`}>
          <h2 className={`text-sm font-semibold ${textColors.primary}`}>
            {t('detail.convertedTo')}
          </h2>
          <button
            type="button"
            onClick={() => { void navigate(`/workshop/work-orders/${a.work_order_id ?? ''}`) }}
            className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm} self-start`}
          >
            {a.work_order_id}
          </button>
        </section>
      ) : null}

      <section className={`${tokens.card.base} flex flex-col gap-3`}>
        <h2 className={`text-sm font-semibold ${textColors.primary}`}>{t('detail.timeline')}</h2>
        <div className="flex flex-wrap gap-2">
          {a.status === 'scheduled' ? (
            <button
              type="button"
              disabled={confirm.isPending}
              onClick={onConfirm}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {t('actions.confirm')}
            </button>
          ) : null}
          {(a.status === 'scheduled' || a.status === 'confirmed') ? (
            <button
              type="button"
              disabled={checkIn.isPending}
              onClick={onCheckIn}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {t('actions.checkIn')}
            </button>
          ) : null}
          {(a.status === 'confirmed' || a.status === 'checked_in') && a.work_order_id === null ? (
            <button
              type="button"
              disabled={convert.isPending}
              onClick={onConvert}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {t('actions.convert')}
            </button>
          ) : null}
          {a.status !== 'cancelled' && a.status !== 'completed' && a.status !== 'closed' ? (
            <div className="flex items-center gap-2">
              <input
                type="text"
                className={`${tokens.input.base} w-48`}
                placeholder={t('detail.reasonPlaceholder')}
                value={cancelReason}
                onChange={(e) => { setCancelReason(e.target.value) }}
              />
              <button
                type="button"
                disabled={cancel.isPending}
                onClick={onCancel}
                className={`${tokens.button.base} ${tokens.button.danger} ${tokens.button.sizes.sm}`}
              >
                {t('actions.cancel')}
              </button>
            </div>
          ) : null}
        </div>
      </section>
    </div>
  )
}
