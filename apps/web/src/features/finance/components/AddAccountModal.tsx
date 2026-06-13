import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { useCreateAccount, useAccounts } from '../hooks/useAccounts'
import { tokens, textColors, borderColors } from '@/lib/designTokens'
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

  if (!open) return null

  return (
    <div className={tokens.modal.backdrop}>
      <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4" role="dialog">
        <div className={`flex items-center justify-between px-6 py-4 border-b ${borderColors.light}`}>
          <h2 className={`text-lg font-semibold ${textColors.primary}`}>{t('finance:chartOfAccounts.account.addTitle')}</h2>
          <button
            onClick={onClose}
            className={`${textColors.disabled} ${textColors.hoverSecondary} transition-colors`}
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="p-6 space-y-4">
          <div>
            <label htmlFor="code" className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.code')}
            </label>
            <input
              id="code"
              name="code"
              type="text"
              value={formData.code}
              onChange={(e) => { setFormData({ ...formData, code: e.target.value }); }}
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500`}
            />
            {errors['code'] && <p className={tokens.helperText.error}>{errors['code']}</p>}
          </div>

          <div>
            <label htmlFor="name" className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.name')}
            </label>
            <input
              id="name"
              name="name"
              type="text"
              value={formData.name}
              onChange={(e) => { setFormData({ ...formData, name: e.target.value }); }}
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500`}
            />
            {errors['name'] && <p className={tokens.helperText.error}>{errors['name']}</p>}
          </div>

          <div>
            <label htmlFor="type" className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.type')}
            </label>
            <select
              id="type"
              name="type"
              value={formData.type}
              onChange={(e) => { setFormData({ ...formData, type: e.target.value as AccountType }); }}
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500`}
            >
              <option value="asset">{t('finance:chartOfAccounts.account.types.asset')}</option>
              <option value="liability">{t('finance:chartOfAccounts.account.types.liability')}</option>
              <option value="equity">{t('finance:chartOfAccounts.account.types.equity')}</option>
              <option value="revenue">{t('finance:chartOfAccounts.account.types.revenue')}</option>
              <option value="expense">{t('finance:chartOfAccounts.account.types.expense')}</option>
            </select>
          </div>

          <div>
            <label htmlFor="parent_id" className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.parentAccount')}
            </label>
            <select
              id="parent_id"
              name="parent_id"
              value={formData.parent_id}
              onChange={(e) => { setFormData({ ...formData, parent_id: e.target.value }); }}
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500`}
            >
              <option value="">{t('finance:chartOfAccounts.account.noParent')}</option>
              {accounts?.filter((a) => a.type === formData.type).map((account) => (
                <option key={account.id} value={account.id}>
                  {account.code} - {account.name}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="description" className={`${tokens.label.base} mb-1`}>
              {t('finance:chartOfAccounts.account.description')}
            </label>
            <textarea
              id="description"
              name="description"
              rows={3}
              value={formData.description}
              onChange={(e) => { setFormData({ ...formData, description: e.target.value }); }}
              className={`w-full px-3 py-2 border ${borderColors.default} rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500`}
            />
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
              disabled={createMutation.isPending}
              className={`flex-1 px-4 py-2 ${tokens.button.primary} rounded-lg text-sm font-medium disabled:opacity-50 transition-colors`}
            >
              {createMutation.isPending
                ? t('finance:chartOfAccounts.account.creating')
                : t('finance:chartOfAccounts.account.create')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
