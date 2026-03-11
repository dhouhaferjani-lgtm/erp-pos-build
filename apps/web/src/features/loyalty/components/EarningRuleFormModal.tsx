import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm, type Resolver } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Button, Input, FormField, Select } from '@/components/atoms'
import { Modal } from '@/components/organisms/Modal/Modal'
import type { EarningRule, CreateEarningRuleData } from '../types/loyalty'

const schema = z.object({
  name: z.string().min(1),
  rule_type: z.enum(['spend', 'item', 'category', 'quantity', 'visit', 'threshold', 'time']),
  priority: z.coerce.number().int().min(0),
  reward_value: z.string().min(1),
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
}

export function EarningRuleFormModal({ isOpen, onClose, onSubmit, isPending, editingRule }: EarningRuleFormModalProps) {
  const { t } = useTranslation(['loyalty', 'common'])

  const form = useForm<FormValues>({
    resolver: zodResolver(schema) as Resolver<FormValues>,
    defaultValues: {
      name: '',
      rule_type: 'spend',
      priority: 0,
      reward_value: '',
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
        reward_value: editingRule.reward_value,
        max_earn_per_transaction: editingRule.max_earn_per_transaction,
        max_earn_per_day: editingRule.max_earn_per_day,
        start_date: editingRule.start_date?.slice(0, 10) ?? null,
        end_date: editingRule.end_date?.slice(0, 10) ?? null,
      })
    } else {
      form.reset({
        name: '',
        rule_type: 'spend',
        priority: 0,
        reward_value: '',
        max_earn_per_transaction: null,
        max_earn_per_day: null,
        start_date: null,
        end_date: null,
      })
    }
  }, [editingRule, form, isOpen])

  const handleSubmit = (values: FormValues) => {
    onSubmit({
      name: values.name,
      rule_type: values.rule_type,
      priority: values.priority,
      reward_value: values.reward_value,
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
            <FormField label={t('loyalty:fields.name')} error={form.formState.errors.name?.message}>
              <Input {...form.register('name')} />
            </FormField>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.ruleType')}>
                <Select {...form.register('rule_type')}>
                  {(['spend', 'item', 'category', 'quantity', 'visit', 'threshold', 'time'] as const).map((type) => (
                    <option key={type} value={type}>{t(`loyalty:ruleTypes.${type}`)}</option>
                  ))}
                </Select>
              </FormField>

              <FormField label={t('loyalty:fields.priority')}>
                <Input {...form.register('priority')} type="number" min="0" />
              </FormField>
            </div>

            <FormField label={t('loyalty:fields.rewardValue')} error={form.formState.errors.reward_value?.message}>
              <Input {...form.register('reward_value')} type="number" step="0.01" min="0" />
            </FormField>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.maxEarnPerTransaction')}>
                <Input {...form.register('max_earn_per_transaction')} type="number" step="0.01" min="0" />
              </FormField>
              <FormField label={t('loyalty:fields.maxEarnPerDay')}>
                <Input {...form.register('max_earn_per_day')} type="number" step="0.01" min="0" />
              </FormField>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <FormField label={t('loyalty:fields.startDate')}>
                <Input {...form.register('start_date')} type="date" />
              </FormField>
              <FormField label={t('loyalty:fields.endDate')}>
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
