import { useState, type FormEvent } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, ClipboardList } from 'lucide-react'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { getErrorMessage } from '@/lib/api'
import { useCreateWorkOrder } from '../hooks/useWorkOrders'
import type { CreateWorkOrderInput, WorkOrderType } from '../types'

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
  const [submitError, setSubmitError] = useState<string | null>(null)

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    setSubmitError(null)
    create.mutate(form, {
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
        className={`inline-flex items-center gap-1 text-sm ${textColors.tertiary} hover:text-slate-900`}
      >
        <ArrowLeft className="h-4 w-4" aria-hidden />
        {t('detail.backToList')}
      </Link>

      <div>
        <h1 className={`flex items-center gap-2 text-2xl font-semibold ${textColors.primary}`}>
          <ClipboardList className={`h-6 w-6 ${textColors.tertiary}`} aria-hidden />
          {t('create.title')}
        </h1>
        <p className={`mt-1 text-sm ${textColors.tertiary}`}>{t('create.subtitle')}</p>
      </div>

      <form
        onSubmit={handleSubmit}
        className={`space-y-4 rounded-lg border bg-white p-6 ${borderColors.light}`}
      >
        <div>
          <label className={`block text-sm font-medium ${textColors.secondary}`}>
            {t('fields.type')}
            <select
              value={form.type}
              onChange={(e) => {
                const value = e.target.value
                if (isWorkOrderType(value)) {
                  setForm({ ...form, type: value })
                }
              }}
              className={tokens.select.base}
            >
              {WORK_ORDER_TYPES.map((type) => (
                <option key={type} value={type}>
                  {t(`workOrderType.${type}`)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <div>
          <label className={`block text-sm font-medium ${textColors.secondary}`}>
            {t('fields.customerPartnerId')}
            <input
              type="text"
              value={form.customer_partner_id}
              onChange={(e) => {
                setForm({ ...form, customer_partner_id: e.target.value })
              }}
              placeholder="UUID"
              className={tokens.input.base}
              required
            />
          </label>
        </div>
        <div>
          <label className={`block text-sm font-medium ${textColors.secondary}`}>
            {t('fields.vehicleId')}
            <input
              type="text"
              value={form.vehicle_id}
              onChange={(e) => {
                setForm({ ...form, vehicle_id: e.target.value })
              }}
              placeholder="UUID"
              className={tokens.input.base}
              required
            />
          </label>
        </div>
        <div>
          <label className={`block text-sm font-medium ${textColors.secondary}`}>
            {t('fields.currency')}
            <input
              type="text"
              value={form.currency}
              onChange={(e) => {
                setForm({ ...form, currency: e.target.value.toUpperCase() })
              }}
              maxLength={3}
              minLength={3}
              className={tokens.input.base}
              required
            />
          </label>
        </div>
        <div>
          <label className={`block text-sm font-medium ${textColors.secondary}`}>
            {t('fields.customerComplaint')}
            <textarea
              value={form.customer_complaint ?? ''}
              onChange={(e) => {
                setForm({ ...form, customer_complaint: e.target.value })
              }}
              className={tokens.input.base}
              rows={3}
            />
          </label>
        </div>

        {submitError !== null && (
          <div
            role="alert"
            className="rounded-md border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700"
          >
            {submitError}
          </div>
        )}

        <div className="flex justify-end gap-2">
          <Link
            to="/workshop/work-orders"
            className="inline-flex items-center rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
          >
            {t('actions.cancel')}
          </Link>
          <button
            type="submit"
            disabled={create.isPending}
            className="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white shadow-sm hover:bg-slate-800 disabled:opacity-50"
          >
            {create.isPending ? t('actions.creating') : t('actions.create')}
          </button>
        </div>
      </form>
    </div>
  )
}
