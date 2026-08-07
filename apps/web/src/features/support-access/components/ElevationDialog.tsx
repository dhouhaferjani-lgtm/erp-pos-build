import { useTranslation } from 'react-i18next'
import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { Textarea } from '@/components/atoms/Textarea'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import { Modal } from '@/components/organisms/Modal'

interface Props { open: boolean; onClose: () => void; onSubmit: (reason: string) => Promise<unknown> }
interface ElevationFormValues { reason: string }

export function ElevationDialog({ open, onClose, onSubmit }: Props) {
  const { t } = useTranslation('admin')
  const schema = z.object({ reason: z.string().trim().min(1, t('supportAccess.validation.required')) })
  const form = useForm<ElevationFormValues>({
    resolver: zodResolver(schema),
    defaultValues: { reason: '' },
  })

  const submit = async ({ reason }: ElevationFormValues) => {
    try {
      await onSubmit(reason)
      form.reset()
      onClose()
    } catch {
      // Mutation hook owns the translated error toast; keep the form open.
    }
  }

  return (
    <Modal isOpen={open} onClose={onClose} title={t('supportAccess.elevation.title')}>
      <form onSubmit={(event) => { void form.handleSubmit(submit)(event) }}>
        <p className={`mt-2 text-sm ${tokens.text.muted}`}>{t('supportAccess.elevation.description')}</p>
        <FormField label={t('supportAccess.fields.reason')} htmlFor="support-elevation-reason" required error={form.formState.errors.reason?.message} className="mt-5">
          <Textarea id="support-elevation-reason" {...form.register('reason')} className="min-h-24 w-full" />
        </FormField>
        <div className="mt-5 flex justify-end gap-3">
          <Button type="button" variant="secondary" onClick={onClose}>{t('supportAccess.actions.cancel')}</Button>
          <Button type="submit">{t('supportAccess.actions.submitElevation')}</Button>
        </div>
      </form>
    </Modal>
  )
}
