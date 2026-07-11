import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { tokens } from '../../../../lib/designTokens'
import { Button, Input, Select, Textarea } from '@/components/atoms'
import { useCreateBundle, useUpdateBundle } from '../../hooks/useBundles'
import type { BundlePricingMode, ServiceBundleData } from '../../types'

interface BundleFormProps {
  initial?: ServiceBundleData
  defaultCurrency?: string
}

export function BundleForm({
  initial,
  defaultCurrency = 'TND',
}: BundleFormProps) {
  const { t } = useTranslation('workshop-bundles')
  const navigate = useNavigate()

  const [code, setCode] = useState(initial?.code ?? '')
  const [name, setName] = useState(initial?.name ?? '')
  const [description, setDescription] = useState(initial?.description ?? '')
  const [pricingMode, setPricingMode] = useState<BundlePricingMode>(
    initial?.pricing_mode ?? 'standard',
  )

  const parsePricingMode = (value: string): BundlePricingMode => {
    return value === 'fixed_bundle' ? 'fixed_bundle' : 'standard'
  }
  const [basePrice, setBasePrice] = useState(initial?.base_price ?? '')
  const [currency, setCurrency] = useState(initial?.currency ?? defaultCurrency)
  const [taxRate, setTaxRate] = useState(initial?.tax_rate ?? '19')
  const [estimatedLaborHours, setEstimatedLaborHours] = useState(
    initial?.estimated_labor_hours ?? '',
  )
  const [serviceIntervalKm, setServiceIntervalKm] = useState(
    initial?.service_interval_km?.toString() ?? '',
  )
  const [serviceIntervalMonths, setServiceIntervalMonths] = useState(
    initial?.service_interval_months?.toString() ?? '',
  )

  const createMutation = useCreateBundle()
  const updateMutation = useUpdateBundle(initial?.id ?? '')

  const isUpdating = initial !== undefined
  const mutation = isUpdating ? updateMutation : createMutation

  const handleSubmit = (e: FormEvent): void => {
    e.preventDefault()
    const payload = {
      code,
      name,
      description: description === '' ? null : description,
      pricing_mode: pricingMode,
      base_price: basePrice === '' ? null : basePrice,
      currency,
      tax_rate: taxRate === '' ? null : taxRate,
      estimated_labor_hours: estimatedLaborHours === '' ? null : estimatedLaborHours,
      service_interval_km:
        serviceIntervalKm === '' ? null : Number.parseInt(serviceIntervalKm, 10),
      service_interval_months:
        serviceIntervalMonths === '' ? null : Number.parseInt(serviceIntervalMonths, 10),
    }

    if (isUpdating) {
      updateMutation.mutate(payload, {
        onSuccess: (bundle) => {
          void navigate(`/workshop/bundles/${bundle.id}`)
        },
      })
    } else {
      createMutation.mutate(payload, {
        onSuccess: (bundle) => {
          void navigate(`/workshop/bundles/${bundle.id}`)
        },
      })
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="grid gap-4 md:grid-cols-2">
        <div>
          <label className={tokens.label.base}>
            {t('form.code')}
          </label>
          <Input
            type="text"
            value={code}
            onChange={(e) => { setCode(e.target.value) }}
            required
            disabled={isUpdating}
          />
        </div>
        <div>
          <label className={tokens.label.base}>
            {t('form.name')}
          </label>
          <Input
            type="text"
            value={name}
            onChange={(e) => { setName(e.target.value) }}
            required
          />
        </div>
      </div>

      <div>
        <label className={tokens.label.base}>
          {t('form.description')}
        </label>
        <Textarea
          value={description}
          onChange={(e) => { setDescription(e.target.value) }}
          rows={2}
        />
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <div>
          <label className={tokens.label.base}>
            {t('form.pricingMode')}
          </label>
          <Select
            value={pricingMode}
            onChange={(e) => { setPricingMode(parsePricingMode(e.target.value)) }}
          >
            <option value="standard">{t('form.pricingMode.standard')}</option>
            <option value="fixed_bundle">{t('form.pricingMode.fixedBundle')}</option>
          </Select>
        </div>
        <div>
          <label className={tokens.label.base}>
            {t('form.basePrice')}
          </label>
          <Input
            type="text"
            value={basePrice}
            onChange={(e) => { setBasePrice(e.target.value) }}
            disabled={pricingMode === 'standard'}
          />
        </div>
        <div>
          <label className={tokens.label.base}>
            {t('form.currency')}
          </label>
          <Input
            type="text"
            value={currency}
            onChange={(e) => { setCurrency(e.target.value.toUpperCase()) }}
            maxLength={3}
            required
          />
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <div>
          <label className={tokens.label.base}>
            {t('form.taxRate')}
          </label>
          <Input
            type="text"
            value={taxRate}
            onChange={(e) => { setTaxRate(e.target.value) }}
          />
        </div>
        <div>
          <label className={tokens.label.base}>
            {t('form.estimatedLaborHours')}
          </label>
          <Input
            type="text"
            value={estimatedLaborHours}
            onChange={(e) => { setEstimatedLaborHours(e.target.value) }}
          />
        </div>
        <div />
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <div>
          <label className={tokens.label.base}>
            {t('form.serviceIntervalKm')}
          </label>
          <Input
            type="number"
            value={serviceIntervalKm}
            onChange={(e) => { setServiceIntervalKm(e.target.value) }}
          />
        </div>
        <div>
          <label className={tokens.label.base}>
            {t('form.serviceIntervalMonths')}
          </label>
          <Input
            type="number"
            value={serviceIntervalMonths}
            onChange={(e) => { setServiceIntervalMonths(e.target.value) }}
          />
        </div>
      </div>

      <div className="flex items-center justify-end gap-2">
        <Button
          type="button"
          variant="secondary"
          size="sm"
          onClick={() => { void navigate(-1) }}
        >
          {t('form.cancel')}
        </Button>
        <Button
          type="submit"
          variant="primary"
          size="sm"
          disabled={mutation.isPending}
        >
          {mutation.isPending
            ? t('form.saving')
            : isUpdating
              ? t('form.save')
              : t('form.create')}
        </Button>
      </div>
    </form>
  )
}
