import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AxiosError } from 'axios'
import { X } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import type { TechnicianTimeEntry, TimeEntryType } from '../api/authoringTypes'
import { useCreateTimeEntry, useUpdateTimeEntry } from '../hooks/useAuthoring'

interface TimeEntryFormModalProps {
  technicianId: string
  timeEntry?: TechnicianTimeEntry | undefined
  onClose: () => void
  onSaved: () => void
}

const TYPES: TimeEntryType[] = ['work_order', 'break', 'non_billable', 'manual_adjust']

interface FormState {
  entry_type: TimeEntryType
  started_at: string
  ended_at: string
  work_order_id: string
  notes: string
}

type FormErrors = Partial<Record<keyof FormState | 'form', string>>

function toInputValue(iso: string | null | undefined): string {
  if (iso === undefined || iso === null || iso === '') return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n: number): string => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export function TimeEntryFormModal({
  technicianId,
  timeEntry,
  onClose,
  onSaved,
}: TimeEntryFormModalProps) {
  const { t } = useTranslation('workshop-technicians')
  const isEditing = timeEntry !== undefined
  const createMut = useCreateTimeEntry(technicianId)
  const updateMut = useUpdateTimeEntry(technicianId)

  const [state, setState] = useState<FormState>({
    entry_type: timeEntry?.entry_type ?? 'work_order',
    started_at: toInputValue(timeEntry?.started_at),
    ended_at: toInputValue(timeEntry?.ended_at),
    work_order_id: timeEntry?.work_order_id ?? '',
    notes: timeEntry?.notes ?? '',
  })
  const [errors, setErrors] = useState<FormErrors>({})
  const isPending = createMut.isPending || updateMut.isPending

  function handleSubmit(e: React.FormEvent<HTMLFormElement>): void {
    e.preventDefault()
    if (state.started_at === '' || state.ended_at === '') {
      const next: FormErrors = {}
      if (state.started_at === '') next.started_at = t('authoring.errors.required')
      if (state.ended_at === '') next.ended_at = t('authoring.errors.required')
      setErrors(next)
      return
    }

    const payload = {
      entry_type: state.entry_type,
      started_at: new Date(state.started_at).toISOString(),
      ended_at: new Date(state.ended_at).toISOString(),
      work_order_id: state.work_order_id.trim() === '' ? null : state.work_order_id.trim(),
      notes: state.notes.trim() === '' ? null : state.notes.trim(),
    }

    const onError = (err: unknown): void => {
      if (err instanceof AxiosError) {
        const data = err.response?.data as
          | { error?: { code?: string }; errors?: Record<string, string[]> }
          | undefined
        if (data?.error?.code === 'TIME_ENTRY_LOCKED') {
          setErrors({ form: t('authoring.errors.locked') })
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

    if (isEditing && timeEntry !== undefined) {
      updateMut.mutate(
        { timeEntryId: timeEntry.id, payload },
        { onSuccess: onSaved, onError },
      )
    } else {
      createMut.mutate(payload, { onSuccess: onSaved, onError })
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50"
      data-testid="time-entry-form-modal"
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
              ? t('authoring.timeEntries.modal.editTitle')
              : t('authoring.timeEntries.modal.createTitle')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('authoring.timeEntries.modal.cancel')}
            className={tokens.modal.closeButton}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className={tokens.label.base}>
              {t('authoring.timeEntries.fields.entryType')}
            </label>
            <select
              className={tokens.input.base}
              value={state.entry_type}
              onChange={(e) => {
                setState((s) => ({ ...s, entry_type: e.target.value as TimeEntryType }))
              }}
            >
              {TYPES.map((ty) => (
                <option key={ty} value={ty}>
                  {t(`authoring.timeEntries.entryType.${ty}`)}
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={tokens.label.base}>
                {t('authoring.timeEntries.fields.startedAt')}
              </label>
              <input
                type="datetime-local"
                className={tokens.input.base}
                value={state.started_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, started_at: e.target.value }))
                }}
              />
              {errors.started_at !== undefined ? (
                <p className={tokens.helperText.error}>{errors.started_at}</p>
              ) : null}
            </div>
            <div>
              <label className={tokens.label.base}>
                {t('authoring.timeEntries.fields.endedAt')}
              </label>
              <input
                type="datetime-local"
                className={tokens.input.base}
                value={state.ended_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, ended_at: e.target.value }))
                }}
              />
              {errors.ended_at !== undefined ? (
                <p className={tokens.helperText.error}>{errors.ended_at}</p>
              ) : null}
            </div>
          </div>

          <div>
            <label className={tokens.label.base}>
              {t('authoring.timeEntries.fields.workOrderId')}
            </label>
            <input
              type="text"
              className={tokens.input.base}
              placeholder="00000000-0000-0000-0000-000000000000"
              value={state.work_order_id}
              onChange={(e) => {
                setState((s) => ({ ...s, work_order_id: e.target.value }))
              }}
            />
          </div>

          <div>
            <label className={tokens.label.base}>
              {t('authoring.timeEntries.fields.notes')}
            </label>
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
              {t('authoring.timeEntries.modal.cancel')}
            </button>
            <button
              type="submit"
              disabled={isPending}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {isPending
                ? t('authoring.timeEntries.modal.saving')
                : t('authoring.timeEntries.modal.save')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
