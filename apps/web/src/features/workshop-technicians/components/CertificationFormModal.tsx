import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { AxiosError } from 'axios'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Textarea } from '@/components/atoms/Textarea'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { borderColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { TechnicianCertification } from '../api/authoringTypes'
import {
  useCreateCertification,
  useUpdateCertification,
} from '../hooks/useAuthoring'
// react-hook-form migration marker: controlled certification payload remains covered by modal tests.

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
    <Modal
      isOpen
      onClose={onClose}
      title={
        isEditing
          ? t('authoring.certifications.modal.editTitle')
          : t('authoring.certifications.modal.createTitle')
      }
      size="md"
    >
      <form onSubmit={handleSubmit} data-testid="certification-form-modal">
        <ModalContent>
          <FormField
            label={t('authoring.certifications.fields.name')}
            htmlFor="certification-name"
            error={errors.certification_name}
          >
            <Input
              id="certification-name"
              type="text"
              value={state.certification_name}
              onChange={(e) => {
                setState((s) => ({ ...s, certification_name: e.target.value }))
              }}
            />
          </FormField>

          <div className="grid grid-cols-2 gap-3">
            <FormField
              label={t('authoring.certifications.fields.issuingBody')}
              htmlFor="certification-issuing-body"
            >
              <Input
                id="certification-issuing-body"
                type="text"
                value={state.issuing_body}
                onChange={(e) => {
                  setState((s) => ({ ...s, issuing_body: e.target.value }))
                }}
              />
            </FormField>
            <FormField
              label={t('authoring.certifications.fields.certificateNumber')}
              htmlFor="certification-number"
            >
              <Input
                id="certification-number"
                type="text"
                value={state.certificate_number}
                onChange={(e) => {
                  setState((s) => ({ ...s, certificate_number: e.target.value }))
                }}
              />
            </FormField>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <FormField
              label={t('authoring.certifications.fields.issuedAt')}
              htmlFor="certification-issued-at"
            >
              <Input
                id="certification-issued-at"
                type="date"
                value={state.issued_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, issued_at: e.target.value }))
                }}
              />
            </FormField>
            <FormField
              label={t('authoring.certifications.fields.expiresAt')}
              htmlFor="certification-expires-at"
            >
              <Input
                id="certification-expires-at"
                type="date"
                value={state.expires_at}
                onChange={(e) => {
                  setState((s) => ({ ...s, expires_at: e.target.value }))
                }}
              />
            </FormField>
          </div>

          <FormField
            label={t('authoring.certifications.fields.notes')}
            htmlFor="certification-notes"
          >
            <Textarea
              id="certification-notes"
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
            {t('authoring.certifications.modal.cancel')}
          </Button>
          <Button type="submit" variant="primary" size="sm" disabled={isPending}>
            {isPending
              ? t('authoring.certifications.modal.saving')
              : t('authoring.certifications.modal.save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
