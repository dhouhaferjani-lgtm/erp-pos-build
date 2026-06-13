import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { useUpdateAccount } from '../hooks/useAccounts'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
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
    description: account.description || '',
    is_active: account.is_active,
  })

  const [errors, setErrors] = useState<Record<string, string>>({})

  // Note: Initial formData set from props, updates when account changes
  // This is acceptable since we're synchronizing with an external prop change
  useEffect(() => {
    setFormData({
      name: account.name,
      description: account.description || '',
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

  if (!open) return null

  return (
    <div className={tokens.modal.backdrop}>
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4" role="dialog">
        <div className={`flex items-center justify-between px-6 py-4 border-b ${borderColors.light}`}>
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>{t('finance:chartOfAccounts.account.editTitle')}</h2>
          <button
            onClick={onClose}
            className={`${textColors.disabled} ${textColors.hoverSecondary} transition-colors`}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.code')}
            </label>
            <input
              type="text"
              value={account.code}
              disabled
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg bg-gray-50 ${textColors.disabled}`}
            />
            <p className={tokens.helperText.base}>{t('finance:chartOfAccounts.account.codeReadonly')}</p>
          </div>

          <div>
            <label className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.type')}
            </label>
            <input
              type="text"
              value={account.type}
              disabled
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg bg-gray-50 ${textColors.disabled} capitalize`}
            />
            <p className={tokens.helperText.base}>{t('finance:chartOfAccounts.account.typeReadonly')}</p>
          </div>

          <div>
            <label htmlFor="edit-name" className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.name')}
            </label>
            <input
              id="edit-name"
              name="name"
              type="text"
              value={formData.name}
              onChange={(e) => { setFormData({ ...formData, name: e.target.value }); }}
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500`}
            />
            {errors['name'] && <p className={tokens.helperText.error}>{errors['name']}</p>}
          </div>

          <div>
            <label htmlFor="edit-description" className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.description')}
            </label>
            <textarea
              id="edit-description"
              name="description"
              rows={3}
              value={formData.description}
              onChange={(e) => { setFormData({ ...formData, description: e.target.value }); }}
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500`}
            />
          </div>

          <div className="flex items-center gap-2">
            <input
              id="is_active"
              name="is_active"
              type="checkbox"
              checked={formData.is_active}
              onChange={(e) => { setFormData({ ...formData, is_active: e.target.checked }); }}
              className={tokens.checkbox.base}
            />
            <label htmlFor="is_active" className={tokens.label.base}>
              {t('finance:chartOfAccounts.account.active')}
            </label>
          </div>

          <div className="flex gap-3 pt-4">
            <button
              type="button"
              onClick={onClose}
              className={`flex-1 px-4 py-2 border ${borderColors.default} rounded-lg text-sm font-medium ${textColors.secondary} hover:bg-gray-50 transition-colors`}
            >
              {t('common:actions.cancel')}
            </button>
            <button
              type="submit"
              disabled={updateMutation.isPending}
              className={`flex-1 px-4 py-2 ${tokens.button.primary} rounded-lg text-sm font-medium disabled:opacity-50 transition-colors`}
            >
              {updateMutation.isPending
                ? t('finance:chartOfAccounts.account.saving')
                : t('finance:chartOfAccounts.account.saveChanges')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
