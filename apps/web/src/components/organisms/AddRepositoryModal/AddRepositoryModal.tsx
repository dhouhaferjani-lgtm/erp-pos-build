import { useEffect, useRef } from 'react'
import { useForm, useWatch, type UseFormRegister, type UseFormSetValue } from 'react-hook-form'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { CheckCircle2, CircleAlert, Loader2, TriangleAlert } from 'lucide-react'
import { semanticColorTokens as colorTokens, tokens } from '../../../lib/designTokens'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '../Modal'
import { FormField } from '../../atoms/FormField'
import { Input } from '../../atoms/Input'
import { Select } from '../../atoms/Select'
import { Button } from '../../atoms/Button'
import { apiPost } from '../../../lib/api'
import { useAccounts } from '../../../features/finance/hooks/useAccounts'
import { BankPicker } from '../../molecules/pickers/BankPicker'
import type { Bank } from '@/hooks/useBanks'
import { useBankAccountValidation, type BankAccountValidationResult } from '@/hooks/useBankAccountValidation'
import { useCompanyConfig } from '@/contexts/CompanyConfigContext'
import { useLocations } from '@/features/locations/hooks/useLocations'

interface Repository {
  id: string
  code: string
  name: string
  type: 'cash_register' | 'safe' | 'bank_account' | 'virtual'
  bank_id: string | null
  bank_name: string | null
  account_number: string | null
  iban: string | null
  bic: string | null
  balance: string
  is_active: boolean
  gl_account_id: string | null
}

interface RepositoryFormData {
  code: string
  name: string
  type: string
  bank_id: string
  bank_name: string
  account_number: string
  iban: string
  bic: string
  gl_account_id: string
  location_id: string
  selected_bank: Bank | null
  bank_fallback: boolean
}

interface RepositoryBankFieldsProps {
  countryCode: string
  selectedBank: Bank | null
  bankFallback: boolean
  bankName: string
  ribValidation: BankAccountValidationResult
  ibanValidation: BankAccountValidationResult
  register: UseFormRegister<RepositoryFormData>
  setValue: UseFormSetValue<RepositoryFormData>
}

