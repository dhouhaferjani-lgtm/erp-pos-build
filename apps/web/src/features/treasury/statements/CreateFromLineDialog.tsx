import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { useTranslation } from 'react-i18next'
import { z } from 'zod'

import { Button, FormField, Input, Select, Textarea } from '@/components/atoms'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { useAccounts } from '@/features/finance/hooks/useAccounts'

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
  const { data: incomeAccounts = [], isLoading: accountsLoading } = useAccounts({ type: 'revenue', active: true })
  const schema = z.object({
    counterparty: z.string().max(255).optional(),
    accountId: z.string().optional(),
    notes: z.string().max(1000).optional(),
  }).superRefine((values, context) => {
    if (!isExpense && !values.accountId) {
      context.addIssue({ code: 'custom', path: ['accountId'], message: t('statements.workspace.create.required') })
    }
  })
  type FormValues = z.infer<typeof schema>
  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { counterparty: '', accountId: '', notes: '' },
  })

  function close() {
    form.reset()
    onClose()
  }

  function submit(values: FormValues) {
    const optionalText = (value: string | undefined): string | undefined => value?.trim() === '' ? undefined : value
    onSubmit(isExpense
      ? { action: 'create_expense', params: { vendor_name: optionalText(values.counterparty), notes: optionalText(values.notes) } }
      : { action: 'create_income', params: { income_account_id: values.accountId, source_name: optionalText(values.counterparty), notes: optionalText(values.notes) } })
    form.reset()
  }

  return (
    <Modal isOpen={isOpen} onClose={close} title={t(isExpense ? 'statements.workspace.create.expenseTitle' : 'statements.workspace.create.incomeTitle')}>
      <form onSubmit={(event) => { void form.handleSubmit(submit)(event) }}>
        <ModalContent>
          {isExpense ? <FormField label={t('statements.workspace.create.vendor')} htmlFor="statement-create-vendor"><Input id="statement-create-vendor" {...form.register('counterparty')} /></FormField> : <><FormField label={t('statements.workspace.create.source')} htmlFor="statement-create-source"><Input id="statement-create-source" {...form.register('counterparty')} /></FormField><FormField label={t('statements.workspace.create.incomeAccount')} htmlFor="statement-create-income-account" required error={form.formState.errors.accountId?.message}><Select id="statement-create-income-account" {...form.register('accountId')} disabled={accountsLoading || incomeAccounts.length === 0}><option value="">{accountsLoading ? t('common:loading') : t('statements.workspace.create.selectIncomeAccount')}</option>{incomeAccounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</Select></FormField></>}
          <FormField label={t('statements.workspace.create.notes')} htmlFor="statement-create-notes"><Textarea id="statement-create-notes" {...form.register('notes')} /></FormField>
        </ModalContent>
        <ModalFooter><Button type="button" variant="secondary" onClick={close}>{t('statements.workspace.cancel')}</Button><Button type="submit" disabled={pending}>{t('statements.workspace.create.submit')}</Button></ModalFooter>
      </form>
    </Modal>
  )
}
