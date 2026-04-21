import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AxiosError } from 'axios'
import { X } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import type { TechnicianCertification } from '../api/authoringTypes'
import {
  useCreateCertification,
  useUpdateCertification,
} from '../hooks/useAuthoring'

interface CertificationFormModalProps {
  technicianId: string
  certification?: TechnicianCertification | undefined
  onClose: () => void
  onSaved: () => void
}

interface FormState {
  certification_name: string
  issuing_body: string
  certificate_number: string
  issued_at: string
  expires_at: string
  notes: string
}

type FormErrors = Partial<Record<keyof FormState | 'form', string>>

/**
 * Fixed-dimension authoring modal for a single technician certification.
 * Width pinned to 560px per the project modal-size memo.
 */
export function CertificationFormModal({
  technicianId,
  certification,
  onClose,
  onSaved,
}: CertificationFormModalProps) {
  const { t } = useTranslation('workshop-technicians')
  const isEditing = certification !== undefined
  const createMut = useCreateCertification(technicianId)
  const updateMut = useUpdateCertification(technicianId)

  const [state, setState] = useState<FormState>({
    certification_name: certification?.certification_name ?? '',
    issuing_body: certification?.issuing_body ?? '',
    certificate_number: certification?.certificate_number ?? '',
    issued_at: certification?.issued_at ?? '',
    expires_at: certification?.expires_at ?? '',
    notes: certification?.notes ?? '',
  })
  const [errors, setErrors] = useState<FormErrors>({})
  const isPending = createMut.isPending || updateMut.isPending

  function handleSubmit(e: React.FormEvent<HTMLFormElement>): void {
    e.preventDefault()
    if (state.certification_name.trim() === '') {
      setErrors({ certification_name: t('authoring.errors.required') })
      return
    }

    const payload = {
      certification_name: state.certification_name.trim(),
      issuing_body: state.issuing_body.trim() === '' ? null : state.issuing_body.trim(),
      certificate_number:
        state.certificate_number.trim() === '' ? null : state.certificate_number.trim(),
      issued_at: state.issued_at === '' ? null : state.issued_at,
      expires_at: state.expires_at === '' ? null : state.expires_at,
      notes: state.notes.trim() === '' ? null : state.notes.trim(),
    }

    const onError = (err: unknown): void => {
      if (err instanceof AxiosError) {
        const data = err.response?.data as
          | { errors?: Record<string, string[]> }
          | undefined
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

    if (isEditing && certification !== undefined) {
      updateMut.mutate(
        { certificationId: certification.id, payload },
        { onSuccess: onSaved, onError },
      )
    } else {
      createMut.mutate(payload, { onSuccess: onSaved, onError })
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50"
      data-testid="certification-form-modal"
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
              ? t('authoring.certifications.modal.editTitle')
              : t('authoring.certifications.modal.createTitle')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('authoring.certifications.modal.cancel')}
            className={tokens.modal.closeButton}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className={tokens.label.base}>
              {t('authoring.certifications.fields.name')}
            </label>
            <input
              type="text"
              className={tokens.input.base}
              value={state.certification_name}
              onChange={(e) => {
                setState((s) => ({ ...s, certification_name: e.target.value }))
              }}
            />
            {errors.certification_name !== undefined ? (
              <p className={tokens.helperText.error}>{errors.certification_name}</p>
            ) : null}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={tokens.label.base}>
                {t('authoring.certifications.fields.issuingBody')}
              </label>
              <input
                type="text"
                className={tokens.input.base}
                value={state.issuing_body}
                onChange={(e) => {
                  setState((s) => ({ ...s, issuing_body: e.target.value }))
                }}
              />
            </div>
            <div>
              <label className={tokens.label.base}>
                {t('authoring.certifications.fields.certificateNumber')}
              </label>
              <input
                type="text"
                className={tokens.input.base}
                value={state.certificate_number}
                onChange={(e) => {
                  setState((s) => ({ ...s, certificate_number: e.target.value }))
                }}
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={tokens.label.base}>
                {t('authoring.certifications.fields.issuedAt')}
              </label>
              <input
                type="date"
                className={tokens.input.base}
                value={state.issued_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, issued_at: e.target.value }))
                }}
              />
            </div>
            <div>
              <label className={tokens.label.base}>
                {t('authoring.certifications.fields.expiresAt')}
              </label>
              <input
                type="date"
                className={tokens.input.base}
                value={state.expires_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, expires_at: e.target.value }))
                }}
              />
            </div>
          </div>

          <div>
            <label className={tokens.label.base}>
              {t('authoring.certifications.fields.notes')}
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
              {t('authoring.certifications.modal.cancel')}
            </button>
            <button
              type="submit"
              disabled={isPending}
              className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.sm}`}
            >
              {isPending
                ? t('authoring.certifications.modal.saving')
                : t('authoring.certifications.modal.save')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
