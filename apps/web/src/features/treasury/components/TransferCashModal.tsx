import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { Controller, useForm, useWatch, type Resolver } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { z } from 'zod'

import { Button, FormField, MoneyInput, Select, Textarea } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import { getErrorMessage } from '@/lib/api'
import { formatCurrency } from '@/lib/format'

import {
  useActivePaymentRepositories,
  type PaymentRepository,
} from '../hooks/usePaymentRepositories'
import { useTransferCash } from '../hooks/useTransferCash'

const validationMessageKeys = new Set([
  'treasury:repositories.transfer.fromRequired',
  'treasury:repositories.transfer.toRequired',
  'treasury:repositories.transfer.invalidAmount',
  'treasury:repositories.transfer.zeroAmount',
  'treasury:repositories.transfer.notesTooLong',
  'treasury:repositories.transfer.sameRepository',
])

function repositoryLabel(repository: PaymentRepository): string {
  return `${repository.name} (${repository.code}) — ${formatCurrency(repository.balance, {
    currency: repository.currency,
  })}`
}

const schema = z.object({
  from_repository_id: z.uuid('treasury:repositories.transfer.fromRequired'),
  to_repository_id: z.uuid('treasury:repositories.transfer.toRequired'),
  amount: z
    .string()
    .regex(/^\d+(\.\d{1,3})?$/, 'treasury:repositories.transfer.invalidAmount')
    .refine(
      (value) => value !== '0' && !/^0+(\.0{1,3})?$/.test(value),
      'treasury:repositories.transfer.zeroAmount',
    ),
  notes: z.string().max(1000, 'treasury:repositories.transfer.notesTooLong').optional(),
}).refine(
  (value) => value.from_repository_id !== value.to_repository_id,
  { path: ['to_repository_id'], message: 'treasury:repositories.transfer.sameRepository' },
)

type FormValues = z.infer<typeof schema>

export interface TransferCashModalProps {
  isOpen: boolean
  onClose: () => void
  onSuccess?: () => void
}

export function TransferCashModal({ isOpen, onClose, onSuccess }: TransferCashModalProps) {
  if (!isOpen) return null

  return (
    <OpenTransferCashModal
      onClose={onClose}
      {...(onSuccess === undefined ? {} : { onSuccess })}
    />
  )
}

function OpenTransferCashModal({ onClose, onSuccess }: Omit<TransferCashModalProps, 'isOpen'>) {
  const { t } = useTranslation(['treasury', 'common'])
  const repositoriesQuery = useActivePaymentRepositories()
  const transfer = useTransferCash()
  const [transferGroupId] = useState(() => crypto.randomUUID())
  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      from_repository_id: '',
      to_repository_id: '',
      amount: '',
      notes: '',
    },
  })
  const fromRepositoryId = useWatch({ control: form.control, name: 'from_repository_id' })
  const fromRepository = repositoriesQuery.data.find((repository) => repository.id === fromRepositoryId)
  const eligibleRepositories = repositoriesQuery.data.filter(
    (repository) => repository.type !== 'virtual',
  )
  const destinationRepositories = eligibleRepositories.filter(
    (repository) => repository.id !== fromRepositoryId
      && (fromRepository === undefined || repository.currency === fromRepository.currency),
  )

  const validationError = (message: string | undefined): string | undefined => {
    if (message === undefined || !validationMessageKeys.has(message)) return message
    return t(message)
  }

  useEffect(() => {
    const destinationId = form.getValues('to_repository_id')
    if (destinationId !== '' && !destinationRepositories.some((repository) => repository.id === destinationId)) {
      form.setValue('to_repository_id', '')
    }
  }, [destinationRepositories, form])

  const submit = (values: FormValues) => {
    transfer.mutate(
      {
        from_repository_id: values.from_repository_id,
        to_repository_id: values.to_repository_id,
        amount: values.amount,
        ...(values.notes ? { notes: values.notes } : {}),
        transfer_group_id: transferGroupId,
      },
      {
        onSuccess: () => {
          toast.success(t('treasury:repositories.transfer.success'))
          onSuccess?.()
          onClose()
        },
        onError: (error) => {
          toast.error(getErrorMessage(error))
        },
      },
    )
  }

  return (
    <Modal isOpen onClose={onClose} size="sm">
      <Modal.Header title={t('treasury:repositories.transfer.title')} onClose={onClose} />
      <form onSubmit={(event) => { void form.handleSubmit(submit)(event) }}>
        <Modal.Content className="space-y-4">
          <FormField
            label={t('treasury:repositories.transfer.from')}
            htmlFor="transfer-from-repository"
            required
            error={validationError(form.formState.errors.from_repository_id?.message)}
            helperText={fromRepository
              ? t('treasury:repositories.transfer.balanceHint', {
                  balance: formatCurrency(fromRepository.balance, { currency: fromRepository.currency }),
                })
              : undefined}
          >
            <Select
              id="transfer-from-repository"
              {...form.register('from_repository_id')}
              error={Boolean(form.formState.errors.from_repository_id)}
              disabled={repositoriesQuery.isLoading}
            >
              <option value="">{t('treasury:repositories.transfer.selectRepository')}</option>
              {eligibleRepositories.map((repository) => (
                <option key={repository.id} value={repository.id}>{repositoryLabel(repository)}</option>
              ))}
            </Select>
          </FormField>

          <FormField
            label={t('treasury:repositories.transfer.to')}
            htmlFor="transfer-to-repository"
            required
            error={validationError(form.formState.errors.to_repository_id?.message)}
          >
            <Select
              id="transfer-to-repository"
              {...form.register('to_repository_id')}
              error={Boolean(form.formState.errors.to_repository_id)}
              disabled={repositoriesQuery.isLoading || fromRepository === undefined}
            >
              <option value="">{t('treasury:repositories.transfer.selectRepository')}</option>
              {destinationRepositories.map((repository) => (
                <option key={repository.id} value={repository.id}>{repositoryLabel(repository)}</option>
              ))}
            </Select>
          </FormField>

          <FormField
            label={t('treasury:repositories.transfer.amount')}
            htmlFor="transfer-amount"
            required
            error={validationError(form.formState.errors.amount?.message)}
          >
            <Controller
              name="amount"
              control={form.control}
              render={({ field }) => (
                <MoneyInput
                  id="transfer-amount"
                  ref={field.ref}
                  name={field.name}
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                  currency={fromRepository?.currency ?? 'EUR'}
                  error={Boolean(form.formState.errors.amount)}
                  disabled={fromRepository === undefined}
                />
              )}
            />
          </FormField>

          <FormField
            label={t('treasury:repositories.transfer.notes')}
            htmlFor="transfer-notes"
            error={validationError(form.formState.errors.notes?.message)}
          >
            <Textarea id="transfer-notes" rows={3} maxLength={1000} {...form.register('notes')} />
          </FormField>
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" disabled={transfer.isPending}>
            {t('treasury:repositories.transfer.submit')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
