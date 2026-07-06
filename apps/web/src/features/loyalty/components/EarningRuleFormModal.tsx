import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, Controller, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input, FormField, Select, QuantityInput } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { EarningRule, CreateEarningRuleData, EarningRuleConditions } from '../types/loyalty'

type ValidationFieldErrors = Record<string, string>

interface ParsedServerError {
  message: string
  fieldErrors: ValidationFieldErrors
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function firstErrorMessage(value: unknown): string | undefined {
  if (Array.isArray(value)) {
    const [first] = value
    return typeof first === 'string' ? first : undefined
  }

  return typeof value === 'string' ? value : undefined
}

function parseServerError(error: unknown): ParsedServerError | null {
  if (!isRecord(error)) {
    return null
  }

  const response = error['response']
  if (!isRecord(response)) {
    return null
  }

  const data = response['data']
  if (!isRecord(data)) {
    return null
  }

  const envelope = data['error']
  if (!isRecord(envelope)) {
    return null
  }

  const message = typeof envelope['message'] === 'string'
    ? envelope['message']
    : 'An unexpected error occurred'
  const fieldErrors: ValidationFieldErrors = {}
  const errors = envelope['errors']

  if (isRecord(errors)) {
    for (const [field, fieldError] of Object.entries(errors)) {
      const errorMessage = firstErrorMessage(fieldError)
      if (errorMessage !== undefined) {
        fieldErrors[field] = errorMessage
      }
    }
  }

  return { message, fieldErrors }
}

function fieldError(fieldErrors: ValidationFieldErrors, field: string): string | undefined {
  return fieldErrors[field]
}

const schema = z.object({
  name: z.string().min(1),
  rule_type: z.enum(['spend', 'item', 'category', 'quantity', 'visit', 'threshold', 'time']),
  priority: z.coerce.number().int().min(1),
  reward_type: z.enum(['fixed', 'multiplier', 'percentage']),
  reward_value: z.string().min(1),
  min_purchase_amount: z.string().optional().nullable(),
  max_earn_per_transaction: z.string().optional().nullable(),
  max_earn_per_day: z.string().optional().nullable(),
  start_date: z.string().optional().nullable(),
  end_date: z.string().optional().nullable(),
})

type FormValues = z.infer<typeof schema>

interface EarningRuleFormModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (data: CreateEarningRuleData) => void
  isPending: boolean
  editingRule: EarningRule | null
  serverError?: unknown
}

