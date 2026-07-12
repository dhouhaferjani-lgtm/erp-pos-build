/* eslint-disable @typescript-eslint/restrict-template-expressions -- React Hook Form field-array paths require numeric indices. */
import { useEffect, useRef, useState } from 'react'
import { CheckCircle, Plus, Trash2, TriangleAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useFieldArray, useWatch } from 'react-hook-form'
import type { Control, UseFormRegister, UseFormSetValue } from 'react-hook-form'
import { Button } from '@/components/atoms/Button'
import { Checkbox } from '@/components/atoms/Checkbox'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { BankPicker } from '@/components/molecules/pickers/BankPicker'
import type { Bank } from '@/hooks/useBanks'
import { useBankAccountValidation } from '@/hooks/useBankAccountValidation'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import type { PartnerFormData } from '../PartnerForm'

interface PartnerBankAccountsSectionProps {
  control: Control<PartnerFormData>
  register: UseFormRegister<PartnerFormData>
  setValue: UseFormSetValue<PartnerFormData>
  country: string
  defaultCurrency: string
}

interface PartnerBankAccountRowProps extends PartnerBankAccountsSectionProps {
  index: number
  onRemove: () => void
  onMakePrimary: () => void
}

function PartnerBankAccountRow({
  control,
  register,
  setValue,
  country,
  index,
  onRemove,
  onMakePrimary,
}: PartnerBankAccountRowProps) {
  const { t } = useTranslation('sales')
  const bankId = useWatch({ control, name: `bank_accounts.${index}.bank_id` })
  const bankName = useWatch({ control, name: `bank_accounts.${index}.bank_name` })
  const bic = useWatch({ control, name: `bank_accounts.${index}.bic` })
  const rib = useWatch({ control, name: `bank_accounts.${index}.rib` })
  const iban = useWatch({ control, name: `bank_accounts.${index}.iban` })
  const isPrimary = useWatch({ control, name: `bank_accounts.${index}.is_primary` })
  const validation = useBankAccountValidation(rib, country, 'rib')
  const [isFallback, setIsFallback] = useState(bankId.length === 0 && bankName.length > 0)
  const autoDerivedIbanRef = useRef('')

  const selectedBank: Bank | null = bankId.length === 0 || bankName.length === 0
    ? null
    : {
        id: bankId,
        country_code: country,
        name: bankName,
        short_name: null,
        bic,
        rib_bank_code: null,
        city: null,
        is_custom: false,
      }

  // Write the derived IBAN while the RIB is valid, and clear it again when the RIB
  // becomes invalid — but only touch the field while it still holds our auto-derived
  // value (or is empty), so a user-typed IBAN is never clobbered. Otherwise a RIB
  // edited valid→invalid would submit a stale IBAN that no longer matches the RIB.
  useEffect(() => {
    const nextIban = validation.status === 'valid' ? validation.derivedIban ?? '' : ''
    if (nextIban !== '') {
      if (iban === '' || iban === autoDerivedIbanRef.current) {
        setValue(`bank_accounts.${index}.iban`, nextIban, { shouldDirty: true })
        autoDerivedIbanRef.current = nextIban
      }
    } else if (autoDerivedIbanRef.current !== '' && iban === autoDerivedIbanRef.current) {
      setValue(`bank_accounts.${index}.iban`, '', { shouldDirty: true })
      autoDerivedIbanRef.current = ''
    }
  }, [iban, index, setValue, validation.derivedIban, validation.status])

  return (
    <div className={`space-y-4 rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-4`}>
      <div className="flex items-center justify-between gap-3">
        <span className={`text-sm font-semibold ${colorTokens.text.primary}`}>
          {t('partners.bankAccounts.accountNumber', { number: index + 1 })}
        </span>
        <Button type="button" variant="ghost" size="xs" onClick={onRemove} aria-label={t('partners.bankAccounts.remove')}>
          <Trash2 className="h-4 w-4" aria-hidden />
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label={t('partners.bankAccounts.label')} htmlFor={`partner-bank-label-${index}`}>
          <Input id={`partner-bank-label-${index}`} {...register(`bank_accounts.${index}.label`)} />
        </FormField>
        <FormField label={t('partners.bankAccounts.bank')} htmlFor={`partner-bank-picker-${index}`}>
          <BankPicker
            id={`partner-bank-picker-${index}`}
            aria-label={t('partners.bankAccounts.bank')}
            country={country}
            value={selectedBank}
            onChange={(bank) => {
              setValue(`bank_accounts.${index}.bank_id`, bank?.id ?? '', { shouldDirty: true })
              setValue(`bank_accounts.${index}.bank_name`, bank?.name ?? '', { shouldDirty: true })
              setValue(`bank_accounts.${index}.bic`, bank?.bic ?? '', { shouldDirty: true })
            }}
            isFallback={isFallback}
            fallbackValue={bankName}
            onFallbackChange={(fallback) => {
              setIsFallback(fallback)
              if (fallback) setValue(`bank_accounts.${index}.bank_id`, '', { shouldDirty: true })
            }}
            onFallbackValueChange={(value) => {
              setValue(`bank_accounts.${index}.bank_name`, value, { shouldDirty: true })
            }}
          />
        </FormField>
        <FormField label={t('partners.bankAccounts.rib')} htmlFor={`partner-bank-rib-${index}`}>
          <Input id={`partner-bank-rib-${index}`} {...register(`bank_accounts.${index}.rib`)} />
          {validation.status === 'valid' ? (
            <span className={`mt-1 flex items-center gap-1 text-sm ${colorTokens.intent.success.text}`}>
              <CheckCircle className="h-4 w-4" aria-hidden />
              {t('partners.bankAccounts.validRib')}
            </span>
          ) : validation.status === 'invalid' ? (
            <span className={`mt-1 flex items-center gap-1 text-sm ${colorTokens.intent.caution.textStrong}`}>
              <TriangleAlert className="h-4 w-4" aria-hidden />
              {t('partners.bankAccounts.invalidRib')}
            </span>
          ) : null}
        </FormField>
        <FormField label={t('partners.bankAccounts.iban')} htmlFor={`partner-bank-iban-${index}`}>
          <Input id={`partner-bank-iban-${index}`} readOnly {...register(`bank_accounts.${index}.iban`)} />
        </FormField>
        <FormField label={t('partners.bankAccounts.bic')} htmlFor={`partner-bank-bic-${index}`}>
          <Input id={`partner-bank-bic-${index}`} readOnly={!isFallback} {...register(`bank_accounts.${index}.bic`)} />
        </FormField>
        <FormField label={t('partners.bankAccounts.currency')} htmlFor={`partner-bank-currency-${index}`}>
          <Input id={`partner-bank-currency-${index}`} maxLength={3} {...register(`bank_accounts.${index}.currency`)} />
        </FormField>
      </div>

      <label className={`flex items-center gap-2 text-sm ${colorTokens.text.secondary}`}>
        <Checkbox
          checked={isPrimary}
          aria-label={t('partners.bankAccounts.primary')}
          onChange={() => { onMakePrimary() }}
        />
        {t('partners.bankAccounts.primary')}
      </label>
    </div>
  )
}

