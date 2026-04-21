import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AxiosError } from 'axios'
import { X } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import type { TechnicianTimeOff, TimeOffReason } from '../api/authoringTypes'
import { useCreateTimeOff, useUpdateTimeOff } from '../hooks/useAuthoring'

interface TimeOffFormModalProps {
  technicianId: string
  timeOff?: TechnicianTimeOff | undefined
  onClose: () => void
  onSaved: () => void
}

const REASONS: TimeOffReason[] = ['vacation', 'sick', 'training', 'personal', 'unpaid', 'other']

interface FormState {
  reason_code: TimeOffReason
  starts_at: string
  ends_at: string
  is_full_day: boolean
  notes: string
}

type FormErrors = Partial<Record<keyof FormState | 'form', string>>

function toInputValue(iso: string | undefined): string {
  if (iso === undefined || iso === '') return ''
  // Convert ISO 8601 (with tz) → YYYY-MM-DDTHH:MM for datetime-local input.
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export function TimeOffFormModal({
  technicianId,
  timeOff,
  onClose,
  onSaved,
}: TimeOffFormModalProps) {
  const { t } = useTranslation('workshop-technicians')
  const isEditing = timeOff !== undefined
  const createMut = useCreateTimeOff(technicianId)
  const updateMut = useUpdateTimeOff(technicianId)

  const [state, setState] = useState<FormState>({
    reason_code: timeOff?.reason_code ?? 'vacation',
    starts_at: toInputValue(timeOff?.starts_at),
    ends_at: toInputValue(timeOff?.ends_at),
    is_full_day: timeOff?.is_full_day ?? true,
    notes: timeOff?.notes ?? '',
  })
  const [errors, setErrors] = useState<FormErrors>({})
  const isPending = createMut.isPending || updateMut.isPending

  function handleSubmit(e: React.FormEvent<HTMLFormElement>): void {
    e.preventDefault()
    if (state.starts_at === '' || state.ends_at === '') {
      const next: FormErrors = {}
      if (state.starts_at === '') next.starts_at = t('authoring.errors.required')
      if (state.ends_at === '') next.ends_at = t('authoring.errors.required')
      setErrors(next)
      return
    }

    const payload = {
      reason_code: state.reason_code,
      starts_at: new Date(state.starts_at).toISOString(),
      ends_at: new Date(state.ends_at).toISOString(),
      is_full_day: state.is_full_day,
      notes: state.notes.trim() === '' ? null : state.notes.trim(),
    }

    const onError = (err: unknown): void => {
      if (err instanceof AxiosError) {
        const data = err.response?.data as
          | { error?: { code?: string }; errors?: Record<string, string[]> }
          | undefined
        if (data?.error?.code === 'TIME_OFF_OVERLAP') {
          setErrors({ form: t('authoring.errors.overlap') })
          return
        }
        if (data?.errors !== undefined) {
          const next: FormErrors = {}
          for (const [field, messages] of Object.entries(data.errors)) {
            if (messages.length > 0) {
              next[field as keyof FormErrors] = messages[0]
            }
          }
          setErrors(next)
          return
        }
      }
      setErrors({ form: t('authoring.errors.generic') })
    }

    if (isEditing && timeOff !== undefined) {
      updateMut.mutate(
        { timeOffId: timeOff.id, payload },
        { onSuccess: onSaved, onError },
      )
    } else {
      createMut.mutate(payload, { onSuccess: onSaved, onError })
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50"
      data-testid="time-off-form-modal"
    >
      <div
        className="relative mx-4 rounded-xl bg-white p-6 shadow-xl"
        style={{ width: '560px', maxWidth: '100%' }}
      >
        <div
          className={`mb-4 flex items-center justify-between border-b ${borderColors.light} pb-3`}
        >
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>
            {isEditing
              ? t('authoring.timeOff.modal.editTitle')
              : t('authoring.timeOff.modal.createTitle')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('authoring.timeOff.modal.cancel')}
            className={tokens.modal.closeButton}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className={tokens.label.base}>
              {t('authoring.timeOff.fields.reasonCode')}
            </label>
            <select
              className={tokens.input.base}
              value={state.reason_code}
              onChange={(e) => {
                setState((s) => ({ ...s, reason_code: e.target.value as TimeOffReason }))
              }}
            >
              {REASONS.map((r) => (
                <option key={r} value={r}>
                  {t(`authoring.timeOff.reason.${r}`)}
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={tokens.label.base}>
                {t('authoring.timeOff.fields.startsAt')}
              </label>
              <input
                type="datetime-local"
                className={tokens.input.base}
                value={state.starts_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, starts_at: e.target.value }))
                }}
              />
              {errors.starts_at !== undefined ? (
                <p className={tokens.helperText.error}>{errors.starts_at}</p>
              ) : null}
            </div>
            <div>
              <label className={tokens.label.base}>{t('authoring.timeOff.fields.endsAt')}</label>
              <input
                type="datetime-local"
                className={tokens.input.base}
                value={state.ends_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, ends_at: e.target.value }))
                }}
              />
              {errors.ends_at !== undefined ? (
                <p className={tokens.helperText.error}>{errors.ends_at}</p>
              ) : null}
            </div>
          </div>

          <div className="flex items-center gap-2">
            <input
              id="time-off-full-day"
              type="checkbox"
              checked={state.is_full_day}
              onChange={(e) => {
                setState((s) => ({ ...s, is_full_day: e.target.checked }))
              }}
            />
            <label htmlFor="time-off-full-day" className={`text-sm ${textColors.secondary}`}>
              {t('authoring.timeOff.fields.isFullDay')}
            </label>
          </div>

          <div>
            <label className={tokens.label.base}>{t('authoring.timeOff.fields.notes')}</label>
            <textarea
              className={tokens.input.base}
              rows={2}
              value={state.notes}
              onChange={(e) => {
                setState((s) => ({ ...s, notes: e.target.value }))
              }}
            />
          </div>

          {errors.form !== undefined ? (
            <div className={`${tokens.alert.base} ${tokens.alert.error}`}>{errors.form}</div>
          ) : null}

          <div
            className={`flex items-center justify-end gap-2 border-t ${borderColors.light} pt-4`}
          >
            <button
              type="button"
              onClick={onClose}
              className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.sm}`}
            >
              {t('authoring.timeOff.modal.cancel')}
            </button>
            <button
              type="submit"
              disabled={isPending}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {isPending
                ? t('authoring.timeOff.modal.saving')
                : t('authoring.timeOff.modal.save')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