function RepositoryBankFields({
  countryCode,
  selectedBank,
  bankFallback,
  bankName,
  ribValidation,
  ibanValidation,
  register,
  setValue,
}: RepositoryBankFieldsProps) {
  const { t } = useTranslation('treasury')

  return (
    <>
      <input type="hidden" {...register('bank_id')} />
      <input type="hidden" {...register('bank_name')} />
      <FormField label={t('repositories.bankName')} htmlFor="repository-bank-name">
        <BankPicker
          id="repository-bank-name"
          aria-label={t('repositories.bankName')}
          country={countryCode}
          value={selectedBank}
          isFallback={bankFallback}
          fallbackValue={bankName}
          onFallbackValueChange={(value) => {
            setValue('bank_name', value)
          }}
          onFallbackChange={(isFallback) => {
            setValue('bank_fallback', isFallback)
            setValue('selected_bank', null)
            setValue('bank_id', '')
            setValue('bic', '')
          }}
          onChange={(bank) => {
            setValue('selected_bank', bank)
            setValue('bank_id', bank?.id ?? '')
            setValue('bank_name', bank?.name ?? '')
            setValue('bic', bank?.bic ?? '')
          }}
        />
      </FormField>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <FormField label={t('repositories.accountNumber')} htmlFor="repository-account-number">
          <Input id="repository-account-number" {...register('account_number')} placeholder={t('repositories.accountNumberPlaceholder')} />
          {ribValidation.status === 'valid' ? (
            <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.success.textStrong}`}>
              <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
              {t('repositories.validation.validRib')}
            </p>
          ) : ribValidation.status === 'invalid' && ribValidation.normalized.length >= 20 ? (
            <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.caution.textStrong}`}>
              <TriangleAlert className="h-3.5 w-3.5" aria-hidden />
              {t('repositories.validation.invalidRibWarning')}
            </p>
          ) : ribValidation.status === 'unsupported' ? (
            <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.info.textStrong}`}>
              <CircleAlert className="h-3.5 w-3.5" aria-hidden />
              {t('repositories.validation.unsupportedCountry')}
            </p>
          ) : null}
        </FormField>

        <FormField label={t('repositories.iban')} htmlFor="repository-iban">
          <Input id="repository-iban" {...register('iban')} placeholder={t('repositories.ibanPlaceholder')} />
          {ibanValidation.status === 'valid' ? (
            <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.success.textStrong}`}>
              <CheckCircle2 className="h-3.5 w-3.5" aria-hidden />
              {t('repositories.validation.validIban')}
            </p>
          ) : ibanValidation.status === 'invalid' && ibanValidation.normalized.length >= 24 ? (
            <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.caution.textStrong}`}>
              <TriangleAlert className="h-3.5 w-3.5" aria-hidden />
              {t('repositories.validation.invalidIbanWarning')}
            </p>
          ) : ibanValidation.status === 'unsupported' ? (
            <p className={`mt-1 flex items-center gap-1 text-xs ${colorTokens.intent.info.textStrong}`}>
              <CircleAlert className="h-3.5 w-3.5" aria-hidden />
              {t('repositories.validation.unsupportedCountry')}
            </p>
          ) : null}
        </FormField>
      </div>

      <FormField label={t('repositories.bic')} htmlFor="repository-bic">
        <Input id="repository-bic" {...register('bic')} readOnly={!bankFallback} placeholder={t('repositories.bicPlaceholder')} />
      </FormField>
    </>
  )
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
  const { config } = useCompanyConfig()
  const countryCode = config?.country_code ?? ''
  const autoDerivedIbanRef = useRef('')

  // Form state with React Hook Form
  const { data: accountsData } = useAccounts({ active: true })
  const accounts = accountsData ?? []
  const { data: locations = [] } = useLocations()

  const {
    register,
    control,
    handleSubmit,
    reset,
    setValue,
    formState: { errors },
  } = useForm<RepositoryFormData>({
    defaultValues: {
      code: '',
      name: '',
      type: 'cash_register',
      bank_id: '',
      bank_name: '',
      account_number: '',
      iban: '',
      bic: '',
      gl_account_id: '',
      location_id: '',
      selected_bank: null,
      bank_fallback: false,
    },
  })

  // Reset form when modal opens
  useEffect(() => {
    if (isOpen) {
      reset({
        code: '',
        name: '',
        type: 'cash_register',
        bank_id: '',
        bank_name: '',
        account_number: '',
        iban: '',
        bic: '',
        gl_account_id: '',
        location_id: '',
        selected_bank: null,
        bank_fallback: false,
      })
      autoDerivedIbanRef.current = ''
    }
  }, [isOpen, reset])

  // Watch type to conditionally show bank fields
  const selectedType = useWatch({ control, name: 'type' })
  const isBankAccount = selectedType === 'bank_account'
  const canAssignLocation = selectedType === 'cash_register' || selectedType === 'safe'
  const accountNumber = useWatch({ control, name: 'account_number' })
  const iban = useWatch({ control, name: 'iban' })
  const bankName = useWatch({ control, name: 'bank_name' })
  const selectedBank = useWatch({ control, name: 'selected_bank' })
  const bankFallback = useWatch({ control, name: 'bank_fallback' })
  const ribValidation = useBankAccountValidation(accountNumber, countryCode, 'rib')
  const ibanValidation = useBankAccountValidation(iban, countryCode, 'iban')

  useEffect(() => {
    const nextIban = ribValidation.status === 'valid' ? ribValidation.derivedIban ?? '' : ''
    if (nextIban !== '') {
      if (iban === '' || iban === autoDerivedIbanRef.current) {
        setValue('iban', nextIban)
        autoDerivedIbanRef.current = nextIban
      }
    } else if (autoDerivedIbanRef.current !== '' && iban === autoDerivedIbanRef.current) {
      setValue('iban', '')
      autoDerivedIbanRef.current = ''
    }
  }, [iban, ribValidation.derivedIban, ribValidation.status, setValue])

  // React Query mutation
  const mutation = useMutation({
    mutationFn: (data: RepositoryFormData) => {
      // Transform data for API
      const payload = {
        code: data.code,
        name: data.name,
        type: data.type,
        bank_id: data.bank_id || null,
        bank_name: data.bank_name || null,
        account_number: data.account_number || null,
        iban: data.iban || null,
        bic: data.bic || null,
        gl_account_id: data.gl_account_id || null,
        ...(data.location_id !== '' ? { location_id: data.location_id } : {}),
      }
      return apiPost<Repository>('/payment-repositories', payload)
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['payment-repositories'] })
      onSuccess?.(response)
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
                  placeholder={t('treasury:repositories.codePlaceholder')}
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
                  placeholder={t('treasury:repositories.namePlaceholder')}
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

            {/* GL Account */}
            <FormField
              label={t('treasury:repositories.glAccount', 'GL Account')}
              htmlFor="repository-gl-account"
            >
              <Select
                id="repository-gl-account"
                {...register('gl_account_id')}
              >
                <option value="">{t('treasury:repositories.noGlAccount', '— None —')}</option>
                {accounts.map((acc) => (
                  <option key={acc.id} value={acc.id}>
                    {acc.code} - {acc.name}
                  </option>
                ))}
              </Select>
            </FormField>

            {canAssignLocation && (
              <FormField label={t('treasury:repositories.location', 'Location')} htmlFor="repository-location">
                <Select id="repository-location" {...register('location_id')}>
                  <option value="">{t('treasury:repositories.selectLocation', 'Select location')}</option>
                  {locations.filter((location) => location.isActive).map((location) => (
                    <option key={location.id} value={location.id}>{location.name}</option>
                  ))}
                </Select>
              </FormField>
            )}

            {/* Bank-specific fields (conditional) */}
            {isBankAccount && (
              <RepositoryBankFields
                countryCode={countryCode}
                selectedBank={selectedBank}
                bankFallback={bankFallback}
                bankName={bankName}
                ribValidation={ribValidation}
                ibanValidation={ibanValidation}
                register={register}
                setValue={setValue}
              />
            )}
          </div>

          {/* Error message */}
          {mutation.isError && (
            <div className={`mt-4 rounded-lg p-3 text-sm ${tokens.alert.error}`}>
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