export function EarningRuleFormModal({
  isOpen,
  onClose,
  onSubmit,
  isPending,
  editingRule,
  serverError,
}: EarningRuleFormModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])
  const parsedServerError = parseServerError(serverError)
  const fieldErrors = parsedServerError?.fieldErrors ?? {}

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      name: '',
      rule_type: 'spend',
      priority: 1,
      reward_type: 'fixed',
      reward_value: '',
      min_purchase_amount: null,
      max_earn_per_transaction: null,
      max_earn_per_day: null,
      start_date: null,
      end_date: null,
    },
  })

  useEffect(() => {
    if (editingRule) {
      form.reset({
        name: editingRule.name,
        rule_type: editingRule.rule_type,
        priority: editingRule.priority,
        reward_type: editingRule.reward_type,
        reward_value: editingRule.reward_value,
        min_purchase_amount: editingRule.conditions.min_purchase_amount ?? null,
        max_earn_per_transaction: editingRule.max_earn_per_transaction,
        max_earn_per_day: editingRule.max_earn_per_day,
        start_date: editingRule.start_date?.slice(0, 10) ?? null,
        end_date: editingRule.end_date?.slice(0, 10) ?? null,
      })
    } else {
      form.reset({
        name: '',
        rule_type: 'spend',
        priority: 1,
        reward_type: 'fixed',
        reward_value: '',
        min_purchase_amount: null,
        max_earn_per_transaction: null,
        max_earn_per_day: null,
        start_date: null,
        end_date: null,
      })
    }
  }, [editingRule, form, isOpen])

  const handleSubmit = (values: FormValues) => {
    const conditions: EarningRuleConditions = {}
    if (values.rule_type === 'spend' && values.min_purchase_amount) {
      conditions.min_purchase_amount = values.min_purchase_amount
    }

    onSubmit({
      name: values.name,
      rule_type: values.rule_type,
      priority: values.priority,
      conditions,
      reward_value: values.reward_value,
      reward_type: values.reward_type,
      max_earn_per_transaction: values.max_earn_per_transaction || null,
      max_earn_per_day: values.max_earn_per_day || null,
      start_date: values.start_date || null,
      end_date: values.end_date || null,
    })
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="lg">
      <Modal.Header
        title={editingRule ? t('loyalty:earningRules.edit') : t('loyalty:earningRules.create')}
        onClose={onClose}
      />
      <form onSubmit={form.handleSubmit(handleSubmit)}>
        <Modal.Content>
          <div className="space-y-4">
            {parsedServerError !== null && (
              <div className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                {parsedServerError.message}
              </div>
            )}

            <FormField
              label={t('loyalty:fields.name')}
              error={form.formState.errors.name?.message ?? fieldError(fieldErrors, 'name')}
            >
              <Input {...form.register('name')} />
            </FormField>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.ruleType')} error={fieldError(fieldErrors, 'rule_type')}>
                <Select {...form.register('rule_type')}>
                  {(['spend', 'item', 'category', 'quantity', 'visit', 'threshold', 'time'] as const).map((type) => (
                    <option key={type} value={type}>{t(`loyalty:ruleTypes.${type}`)}</option>
                  ))}
                </Select>
              </FormField>

              <FormField label={t('loyalty:fields.priority')} error={fieldError(fieldErrors, 'priority')}>
                <Input {...form.register('priority')} type="number" min="1" />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.rewardType')} error={fieldError(fieldErrors, 'reward_type')}>
                {/* Earning math only implements 'fixed' (PointEarningService ignores
                    reward_type); multiplier/percentage are hidden until the engine
                    honors them to avoid a 10x over-earn misconfiguration. */}
                <Select {...form.register('reward_type')}>
                  {(['fixed'] as const).map((type) => (
                    <option key={type} value={type}>{t(`loyalty:earningRewardTypes.${type}`)}</option>
                  ))}
                </Select>
              </FormField>

              <FormField
                label={t('loyalty:fields.rewardValue')}
                error={form.formState.errors.reward_value?.message ?? fieldError(fieldErrors, 'reward_value')}
              >
                <Controller
                  name="reward_value"
                  control={form.control}
                  render={({ field }) => (
                    <QuantityInput
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      decimalPlaces={4}
                      min="0"
                    />
                  )}
                />
              </FormField>
            </div>

            {form.watch('rule_type') === 'spend' && (
              <FormField
                label={t('loyalty:fields.minPurchaseAmount')}
                error={fieldError(fieldErrors, 'conditions.min_purchase_amount') ?? fieldError(fieldErrors, 'conditions')}
              >
                <Controller
                  name="min_purchase_amount"
                  control={form.control}
                  render={({ field }) => (
                    <QuantityInput
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      decimalPlaces={3}
                      min="0"
                    />
                  )}
                />
              </FormField>
            )}

            <div className="grid grid-cols-2 gap-4">
              <FormField
                label={t('loyalty:fields.maxEarnPerTransaction')}
                error={fieldError(fieldErrors, 'max_earn_per_transaction')}
              >
                <Controller
                  name="max_earn_per_transaction"
                  control={form.control}
                  render={({ field }) => (
                    <QuantityInput
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      decimalPlaces={2}
                      min="0"
                    />
                  )}
                />
              </FormField>
              <FormField label={t('loyalty:fields.maxEarnPerDay')} error={fieldError(fieldErrors, 'max_earn_per_day')}>
                <Controller
                  name="max_earn_per_day"
                  control={form.control}
                  render={({ field }) => (
                    <QuantityInput
                      value={field.value ?? ''}
                      onChange={field.onChange}
                      decimalPlaces={2}
                      min="0"
                    />
                  )}
                />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.startDate')} error={fieldError(fieldErrors, 'start_date')}>
                <Input {...form.register('start_date')} type="date" />
              </FormField>
              <FormField label={t('loyalty:fields.endDate')} error={fieldError(fieldErrors, 'end_date')}>
                <Input {...form.register('end_date')} type="date" />
              </FormField>
            </div>
          </div>
        </Modal.Content>
        <Modal.Footer>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:cancel')}
          </Button>
          <Button type="submit" disabled={isPending}>
            {isPending ? t('common:saving') : t('common:save')}
          </Button>
        </Modal.Footer>
      </form>
    </Modal>
  )
}