export function PartnerBankAccountsSection({
  control,
  register,
  setValue,
  country,
  defaultCurrency,
}: PartnerBankAccountsSectionProps) {
  const { t } = useTranslation('sales')
  const { fields, append, remove } = useFieldArray({ control, name: 'bank_accounts' })

  return (
    <section className={`space-y-4 border-t ${colorTokens.border.subtle} pt-6`}>
      <div className="flex items-center justify-between gap-4">
        <div>
          <h4 className={`font-semibold ${colorTokens.text.primary}`}>{t('partners.bankAccounts.title')}</h4>
          <p className={`text-sm ${colorTokens.text.subtle}`}>{t('partners.bankAccounts.hint')}</p>
        </div>
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={() => {
            append({
              label: '',
              bank_id: '',
              bank_name: '',
              rib: '',
              iban: '',
              bic: '',
              currency: defaultCurrency,
              is_primary: fields.length === 0,
            })
          }}
        >
          <Plus className="me-2 h-4 w-4" aria-hidden />
          {t('partners.bankAccounts.add')}
        </Button>
      </div>

      {fields.length === 0 ? (
        <p className={`text-sm ${colorTokens.text.subtle}`}>{t('partners.bankAccounts.empty')}</p>
      ) : fields.map((field, index) => (
        <PartnerBankAccountRow
          key={field.id}
          control={control}
          register={register}
          setValue={setValue}
          country={country}
          defaultCurrency={defaultCurrency}
          index={index}
          onRemove={() => { remove(index) }}
          onMakePrimary={() => {
            fields.forEach((_, rowIndex) => {
              setValue(`bank_accounts.${rowIndex}.is_primary`, rowIndex === index, { shouldDirty: true })
            })
          }}
        />
      ))}
    </section>
  )
}
