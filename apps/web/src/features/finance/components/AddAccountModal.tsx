import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useCreateAccount, useAccounts } from '../hooks/useAccounts'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms'
import { Button, FormField, Input, Select, Textarea } from '@/components/atoms'
import type { AccountType } from '../types'

interface AddAccountModalProps {
  open: boolean
  onClose: () => void
  onSuccess: () => void
}

export function AddAccountModal({ open, onClose, onSuccess }: AddAccountModalProps) {
  const { t } = useTranslation(['finance', 'common', 'validation'])
  const { data: accounts } = useAccounts()
  const createMutation = useCreateAccount()

  const [formData, setFormData] = useState({
    code: '',
    name: '',
    type: 'asset' as AccountType,
    description: '',
    parent_id: '',
  })

  const [errors, setErrors] = useState<Record<string, string>>({})

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const newErrors: Record<string, string> = {}

    if (!formData.code.trim()) {
      newErrors['code'] = t('validation:required')
    }

    if (!formData.name.trim()) {
      newErrors['name'] = t('validation:required')
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors)
      return
    }

    void createMutation
      .mutateAsync({
        code: formData.code,
        name: formData.name,
        type: formData.type,
        description: formData.description || undefined,
        parent_id: formData.parent_id || undefined,
      })
      .then(() => {
        setFormData({
          code: '',
          name: '',
          type: 'asset',
          description: '',
          parent_id: '',
        })
        setErrors({})
        onSuccess()
      })
      .catch((error: unknown) => {
        console.error('Failed to create account:', error)
      })
  }

  return (
    <Modal isOpen={open} onClose={onClose} title={t('finance:chartOfAccounts.account.addTitle')}>
      <form onSubmit={handleSubmit}>
        <ModalContent>
          <FormField
            label={t('finance:chartOfAccounts.account.code')}
            htmlFor="code"
            error={errors['code']}
          >
            <Input
              id="code"
              name="code"
              type="text"
              value={formData.code}
              onChange={(e) => { setFormData({ ...formData, code: e.target.value }) }}
              error={Boolean(errors['code'])}
            />
          </FormField>

          <FormField
            label={t('finance:chartOfAccounts.account.name')}
            htmlFor="name"
            error={errors['name']}
          >
            <Input
              id="name"
              name="name"
              type="text"
              value={formData.name}
              onChange={(e) => { setFormData({ ...formData, name: e.target.value }) }}
              error={Boolean(errors['name'])}
            />
          </FormField>

          <FormField label={t('finance:chartOfAccounts.account.type')} htmlFor="type">
            <Select
              id="type"
              name="type"
              value={formData.type}
              onChange={(e) => { setFormData({ ...formData, type: e.target.value as AccountType }) }}
            >
              <option value="asset">{t('finance:chartOfAccounts.account.types.asset')}</option>
              <option value="liability">{t('finance:chartOfAccounts.account.types.liability')}</option>
              <option value="equity">{t('finance:chartOfAccounts.account.types.equity')}</option>
              <option value="revenue">{t('finance:chartOfAccounts.account.types.revenue')}</option>
              <option value="expense">{t('finance:chartOfAccounts.account.types.expense')}</option>
            </Select>
          </FormField>

          <FormField label={t('finance:chartOfAccounts.account.parentAccount')} htmlFor="parent_id">
            <Select
              id="parent_id"
              name="parent_id"
              value={formData.parent_id}
              onChange={(e) => { setFormData({ ...formData, parent_id: e.target.value }) }}
            >
              <option value="">{t('finance:chartOfAccounts.account.noParent')}</option>
              {accounts?.filter((a) => a.type === formData.type).map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} - {account.name}
                </option>
              ))}
            </Select>
          </FormField>

          <FormField label={t('finance:chartOfAccounts.account.description')} htmlFor="description">
            <Textarea
              id="description"
              name="description"
              rows={3}
              value={formData.description}
              onChange={(e) => { setFormData({ ...formData, description: e.target.value }) }}
            />
          </FormField>
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={createMutation.isPending}>
            {createMutation.isPending
              ? t('finance:chartOfAccounts.account.creating')
              : t('finance:chartOfAccounts.account.create')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
