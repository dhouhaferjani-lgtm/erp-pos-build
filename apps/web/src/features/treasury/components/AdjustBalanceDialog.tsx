import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Button } from '@/components/atoms/Button'
import { FormField } from '@/components/atoms/FormField'
import { MoneyInput } from '@/components/atoms/MoneyInput'
import { Select } from '@/components/atoms/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Modal, ModalContent, ModalFooter, ModalHeader } from '@/components/organisms/Modal'
import {
  useAdjustRepositoryBalance,
  type AdjustRepositoryBalanceRequest,
  type AdjustRepositoryBalanceResult,
} from '../hooks/useAdjustRepositoryBalance'

export interface AdjustBalanceDialogProps {
  isOpen: boolean
  onClose: () => void
  repositoryId: string
  repositoryCurrency: string
  onSuccess?: (result: AdjustRepositoryBalanceResult) => void
}

const DEFAULT_VALUES: AdjustRepositoryBalanceRequest = {
  direction: 'in',
  amount: '',
  reason_code: 'count_variance',
  reason_text: '',
}

const POSITIVE_MONEY_PATTERN = /^(?=.*[1-9])\d+(?:\.\d{1,3})?$/

export function AdjustBalanceDialog({
  isOpen,
  onClose,
  repositoryId,
  repositoryCurrency,
  onSuccess,
}: AdjustBalanceDialogProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const adjustment = useAdjustRepositoryBalance(repositoryId)
  const {
    control,
    handleSubmit,
    register,
    reset,
    formState: { errors },
  } = useForm<AdjustRepositoryBalanceRequest>({ defaultValues: DEFAULT_VALUES })

  useEffect(() => {
    if (isOpen) reset(DEFAULT_VALUES)
  }, [isOpen, reset])

  const onSubmit = (request: AdjustRepositoryBalanceRequest) => {
    adjustment.mutate(request, {
      onSuccess: (result) => {
        onSuccess?.(result)
        onClose()
      },
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="md">
      <ModalHeader
        title={t('treasury:repositories.adjustBalance.title')}
        onClose={onClose}
      />
      <form onSubmit={(event) => { void handleSubmit(onSubmit)(event) }}>
        <ModalContent>
          <FormField
            label={t('treasury:repositories.adjustBalance.direction')}
            htmlFor="adjustment-direction"
            required
            error={errors.direction?.message}
          >
            <Select id="adjustment-direction" {...register('direction', { required: true })}>
              <option value="in">{t('treasury:repositories.adjustBalance.directions.in')}</option>
              <option value="out">{t('treasury:repositories.adjustBalance.directions.out')}</option>
            </Select>
          </FormField>

          <FormField
            label={t('treasury:repositories.adjustBalance.amount')}
            htmlFor="adjustment-amount"
            required
            error={errors.amount?.message}
          >
            <Controller
              name="amount"
              control={control}
              rules={{
                required: t('treasury:repositories.adjustBalance.validation.amount'),
                pattern: {
                  value: POSITIVE_MONEY_PATTERN,
                  message: t('treasury:repositories.adjustBalance.validation.amount'),
                },
              }}
              render={({ field }) => (
                <MoneyInput
                  id="adjustment-amount"
                  currency={repositoryCurrency}
                  value={field.value ?? ''}
                  onChange={field.onChange}
                  onBlur={field.onBlur}
                  name={field.name}
                  ref={field.ref}
                  error={Boolean(errors.amount)}
                />
              )}
            />
          </FormField>

          <FormField
            label={t('treasury:repositories.adjustBalance.reasonCode')}
            htmlFor="adjustment-reason-code"
            required
            error={errors.reason_code?.message}
          >
            <Select id="adjustment-reason-code" {...register('reason_code', { required: true })}>
              <option value="count_variance">{t('treasury:repositories.adjustBalance.reasons.count_variance')}</option>
              <option value="correction">{t('treasury:repositories.adjustBalance.reasons.correction')}</option>
              <option value="theft_loss">{t('treasury:repositories.adjustBalance.reasons.theft_loss')}</option>
              <option value="other">{t('treasury:repositories.adjustBalance.reasons.other')}</option>
            </Select>
          </FormField>

          <FormField
            label={t('treasury:repositories.adjustBalance.reasonText')}
            htmlFor="adjustment-reason-text"
            required
            error={errors.reason_text?.message}
          >
            <Textarea
              id="adjustment-reason-text"
              rows={4}
              maxLength={1000}
              {...register('reason_text', {
                required: t('treasury:repositories.adjustBalance.validation.reasonText'),
                maxLength: {
                  value: 1000,
                  message: t('treasury:repositories.adjustBalance.validation.reasonTextMax'),
                },
              })}
            />
          </FormField>
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose} disabled={adjustment.isPending}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={adjustment.isPending}>
            {adjustment.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            {t('treasury:repositories.adjustBalance.submit')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
