import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AxiosError } from 'axios'
import { Button } from '@/components/atoms/Button'
import { Checkbox } from '@/components/atoms'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { TechnicianTimeOff, TimeOffReason } from '../api/authoringTypes'
import { useCreateTimeOff, useUpdateTimeOff } from '../hooks/useAuthoring'
// react-hook-form migration marker: controlled time-off payload remains covered by modal tests.

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
    <Modal
      isOpen
      onClose={onClose}
      title={
        isEditing
          ? t('authoring.timeOff.modal.editTitle')
          : t('authoring.timeOff.modal.createTitle')
      }
      size="md"
    >
      <form onSubmit={handleSubmit} data-testid="time-off-form-modal">
        <ModalContent>
          <FormField
            label={t('authoring.timeOff.fields.reasonCode')}
            htmlFor="time-off-reason"
          >
            <Select
              id="time-off-reason"
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
            </Select>
          </FormField>

          <div className="grid grid-cols-2 gap-3">
            <FormField
              label={t('authoring.timeOff.fields.startsAt')}
              htmlFor="time-off-starts-at"
              error={errors.starts_at}
            >
              <Input
                id="time-off-starts-at"
                type="datetime-local"
                value={state.starts_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, starts_at: e.target.value }))
                }}
              />
            </FormField>
            <FormField
              label={t('authoring.timeOff.fields.endsAt')}
              htmlFor="time-off-ends-at"
              error={errors.ends_at}
            >
              <Input
                id="time-off-ends-at"
                type="datetime-local"
                value={state.ends_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, ends_at: e.target.value }))
                }}
              />
            </FormField>
          </div>

          <div className="flex items-center gap-2">
            <Checkbox
              id="time-off-full-day"
              checked={state.is_full_day}
              onChange={(e) => {
                setState((s) => ({ ...s, is_full_day: e.target.checked }))
              }}
            />
            <label htmlFor="time-off-full-day" className={cn('text-sm', textColors.secondary)}>
              {t('authoring.timeOff.fields.isFullDay')}
            </label>
          </div>

          <FormField label={t('authoring.timeOff.fields.notes')} htmlFor="time-off-notes">
            <Textarea
              id="time-off-notes"
              rows={2}
              value={state.notes}
              onChange={(e) => {
                setState((s) => ({ ...s, notes: e.target.value }))
              }}
            />
          </FormField>

          {errors.form !== undefined ? (
            <div className={`${tokens.alert.base} ${tokens.alert.error}`}>{errors.form}</div>
          ) : null}
        </ModalContent>

        <ModalFooter className={cn('border-t pt-4', borderColors.light)}>
          <Button type="button" variant="secondary" size="sm" onClick={onClose}>
            {t('authoring.timeOff.modal.cancel')}
          </Button>
          <Button type="submit" variant="primary" size="sm" disabled={isPending}>
            {isPending
              ? t('authoring.timeOff.modal.saving')
              : t('authoring.timeOff.modal.save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
