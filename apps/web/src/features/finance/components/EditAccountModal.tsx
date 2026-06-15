import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useUpdateAccount } from '../hooks/useAccounts'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms'
import { Button, FormField, Input, Textarea } from '@/components/atoms'
import { tokens } from '@/lib/designTokens'
import type { Account } from '../types'

interface EditAccountModalProps {
  account: Account
  open: boolean
  onClose: () => void
  onSuccess: () => void
}

export function EditAccountModal({ account, open, onClose, onSuccess }: EditAccountModalProps) {
  const { t } = useTranslation(['finance', 'common', 'validation'])
  const updateMutation = useUpdateAccount()

  const [formData, setFormData] = useState({
    name: account.name,
    description: account.description ?? '',
    is_active: account.is_active,
  })

  const [errors, setErrors] = useState<Record<string, string>>({})

  // Note: Initial formData set from props, updates when account changes
  // This is acceptable since we're synchronizing with an external prop change
  useEffect(() => {
    setFormData({
      name: account.name,
      description: account.description ?? '',
      is_active: account.is_active,
    })
    // Intentionally resetting form when account prop changes
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [account.id])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    const newErrors: Record<string, string> = {}

    if (!formData.name.trim()) {
      newErrors['name'] = t('validation:required')
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors)
      return
    }

    void updateMutation
      .mutateAsync({
        id: account.id,
        data: {
          name: formData.name,
          description: formData.description || null,
          is_active: formData.is_active,
        },
      })
      .then(() => {
        setErrors({})
        onSuccess()
      })
      .catch((error: unknown) => {
        console.error('Failed to update account:', error)
      })
  }

  return (
    <Modal isOpen={open} onClose={onClose} title={t('finance:chartOfAccounts.account.editTitle')}>
      <form onSubmit={handleSubmit}>
        <ModalContent>
          <FormField
            label={t('finance:chartOfAccounts.account.code')}
            helperText={t('finance:chartOfAccounts.account.codeReadonly')}
          >
            <Input type="text" value={account.code} disabled />
          </FormField>

          <FormField
            label={t('finance:chartOfAccounts.account.type')}
            helperText={t('finance:chartOfAccounts.account.typeReadonly')}
          >
            <Input type="text" value={account.type} disabled className="capitalize" />
          </FormField>

          <FormField
            label={t('finance:chartOfAccounts.account.name')}
            htmlFor="edit-name"
            error={errors['name']}
          >
            <Input
              id="edit-name"
              name="name"
              type="text"
              value={formData.name}
              onChange={(e) => { setFormData({ ...formData, name: e.target.value }) }}
              error={Boolean(errors['name'])}
            />
          </FormField>

          <FormField label={t('finance:chartOfAccounts.account.description')} htmlFor="edit-description">
            <Textarea
              id="edit-description"
              name="description"
              rows={3}
              value={formData.description}
              onChange={(e) => { setFormData({ ...formData, description: e.target.value }) }}
            />
          </FormField>

          <div className="flex items-center gap-2">
            <input
              id="is_active"
              name="is_active"
              type="checkbox"
              checked={formData.is_active}
              onChange={(e) => { setFormData({ ...formData, is_active: e.target.checked }) }}
              className={tokens.checkbox.base}
            />
            <label htmlFor="is_active" className={tokens.label.base}>
              {t('finance:chartOfAccounts.account.active')}
            </label>
          </div>
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" variant="primary" disabled={updateMutation.isPending}>
            {updateMutation.isPending
              ? t('finance:chartOfAccounts.account.saving')
              : t('finance:chartOfAccounts.account.saveChanges')}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
