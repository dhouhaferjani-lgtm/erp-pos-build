import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useCreateWithholdingRule, useUpdateWithholdingRule } from '../hooks/useWithholding'
import { Button } from '@/components/atoms/Button'
import { Checkbox } from '@/components/atoms'
import { FormField } from '@/components/atoms/FormField'
import { Input } from '@/components/atoms/Input'
import { Select } from '@/components/atoms/Select'
import { Textarea } from '@/components/atoms/Textarea'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { textColors, borderColors } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { WithholdingRule, CreateWithholdingRuleRequest } from '../types'

interface WithholdingRuleFormModalProps {
  isOpen: boolean
  onClose: () => void
  rule?: WithholdingRule | null
  countryCode?: string
}

export function WithholdingRuleFormModal({
  isOpen,
  onClose,
  rule,
  countryCode = 'TN',
}: WithholdingRuleFormModalProps) {
  const { t } = useTranslation(['withholding', 'common'])
  const createMutation = useCreateWithholdingRule()
  const updateMutation = useUpdateWithholdingRule()

  const [formData, setFormData] = useState<CreateWithholdingRuleRequest>({
    country_code: countryCode,
    code: '',
    name: '',
    description: '',
    transaction_type: undefined,
    partner_tax_status: undefined,
    min_amount: '',
    rate: 0,
    effective_from: new Date().toISOString().split('T')[0],
    effective_to: '',
    is_active: true,
  })

  // Load rule data when editing
  useEffect(() => {
    if (rule) {
      setFormData({
        country_code: rule.country_code,
        code: rule.code,
        name: rule.name,
        description: rule.description || '',
        transaction_type: rule.transaction_type || undefined,
        partner_tax_status: rule.partner_tax_status || undefined,
        min_amount: rule.min_amount || '',
        rate: Number(rule.rate_percentage),
        effective_from: rule.effective_from.split('T')[0],
        effective_to: rule.effective_to?.split('T')[0] || '',
        is_active: rule.is_active,
      })
    } else {
      // Reset form for new rule
      setFormData({
        country_code: countryCode,
        code: '',
        name: '',
        description: '',
        transaction_type: undefined,
        partner_tax_status: undefined,
        min_amount: '',
        rate: 0,
        effective_from: new Date().toISOString().split('T')[0],
        effective_to: '',
        is_active: true,
      })
    }
  }, [rule, countryCode])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    try {
      if (rule) {
        await updateMutation.mutateAsync({
          id: rule.id,
          data: formData,
        })
      } else {
        await createMutation.mutateAsync(formData)
      }
      onClose()
    } catch {
      // Error handled by mutation hooks
    }
  }

  const handleChange = (
    field: keyof CreateWithholdingRuleRequest,
    value: string | number | boolean,
  ) => {
    setFormData((prev) => ({
      ...prev,
      [field]: value === '' ? undefined : value,
    }))
  }

  const isLoading = createMutation.isPending || updateMutation.isPending

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={rule ? t('rules.editRule') : t('rules.createRule')}
      size="lg"
    >
      <form onSubmit={handleSubmit}>
        <ModalContent className="space-y-6">
          {/* Basic Info */}
          <div className="space-y-4">
            <h3 className={cn('text-sm font-medium', textColors.primary)}>
              {t('rules.basicInfo')}
            </h3>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('rules.code')} htmlFor="rule-code" required>
                <Input
                  id="rule-code"
                  type="text"
                  value={formData.code}
                  onChange={(e) => { handleChange('code', e.target.value); }}
                  required
                  placeholder={t('withholding:rules.codePlaceholder')}
                />
              </FormField>

              <FormField label={t('rules.ratePercentage')} htmlFor="rule-rate" required>
                <Input
                  id="rule-rate"
                  type="number"
                  value={formData.rate}
                  onChange={(e) => { handleChange('rate', parseFloat(e.target.value)); }}
                  required
                  min="0"
                  max="100"
                  step="any"
                  placeholder="10.00"
                />
              </FormField>
            </div>

            <FormField label={t('rules.name')} htmlFor="rule-name" required>
              <Input
                id="rule-name"
                type="text"
                value={formData.name}
                onChange={(e) => { handleChange('name', e.target.value); }}
                required
                placeholder={t('rules.namePlaceholder')}
              />
            </FormField>

            <FormField label={t('rules.description')} htmlFor="rule-description">
              <Textarea
                id="rule-description"
                value={formData.description}
                onChange={(e) => { handleChange('description', e.target.value); }}
                rows={2}
                placeholder={t('rules.descriptionPlaceholder')}
              />
            </FormField>
          </div>

          {/* Conditions */}
          <div className="space-y-4">
            <h3 className={cn('text-sm font-medium', textColors.primary)}>
              {t('rules.conditions')}
            </h3>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('rules.transactionType')} htmlFor="rule-transaction-type">
                <Select
                  id="rule-transaction-type"
                  value={formData.transaction_type || ''}
                  onChange={(e) => { handleChange('transaction_type', e.target.value); }}
                >
                  <option value="">{t('common:all')}</option>
                  <option value="services">{t('transactionTypes.services')}</option>
                  <option value="goods">{t('transactionTypes.goods')}</option>
                  <option value="professional_services">{t('transactionTypes.professional_services')}</option>
                  <option value="rent">{t('transactionTypes.rent')}</option>
                </Select>
              </FormField>

              <FormField label={t('rules.partnerTaxStatus')} htmlFor="rule-partner-tax-status">
                <Select
                  id="rule-partner-tax-status"
                  value={formData.partner_tax_status || ''}
                  onChange={(e) => { handleChange('partner_tax_status', e.target.value); }}
                >
                  <option value="">{t('common:all')}</option>
                  <option value="REGISTERED">{t('taxStatuses.registered')}</option>
                  <option value="NON_REGISTERED">{t('taxStatuses.nonRegistered')}</option>
                  <option value="EXEMPT">{t('taxStatuses.exempt')}</option>
                </Select>
              </FormField>
            </div>

            <FormField
              label={t('rules.minAmount')}
              htmlFor="rule-min-amount"
              helperText={t('rules.minAmountHelp')}
            >
              <Input
                id="rule-min-amount"
                type="number"
                value={formData.min_amount}
                onChange={(e) => { handleChange('min_amount', e.target.value); }}
                min="0"
                step="any"
                placeholder="0.000"
              />
            </FormField>
          </div>

          {/* Validity Period */}
          <div className="space-y-4">
            <h3 className={cn('text-sm font-medium', textColors.primary)}>
              {t('rules.validityPeriod')}
            </h3>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('rules.effectiveFrom')} htmlFor="rule-effective-from" required>
                <Input
                  id="rule-effective-from"
                  type="date"
                  value={formData.effective_from}
                  onChange={(e) => { handleChange('effective_from', e.target.value); }}
                  required
                />
              </FormField>

              <FormField
                label={t('rules.effectiveTo')}
                htmlFor="rule-effective-to"
                helperText={t('rules.effectiveToHelp')}
              >
                <Input
                  id="rule-effective-to"
                  type="date"
                  value={formData.effective_to}
                  onChange={(e) => { handleChange('effective_to', e.target.value); }}
                />
              </FormField>
            </div>

            <div className="flex items-center gap-2">
              <Checkbox
                id="is_active"
                checked={formData.is_active}
                onChange={(e) => { handleChange('is_active', e.target.checked); }}
              />
              <label htmlFor="is_active" className={cn('text-sm', textColors.secondary)}>
                {t('rules.isActive')}
              </label>
            </div>
          </div>
        </ModalContent>

        <ModalFooter className={cn('border-t pt-4', borderColors.light)}>
          <Button
            type="button"
            variant="secondary"
            onClick={onClose}
            disabled={isLoading}
          >
            {t('common:cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={isLoading}>
            {isLoading ? t('common:saving') : rule ? t('common:save') : t('common:actions.create')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
