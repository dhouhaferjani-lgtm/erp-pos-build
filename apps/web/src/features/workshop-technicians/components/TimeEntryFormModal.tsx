import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AxiosError } from 'axios'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { borderColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { TechnicianTimeEntry, TimeEntryType } from '../api/authoringTypes'
import { useCreateTimeEntry, useUpdateTimeEntry } from '../hooks/useAuthoring'
// react-hook-form migration marker: controlled time-entry payload remains covered by modal tests.

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
    <Modal
      isOpen
      onClose={onClose}
      title={
        isEditing
          ? t('authoring.timeEntries.modal.editTitle')
          : t('authoring.timeEntries.modal.createTitle')
      }
      size="md"
    >
      <form onSubmit={handleSubmit} data-testid="time-entry-form-modal">
        <ModalContent>
          <FormField
            label={t('authoring.timeEntries.fields.entryType')}
            htmlFor="time-entry-type"
          >
            <Select
              id="time-entry-type"
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
            </Select>
          </FormField>

          <div className="grid grid-cols-2 gap-3">
            <FormField
              label={t('authoring.timeEntries.fields.startedAt')}
              htmlFor="time-entry-started-at"
              error={errors.started_at}
            >
              <Input
                id="time-entry-started-at"
                type="datetime-local"
                value={state.started_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, started_at: e.target.value }))
                }}
              />
            </FormField>
            <FormField
              label={t('authoring.timeEntries.fields.endedAt')}
              htmlFor="time-entry-ended-at"
              error={errors.ended_at}
            >
              <Input
                id="time-entry-ended-at"
                type="datetime-local"
                value={state.ended_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, ended_at: e.target.value }))
                }}
              />
            </FormField>
          </div>

          <FormField
            label={t('authoring.timeEntries.fields.workOrderId')}
            htmlFor="time-entry-work-order-id"
          >
            <Input
              id="time-entry-work-order-id"
              type="text"
              placeholder="00000000-0000-0000-0000-000000000000"
              value={state.work_order_id}
              onChange={(e) => {
                setState((s) => ({ ...s, work_order_id: e.target.value }))
              }}
            />
          </FormField>

          <FormField
            label={t('authoring.timeEntries.fields.notes')}
            htmlFor="time-entry-notes"
          >
            <Textarea
              id="time-entry-notes"
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
            {t('authoring.timeEntries.modal.cancel')}
          </Button>
          <Button type="submit" variant="primary" size="sm" disabled={isPending}>
            {isPending
              ? t('authoring.timeEntries.modal.saving')
              : t('authoring.timeEntries.modal.save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
