import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'

import { Button, Input, Textarea } from '@/components/atoms'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { tokens } from '@/lib/designTokens'

import type { StatementActionType } from './api'

interface CreateFromLineDialogProps {
  isOpen: boolean
  direction: 'in' | 'out'
  pending?: boolean
  onClose: () => void
  onSubmit: (input: { action: StatementActionType; params: Record<string, unknown> }) => void
}

export function CreateFromLineDialog({ isOpen, direction, pending = false, onClose, onSubmit }: CreateFromLineDialogProps) {
  const { t } = useTranslation('treasury')
  const isExpense = direction === 'out'
  const form = useForm<{ counterparty: string; accountId: string; notes: string }>({
    defaultValues: { counterparty: '', accountId: '', notes: '' },
  })

  function close() {
    form.reset()
    onClose()
  }

  function submit(values: { counterparty: string; accountId: string; notes: string }) {
    onSubmit(isExpense
      ? { action: 'create_expense', params: { vendor_name: values.counterparty || undefined, notes: values.notes || undefined } }
      : { action: 'create_income', params: { income_account_id: values.accountId, source_name: values.counterparty || undefined, notes: values.notes || undefined } })
    form.reset()
  }

  return (
    <Modal isOpen={isOpen} onClose={close} title={t(isExpense ? 'statements.workspace.create.expenseTitle' : 'statements.workspace.create.incomeTitle')}>
      <form onSubmit={(event) => { void form.handleSubmit(submit)(event) }}>
        <ModalContent>
          {isExpense ? <label className={tokens.label.base}>{t('statements.workspace.create.vendor')}<Input {...form.register('counterparty')} /></label> : <><label className={tokens.label.base}>{t('statements.workspace.create.source')}<Input {...form.register('counterparty')} /></label><label className={tokens.label.base}>{t('statements.workspace.create.incomeAccount')}<Input required {...form.register('accountId', { required: true })} /></label></>}
          <label className={tokens.label.base}>{t('statements.workspace.create.notes')}<Textarea {...form.register('notes')} /></label>
        </ModalContent>
        <ModalFooter><Button type="button" variant="secondary" onClick={close}>{t('statements.workspace.cancel')}</Button><Button type="submit" disabled={pending}>{t('statements.workspace.create.submit')}</Button></ModalFooter>
      </form>
    </Modal>
  )
}
