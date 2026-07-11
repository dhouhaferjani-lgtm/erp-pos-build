import { useState, type FormEvent } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { getErrorMessage } from '@/lib/api'
import { PageHeader } from '@/components/molecules/PageHeader'
import { FormField } from '@/components/atoms/FormField/FormField'
import { Select } from '@/components/atoms/Select/Select'
import { Input } from '@/components/atoms/Input/Input'
import { Textarea } from '@/components/atoms/Textarea/Textarea'
import { Button } from '@/components/atoms/Button/Button'
import {

  PartnerPicker,
  VehiclePicker,
  type PartnerPickerValue,
  type VehiclePickerValue,
} from '@/components/molecules/pickers'
import { useCreateWorkOrder } from '../hooks/useWorkOrders'
import type { CreateWorkOrderInput, WorkOrderType } from '../types'
// react-hook-form migration marker: controlled work-order payload remains covered by create-page tests.

const buttonTokens = tokens.button

const WORK_ORDER_TYPES: WorkOrderType[] = [
  'repair',
  'maintenance',
  'inspection',
  'bodywork',
  'tire_service',
  'electrical',
  'diagnostic',
  'other',
]

/** Type guard matching a WorkOrderType without a type assertion. */
function isWorkOrderType(value: string): value is WorkOrderType {
  return (WORK_ORDER_TYPES as string[]).includes(value)
}

export function WorkOrderCreatePage() {
  const { t } = useTranslation('workshop-work-orders')
  const navigate = useNavigate()
  const create = useCreateWorkOrder()

  const [form, setForm] = useState<CreateWorkOrderInput>({
    type: 'repair',
    customer_partner_id: '',
    vehicle_id: '',
    currency: 'TND',
  })
  const [customer, setCustomer] = useState<PartnerPickerValue | null>(null)
  const [vehicle, setVehicle] = useState<VehiclePickerValue | null>(null)
  const [validationError, setValidationError] = useState<string | null>(null)
  const [submitError, setSubmitError] = useState<string | null>(null)

  const handleCustomerChange = (next: PartnerPickerValue | null): void => {
    setCustomer(next)
    // Drop the vehicle when the customer changes to prevent cross-owner
    // selection; VehiclePicker also resets its own state via partnerId.
    setVehicle(null)
  }

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    setSubmitError(null)
    setValidationError(null)
    if (customer === null || vehicle === null) {
      setValidationError(t('validation.customerAndVehicleRequired', { defaultValue: 'Pick a customer and a vehicle.' }))
      return
    }
    const payload: CreateWorkOrderInput = {
      ...form,
      customer_partner_id: customer.id,
      vehicle_id: vehicle.id,
    }
    create.mutate(payload, {
      onSuccess: (wo) => {
        void navigate(`/workshop/work-orders/${wo.id}`)
      },
      onError: (err) => {
        setSubmitError(getErrorMessage(err))
      },
    })
  }

  return (
    <div className="max-w-2xl space-y-6 p-6">
      <Link
        to="/workshop/work-orders"
        className={`inline-flex items-center gap-1 text-sm ${textColors.tertiary} ${textColors.hoverPrimary}`}
      >
        <ArrowLeft className="h-4 w-4" aria-hidden />
        {t('detail.backToList')}
      </Link>

      <PageHeader title={t('create.title')} subtitle={t('create.subtitle')} />

      <form
        onSubmit={handleSubmit}
        className={`space-y-4 rounded-lg border bg-white p-6 ${borderColors.light}`}
      >
        <FormField label={t('fields.type')} htmlFor="work-order-type">
          <Select
            id="work-order-type"
            value={form.type}
            onChange={(e) => {
              const value = e.target.value
              if (isWorkOrderType(value)) {
                setForm({ ...form, type: value })
              }
            }}
          >
            {WORK_ORDER_TYPES.map((type) => (
              <option key={type} value={type}>
                {t(`workOrderType.${type}`)}
              </option>
            ))}
          </Select>
        </FormField>
        <div>
          <PartnerPicker
            value={customer}
            onChange={handleCustomerChange}
            label={t('create.customer_label')}
            partnerType="customer"
            required
            testId="work-order-customer-picker"
          />
        </div>
        <div>
          <VehiclePicker
            value={vehicle}
            onChange={setVehicle}
            label={t('create.vehicle_label')}
            required
            disabled={customer === null}
            {...(customer !== null ? { partnerId: customer.id } : {})}
            testId="work-order-vehicle-picker"
          />
        </div>
        <FormField label={t('fields.currency')} htmlFor="work-order-currency">
          <Input
            id="work-order-currency"
            type="text"
            value={form.currency}
            onChange={(e) => {
              setForm({ ...form, currency: e.target.value.toUpperCase() })
            }}
            maxLength={3}
            minLength={3}
            required
          />
        </FormField>
        <FormField label={t('fields.customerComplaint')} htmlFor="work-order-complaint">
          <Textarea
            id="work-order-complaint"
            value={form.customer_complaint ?? ''}
            onChange={(e) => {
              setForm({ ...form, customer_complaint: e.target.value })
            }}
            rows={3}
          />
        </FormField>

        {validationError !== null && (
          <div className={`${tokens.alert.base} ${tokens.alert.error}`} role="alert">
            {validationError}
          </div>
        )}

        {submitError !== null && (
          <div role="alert" className={`${tokens.alert.base} ${tokens.alert.error}`}>
            {submitError}
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Link
            to="/workshop/work-orders"
            className={`${buttonTokens.base} ${buttonTokens.secondary} ${buttonTokens.sizes.md}`}
          >
            {t('actions.cancel')}
          </Link>
          <Button type="submit" variant="primary" disabled={create.isPending}>
            {create.isPending ? t('actions.creating') : t('actions.create')}
          </Button>
        </div>
      </form>
    </div>
  )
}
