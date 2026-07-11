import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { Button, Checkbox, Input, Select, Textarea } from '@/components/atoms'
import {

  PartnerPicker,
  VehiclePicker,
  type PartnerPickerValue,
  type VehiclePickerValue,
} from '@/components/molecules/pickers'
import { ConflictAlert } from '../molecules/ConflictAlert'
import { useBays, useBookAppointment } from '../../hooks/useScheduling'
import { isAppointmentType, isWaitType } from '../../types'
import type {
  AppointmentConflictPayload,
  AppointmentType,
  BookAppointmentInput,
  ConflictDetail,
  WaitType,
} from '../../types'

interface AppointmentFormDrawerProps {
  isOpen: boolean
  locationId: string
  onClose: () => void
  onBooked?: (appointmentId: string) => void
  /** Pre-fill start/end/bay when dropped from the calendar. */
  initialStart?: string
  initialEnd?: string
  initialBayId?: string | null
}

interface FormState {
  customer_name: string
  customer_phone: string
  vehicle_plate: string
  vehicle_description: string
  appointment_type: AppointmentType
  wait_type: WaitType
  scheduled_start: string
  scheduled_end: string
  estimated_duration_minutes: number
  bay_id: string
  customer_notes: string
  internal_notes: string
  services_summary: string
}

