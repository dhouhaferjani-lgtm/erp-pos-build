import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Loader2 } from 'lucide-react'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../Modal'
import { FormField } from '../../atoms/FormField'
import { Input } from '../../atoms/Input'
import { Select } from '../../atoms/Select'
import { Button } from '../../atoms/Button'
import { apiPost } from '../../../lib/api'

interface Repository {
  id: string
  code: string
  name: string
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual'
  bank_name: string | null
  account_number: string | null
  iban: string | null
  bic: string | null
  balance: string
  is_active: boolean
}

interface RepositoryFormData {
  code: string
  name: string
  type: string
  bank_name: string
  account_number: string
  iban: string
  bic: string
}

export interface AddRepositoryModalProps {
  /**
   * Controls modal visibility
   */
  isOpen: boolean

  /**
   * Callback when modal should close
   */
  onClose: () => void

  /**
   * Callback after successful repository creation
   * Receives the newly created repository
   */
  onSuccess?: (repository: Repository) => void
}

/**
 * AddRepositoryModal - Payment repository creation modal
 *
 * Creates payment repositories (cash registers, safes, bank accounts, virtual).
 * Conditionally shows bank fields when type is "bank_account".
 *
 * @example
 * ```tsx
 * <AddRepositoryModal
 *   isOpen={isOpen}
 *   onClose={() => setIsOpen(false)}
 *   onSuccess={(repository) => {
 *     // Refresh repository list
 *     refetch()
 *   }}
 * />
 * ```
 */
export function AddRepositoryModal({
  isOpen,
  onClose,
  onSuccess,
}: AddRepositoryModalProps) {
  const { t } = useTranslation(['treasury', 'common'])
  const queryClient = useQueryClient()

  // Form state with React Hook Form
  const {
    register,
    handleSubmit,
    reset,
    watch,
    formState: { errors },
  } = useForm<RepositoryFormData>({
    defaultValues: {
      code: '',
      name: '',
      type: 'cash_register',
      bank_name: '',
      account_number: '',
      iban: '',
      bic: '',
    },
  })

  // Reset form when modal opens
  useEffect(() => {
    if (isOpen) {
      reset({
        code: '',
        name: '',
        type: 'cash_register',
        bank_name: '',
        account_number: '',
        iban: '',
        bic: '',
      })
    }
  }, [isOpen, reset])

  // Watch type to conditionally show bank fields
  const selectedType = watch('type')
  const isBankAccount = selectedType === 'bank_account'

  // React Query mutation
  const mutation = useMutation({
    mutationFn: (data: RepositoryFormData) => {
      // Transform data for API
      const payload = {
        code: data.code,
        name: data.name,
        type: data.type,
        bank_name: data.bank_name || null,
        account_number: data.account_number || null,
        iban: data.iban || null,
        bic: data.bic || null,
      }
      return apiPost<{ data: Repository }>('/payment-repositories', payload)
    },
    onSuccess: (response) => {
      void queryClient.invalidateQueries({ queryKey: ['payment-repositories'] })
      onSuccess?.(response.data)
      onClose()
    },
  })

  const onSubmit = (data: RepositoryFormData) => {
    mutation.mutate(data)
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <ModalHeader title={t('treasury:repositories.add', 'Add Repository')} onClose={onClose} />

      <form onSubmit={(e) => { void handleSubmit(onSubmit)(e) }}>
        <ModalContent>
          <div className="space-y-4">
            {/* Code and Name */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <FormField
                label={t('treasury:repositories.code', 'Code')}
                htmlFor="repository-code"
                required
                error={errors.code?.message}
              >
                <Input
                  id="repository-code"
                  {...register('code', { required: t('common:validation.required') })}
                  placeholder="CASH-01"
                />
              </FormField>

              <FormField
                label={t('treasury:repositories.name', 'Name')}
                htmlFor="repository-name"
                required
                error={errors.name?.message}
              >
                <Input
                  id="repository-name"
                  {...register('name', { required: t('common:validation.required') })}
                  placeholder="Main Cash Register"
                />
              </FormField>
            </div>

            {/* Type */}
            <FormField
              label={t('treasury:repositories.type', 'Type')}
              htmlFor="repository-type"
              required
              error={errors.type?.message}
            >
              <Select
                id="repository-type"
                {...register('type', { required: t('common:validation.required') })}
              >
                <option value="cash_register">{t('treasury:repositories.types.cash_register', 'Cash Register')}</option>
                <option value="safe">{t('treasury:repositories.types.safe', 'Safe')}</option>
                <option value="bank_account">{t('treasury:repositories.types.bank_account', 'Bank Account')}</option>
                <option value="virtual">{t('treasury:repositories.types.virtual', 'Virtual')}</option>
              </Select>
            </FormField>

            {/* Bank-specific fields (conditional) */}
            {isBankAccount && (
              <>
                <FormField
                  label={t('treasury:repositories.bankName', 'Bank Name')}
                  htmlFor="repository-bank-name"
                >
                  <Input
                    id="repository-bank-name"
                    {...register('bank_name')}
                    placeholder="Bank of America"
                  />
                </FormField>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <FormField
                    label={t('treasury:repositories.accountNumber', 'Account Number')}
                    htmlFor="repository-account-number"
                  >
                    <Input
                      id="repository-account-number"
                      {...register('account_number')}
                      placeholder="1234567890"
                    />
                  </FormField>

                  <FormField
                    label={t('treasury:repositories.iban', 'IBAN')}
                    htmlFor="repository-iban"
                  >
                    <Input
                      id="repository-iban"
                      {...register('iban')}
                      placeholder="FR1234567890"
                    />
                  </FormField>
                </div>

                <FormField
                  label={t('treasury:repositories.bic', 'BIC/SWIFT')}
                  htmlFor="repository-bic"
                >
                  <Input
                    id="repository-bic"
                    {...register('bic')}
                    placeholder="BNPAFRPP"
                  />
                </FormField>
              </>
            )}
          </div>

          {/* Error message */}
          {mutation.isError && (
            <div className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
              {mutation.error instanceof Error
                ? mutation.error.message
                : t('common:errorMessages.generic')}
            </div>
          )}
        </ModalContent>

        <ModalFooter>
          <Button
            type="button"
            variant="secondary"
            onClick={onClose}
            disabled={mutation.isPending}
          >
            {t('common:actions.cancel')}
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={mutation.isPending}
          >
            {mutation.isPending && <Loader2 className="h-4 w-4 animate-spin" />}
            {t('common:actions.save')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