function toDateTimeLocal(iso?: string): string {
  if (iso === undefined || iso === '') return ''
  // Trim seconds + timezone to fit `datetime-local` input format.
  const d = new Date(iso)
  const pad = (n: number): string => (n < 10 ? `0${String(n)}` : String(n))
  return `${String(d.getFullYear())}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

function fromDateTimeLocal(value: string): string {
  if (value === '') return ''
  const d = new Date(value)
  return d.toISOString()
}

function isConflictError(err: unknown): err is { response: { status: number; data: AppointmentConflictPayload } } {
  if (err === null || typeof err !== 'object') return false
  const withResponse = err as { response?: { status?: number; data?: { error_code?: string } } }
  return (
    withResponse.response?.status === 409 &&
    withResponse.response.data?.error_code === 'appointment_conflict'
  )
}

/**
 * Modal form drawer for booking a new appointment.
 *
 * Organism: owns `useBookAppointment` mutation + `useBays` query. The form
 * is minimal by design for v1 — services_summary is a free-text field, and
 * the planned_services array is wired through a single service pseudo-entry
 * so the backend's `planned_services` rule passes. Richer service pickers
 * live behind the AppointmentDetailPage (cross-module BundlePicker in the
 * follow-up patch).
 *
 * State reset on reopen is handled by keying the inner content on `isOpen`
 * (and the initial* props) — React remounts the form, letting
 * `useState(initialValue)` apply naturally without a reset effect.
 */
export function AppointmentFormDrawer(props: AppointmentFormDrawerProps) {
  if (!props.isOpen) return null
  const resetKey = `${props.initialStart ?? ''}|${props.initialEnd ?? ''}|${props.initialBayId ?? ''}`
  return <AppointmentFormDrawerContent key={resetKey} {...props} />
}

function AppointmentFormDrawerContent({
  locationId,
  onClose,
  onBooked,
  initialStart,
  initialEnd,
  initialBayId,
}: AppointmentFormDrawerProps) {
  const { t } = useTranslation(['scheduling', 'pickers'])
  const baysQuery = useBays()
  const booking = useBookAppointment()

  const [form, setForm] = useState<FormState>({
    customer_name: '',
    customer_phone: '',
    vehicle_plate: '',
    vehicle_description: '',
    appointment_type: 'standard_repair',
    wait_type: 'drop_off',
    scheduled_start: toDateTimeLocal(initialStart),
    scheduled_end: toDateTimeLocal(initialEnd),
    estimated_duration_minutes: 60,
    bay_id: initialBayId ?? '',
    customer_notes: '',
    internal_notes: '',
    services_summary: '',
  })
  const [customer, setCustomer] = useState<PartnerPickerValue | null>(null)
  const [vehicle, setVehicle] = useState<VehiclePickerValue | null>(null)
  // Walk-in mode preserves the legacy free-text name + plate path that
  // storefront / public-booking flows (see Plan D CAPTCHA) rely on.
  const [isWalkIn, setIsWalkIn] = useState(false)
  const [conflict, setConflict] = useState<ConflictDetail | null>(null)
  const [genericError, setGenericError] = useState<string | null>(null)

  const update = <K extends keyof FormState>(key: K, value: FormState[K]): void => {
    setForm((prev) => ({ ...prev, [key]: value }))
  }

  const handleCustomerChange = (next: PartnerPickerValue | null): void => {
    setCustomer(next)
    setVehicle(null)
  }

  const submit = (e: React.FormEvent<HTMLFormElement>): void => {
    e.preventDefault()
    setConflict(null)
    setGenericError(null)

    const startIso = fromDateTimeLocal(form.scheduled_start)
    const endIso = fromDateTimeLocal(form.scheduled_end)
    if (startIso === '' || endIso === '') {
      setGenericError(t('errors.invalidDate'))
      return
    }
    if (new Date(endIso) <= new Date(startIso)) {
      setGenericError(t('errors.invalidRange'))
      return
    }

    // Backend requires at least one of: customer_partner_id (picker) OR
    // customer_name (walk-in). The StoreAppointmentRequest validation
    // already enforces this server-side.
    if (!isWalkIn && customer === null) {
      setGenericError(t('errors.customerRequired', { defaultValue: 'Pick a customer or switch to walk-in mode.' }))
      return
    }
    if (isWalkIn && form.customer_name.trim() === '') {
      setGenericError(t('errors.customerNameRequired', { defaultValue: 'Customer name is required for walk-ins.' }))
      return
    }

    const input: BookAppointmentInput = {
      location_id: locationId,
      bay_id: form.bay_id === '' ? null : form.bay_id,
      customer_partner_id: isWalkIn ? null : (customer?.id ?? null),
      vehicle_id: isWalkIn ? null : (vehicle?.id ?? null),
      customer_name: isWalkIn
        ? (form.customer_name.trim() === '' ? null : form.customer_name.trim())
        : (customer?.name ?? null),
      customer_phone: form.customer_phone.trim() === '' ? null : form.customer_phone.trim(),
      vehicle_plate: isWalkIn
        ? (form.vehicle_plate.trim() === '' ? null : form.vehicle_plate.trim())
        : (vehicle?.license_plate ?? null),
      vehicle_description: isWalkIn
        ? (form.vehicle_description.trim() === '' ? null : form.vehicle_description.trim())
        : (vehicle !== null ? `${vehicle.brand} ${vehicle.model}` : null),
      appointment_type: form.appointment_type,
      wait_type: form.wait_type,
      scheduled_start: startIso,
      scheduled_end: endIso,
      estimated_duration_minutes: form.estimated_duration_minutes,
      services_summary: form.services_summary.trim() === '' ? null : form.services_summary.trim(),
      customer_notes: form.customer_notes.trim() === '' ? null : form.customer_notes.trim(),
      internal_notes: form.internal_notes.trim() === '' ? null : form.internal_notes.trim(),
      // Minimal single-entry planned service — richer picker ships in the
      // follow-up patch that wires BundlePicker cross-module.
      planned_services: [
        {
          service_ref_type: 'service',
          service_ref_id: '00000000-0000-0000-0000-000000000000',
          display_name: form.services_summary.trim() === '' ? 'Service' : form.services_summary.trim(),
          estimated_duration_minutes: form.estimated_duration_minutes,
          display_order: 0,
        },
      ],
    }

    booking.mutate(input, {
      onSuccess: (appt) => {
        if (onBooked) onBooked(appt.id)
        onClose()
      },
      onError: (err: unknown) => {
        if (isConflictError(err)) {
          setConflict(err.response.data.conflict)
          return
        }
        setGenericError(t('errors.generic'))
      },
    })
  }

  const activeBays = baysQuery.data?.filter((b) => b.is_active && b.location_id === locationId) ?? []

  return (
    <div className={tokens.modal.backdrop} role="dialog" aria-modal="true">
      <div className={`${tokens.modal.container} max-w-2xl`}>
        <header className={tokens.modal.header}>
          <h2 className={tokens.modal.title}>{t('drawer.bookTitle')}</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('actions.close')}
            className={tokens.modal.closeButton}
          >
            ×
          </button>
        </header>

        <form onSubmit={submit} className="flex flex-col gap-4">
          {conflict !== null ? <ConflictAlert conflict={conflict} /> : null}
          {genericError !== null ? (
            <div className={`${tokens.alert.base} ${tokens.alert.error}`} role="alert">
              {genericError}
            </div>
          ) : null}

          <fieldset className="flex flex-col gap-3">
            <div className="flex items-center justify-between">
              <legend className={`text-sm font-semibold ${textColors.primary}`}>
                {t('drawer.sectionCustomer')}
              </legend>
              <label className={`flex items-center gap-2 text-xs ${textColors.tertiary}`}>
                <Checkbox
                  checked={isWalkIn}
                  onChange={(e) => {
                    setIsWalkIn(e.target.checked)
                    if (e.target.checked) {
                      setCustomer(null)
                      setVehicle(null)
                    }
                  }}
                  data-testid="appointment-walkin-toggle"
                />
                {t('drawer.walkInMode', { defaultValue: 'Walk-in (free-text)' })}
              </label>
            </div>
            {isWalkIn ? (
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <label className="block">
                  <span className={tokens.label.base}>{t('fields.customerName')}</span>
                  <Input
                    type="text"
                    value={form.customer_name}
                    onChange={(e) => { update('customer_name', e.target.value) }}
                    data-testid="appointment-customer-name"
                  />
                </label>
                <label className="block">
                  <span className={tokens.label.base}>{t('fields.customerPhone')}</span>
                  <Input
                    type="tel"
                    value={form.customer_phone}
                    onChange={(e) => { update('customer_phone', e.target.value) }}
                  />
                </label>
              </div>
            ) : (
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <PartnerPicker
                  value={customer}
                  onChange={handleCustomerChange}
                  label={t('fields.customerName')}
                  partnerType="customer"
                  allowNewInline
                  testId="appointment-customer-picker"
                />
                <label className="block">
                  <span className={tokens.label.base}>{t('fields.customerPhone')}</span>
                  <Input
                    type="tel"
                    value={form.customer_phone}
                    onChange={(e) => { update('customer_phone', e.target.value) }}
                  />
                </label>
              </div>
            )}
          </fieldset>

          <fieldset className="flex flex-col gap-3">
            <legend className={`text-sm font-semibold ${textColors.primary}`}>
              {t('drawer.sectionVehicle')}
            </legend>
            {isWalkIn ? (
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <label className="block">
                  <span className={tokens.label.base}>{t('fields.vehiclePlate')}</span>
                  <Input
                    type="text"
                    value={form.vehicle_plate}
                    onChange={(e) => { update('vehicle_plate', e.target.value) }}
                    data-testid="appointment-vehicle-plate"
                  />
                </label>
                <label className="block">
                  <span className={tokens.label.base}>{t('fields.vehicleDescription')}</span>
                  <Input
                    type="text"
                    value={form.vehicle_description}
                    onChange={(e) => { update('vehicle_description', e.target.value) }}
                  />
                </label>
              </div>
            ) : (
              <VehiclePicker
                value={vehicle}
                onChange={setVehicle}
                label={t('fields.vehiclePlate')}
                disabled={customer === null}
                allowNewInline
                {...(customer !== null ? { partnerId: customer.id } : {})}
                testId="appointment-vehicle-picker"
              />
            )}
          </fieldset>

          <fieldset className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <legend className={`text-sm font-semibold ${textColors.primary}`}>
              {t('drawer.sectionSchedule')}
            </legend>
            <label className="block">
              <span className={tokens.label.base}>
                {t('fields.scheduledStart')} <span className={tokens.label.required}>*</span>
              </span>
              <Input
                type="datetime-local"
                required
                value={form.scheduled_start}
                onChange={(e) => { update('scheduled_start', e.target.value) }}
              />
            </label>
            <label className="block">
              <span className={tokens.label.base}>
                {t('fields.scheduledEnd')} <span className={tokens.label.required}>*</span>
              </span>
              <Input
                type="datetime-local"
                required
                value={form.scheduled_end}
                onChange={(e) => { update('scheduled_end', e.target.value) }}
              />
            </label>
            <label className="block">
              <span className={tokens.label.base}>
                {t('fields.estimatedDuration')} <span className={tokens.label.required}>*</span>
              </span>
              <Input
                type="number"
                min={1}
                required
                value={form.estimated_duration_minutes}
                onChange={(e) => {
                  update('estimated_duration_minutes', Number(e.target.value))
                }}
              />
            </label>
            <label className="block">
              <span className={tokens.label.base}>{t('fields.bay')}</span>
              <Select
                value={form.bay_id}
                onChange={(e) => { update('bay_id', e.target.value) }}
              >
                <option value="">—</option>
                {activeBays.map((bay) => (
                  <option key={bay.id} value={bay.id}>
                    {bay.code} · {bay.name}
                  </option>
                ))}
              </Select>
            </label>
            <label className="block">
              <span className={tokens.label.base}>
                {t('fields.appointmentType')} <span className={tokens.label.required}>*</span>
              </span>
              <Select
                value={form.appointment_type}
                onChange={(e) => {
                  const raw = e.target.value
                  if (isAppointmentType(raw)) update('appointment_type', raw)
                }}
              >
                <option value="quick_service">{t('appointmentType.quick_service')}</option>
                <option value="inspection">{t('appointmentType.inspection')}</option>
                <option value="diagnostic">{t('appointmentType.diagnostic')}</option>
                <option value="standard_repair">{t('appointmentType.standard_repair')}</option>
                <option value="major_repair">{t('appointmentType.major_repair')}</option>
                <option value="maintenance">{t('appointmentType.maintenance')}</option>
                <option value="tire_service">{t('appointmentType.tire_service')}</option>
                <option value="bodywork">{t('appointmentType.bodywork')}</option>
                <option value="other">{t('appointmentType.other')}</option>
              </Select>
            </label>
            <label className="block">
              <span className={tokens.label.base}>{t('fields.waitType')}</span>
              <Select
                value={form.wait_type}
                onChange={(e) => {
                  const raw = e.target.value
                  if (isWaitType(raw)) update('wait_type', raw)
                }}
              >
                <option value="drop_off">{t('waitType.drop_off')}</option>
                <option value="waiter">{t('waitType.waiter')}</option>
                <option value="pickup_scheduled">{t('waitType.pickup_scheduled')}</option>
              </Select>
            </label>
          </fieldset>

          <fieldset className="flex flex-col gap-3">
            <legend className={`text-sm font-semibold ${textColors.primary}`}>
              {t('drawer.sectionNotes')}
            </legend>
            <label className="block">
              <span className={tokens.label.base}>{t('fields.servicesSummary')}</span>
              <Textarea
                rows={2}
                value={form.services_summary}
                onChange={(e) => { update('services_summary', e.target.value) }}
              />
            </label>
            <label className="block">
              <span className={tokens.label.base}>{t('fields.customerNotes')}</span>
              <Textarea
                rows={2}
                value={form.customer_notes}
                onChange={(e) => { update('customer_notes', e.target.value) }}
              />
            </label>
            <label className="block">
              <span className={tokens.label.base}>{t('fields.internalNotes')}</span>
              <Textarea
                rows={2}
                value={form.internal_notes}
                onChange={(e) => { update('internal_notes', e.target.value) }}
              />
            </label>
          </fieldset>

          <footer className={tokens.modal.footer}>
            <Button
              type="button"
              variant="secondary"
              size="md"
              onClick={onClose}
            >
              {t('actions.close')}
            </Button>
            <Button
              type="submit"
              variant="primary"
              size="md"
              disabled={booking.isPending}
            >
              {booking.isPending ? t('drawer.savingLabel') : t('actions.book')}
            </Button>
          </footer>
        </form>
      </div>
    </div>
  )
}
