import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Save } from 'lucide-react'
import { updateUser } from '../../users/api/users'
import { getErrorMessage } from '../../../lib/api'
import type { User } from '../../users/types'

interface Role {
  name: string
  permissions: string[]
}

interface UserEditModalProps {
  user: User
  roles: Role[]
  onClose: () => void
  onSuccess: (message: string) => void
  onError: (message: string) => void
}

export function UserEditModal({ user, roles, onClose, onSuccess, onError }: UserEditModalProps) {
  const { t } = useTranslation(['settings', 'common'])
  const queryClient = useQueryClient()

  const [name, setName] = useState(user.name)
  const [email, setEmail] = useState(user.email ?? '')
  const [phone, setPhone] = useState(user.phone ?? '')
  const [role, setRole] = useState(user.roles[0] ?? 'operator')
  const [canDiscount, setCanDiscount] = useState(user.canDiscount ?? false)
  const [maxDiscountPercent, setMaxDiscountPercent] = useState<string>(
    user.maxDiscountPercent != null ? String(user.maxDiscountPercent) : ''
  )
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    setName(user.name)
    setEmail(user.email ?? '')
    setPhone(user.phone ?? '')
    setRole(user.roles[0] ?? 'operator')
    setCanDiscount(user.canDiscount ?? false)
    setMaxDiscountPercent(
      user.maxDiscountPercent != null ? String(user.maxDiscountPercent) : ''
    )
  }, [user])

  const mutation = useMutation({
    mutationFn: async () => {
      const data: Record<string, unknown> = {
        name,
        email: email.trim() || null,
        phone: phone || null,
        role,
        can_discount: canDiscount,
        max_discount_percent: canDiscount && maxDiscountPercent !== ''
          ? Number(maxDiscountPercent)
          : null,
      }
      return updateUser(user.id, data)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      onSuccess(t('settings:userEdit.success'))
      onClose()
    },
    onError: (error) => {
      onError(getErrorMessage(error))
    },
  })

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!name.trim()) {
      newErrors['name'] = t('common:users.validation.nameRequired')
    }
    if (role !== 'cashier') {
      if (!email.trim()) {
        newErrors['email'] = t('common:users.validation.emailRequired')
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        newErrors['email'] = t('common:users.validation.invalidEmail')
      }
    }
    if (!role) {
      newErrors['role'] = t('common:users.validation.roleRequired')
    }
    if (canDiscount && maxDiscountPercent !== '') {
      const val = Number(maxDiscountPercent)
      if (isNaN(val) || val < 0 || val > 100) {
        newErrors['maxDiscountPercent'] = t('common:users.validation.invalidDiscount', {
          defaultValue: 'Must be between 0 and 100',
        })
      }
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (validate()) {
      mutation.mutate()
    }
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      <div className="flex min-h-full items-center justify-center p-4">
        <div className="fixed inset-0 bg-black bg-opacity-25" onClick={onClose} />
        <div className="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            {t('settings:userEdit.title')}
          </h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            {/* Name */}
            <div>
              <label htmlFor="editName" className="block text-sm font-medium text-gray-700">
                {t('common:users.modal.nameLabel')} *
              </label>
              <input
                type="text"
                id="editName"
                value={name}
                onChange={(e) => { setName(e.target.value) }}
                className={`mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-1 ${
                  errors['name']
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                }`}
              />
              {errors['name'] && <p className="mt-1 text-sm text-red-600">{errors['name']}</p>}
            </div>

            {/* Email */}
            <div>
              <label htmlFor="editEmail" className="block text-sm font-medium text-gray-700">
                {t('common:users.modal.emailLabel')} {role !== 'cashier' && '*'}
              </label>
              <input
                type="email"
                id="editEmail"
                value={email}
                onChange={(e) => { setEmail(e.target.value) }}
                className={`mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-1 ${
                  errors['email']
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                }`}
              />
              {errors['email'] && <p className="mt-1 text-sm text-red-600">{errors['email']}</p>}
            </div>

            {/* Phone */}
            <div>
              <label htmlFor="editPhone" className="block text-sm font-medium text-gray-700">
                {t('common:users.modal.phoneLabel')}
              </label>
              <input
                type="tel"
                id="editPhone"
                value={phone}
                onChange={(e) => { setPhone(e.target.value) }}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
              />
            </div>

            {/* Role */}
            <div>
              <label htmlFor="editRole" className="block text-sm font-medium text-gray-700">
                {t('common:users.modal.roleLabel')} *
              </label>
              <select
                id="editRole"
                value={role}
                onChange={(e) => { setRole(e.target.value) }}
                className={`mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-1 ${
                  errors['role']
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                }`}
              >
                {roles.map((r) => (
                  <option key={r.name} value={r.name}>
                    {r.name.charAt(0).toUpperCase() + r.name.slice(1)}
                  </option>
                ))}
              </select>
              {errors['role'] && <p className="mt-1 text-sm text-red-600">{errors['role']}</p>}
            </div>

            {/* Discount Section */}
            <div className="border-t border-gray-200 pt-4">
              <h3 className="text-sm font-semibold text-gray-900 mb-3">
                {t('settings:userEdit.discountSection')}
              </h3>

              {/* Can Discount Toggle */}
              <div className="flex items-center justify-between">
                <label htmlFor="canDiscount" className="text-sm text-gray-700">
                  {t('settings:userEdit.canDiscount')}
                </label>
                <button
                  type="button"
                  id="canDiscount"
                  role="switch"
                  aria-checked={canDiscount}
                  onClick={() => { setCanDiscount(!canDiscount) }}
                  className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                    canDiscount ? 'bg-blue-600' : 'bg-gray-200'
                  }`}
                >
                  <span
                    className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                      canDiscount ? 'translate-x-5' : 'translate-x-0'
                    }`}
                  />
                </button>
              </div>

              {/* Max Discount Percent */}
              {canDiscount && (
                <div className="mt-3">
                  <label htmlFor="maxDiscountPercent" className="block text-sm font-medium text-gray-700">
                    {t('settings:userEdit.maxDiscountPercent')}
                  </label>
                  <div className="mt-1 relative">
                    <input
                      type="number"
                      id="maxDiscountPercent"
                      min={0}
                      max={100}
                      step={1}
                      value={maxDiscountPercent}
                      onChange={(e) => { setMaxDiscountPercent(e.target.value) }}
                      placeholder="100"
                      className={`block w-full rounded-md border px-3 py-2 pr-8 shadow-sm focus:outline-none focus:ring-1 ${
                        errors['maxDiscountPercent']
                          ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                          : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                      }`}
                    />
                    <span className="absolute inset-y-0 right-3 flex items-center text-gray-400 text-sm">
                      %
                    </span>
                  </div>
                  {errors['maxDiscountPercent'] && (
                    <p className="mt-1 text-sm text-red-600">{errors['maxDiscountPercent']}</p>
                  )}
                  <p className="mt-1 text-xs text-gray-500">
                    {t('settings:userEdit.maxDiscountHelp')}
                  </p>
                </div>
              )}
            </div>

            {/* Actions */}
            <div className="flex justify-end gap-3 pt-4">
              <button
                type="button"
                onClick={onClose}
                className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('common:actions.cancel')}
              </button>
              <button
                type="submit"
                disabled={mutation.isPending}
                className="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {mutation.isPending ? (
                  <>
                    <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                    {t('settings:userEdit.saving')}
                  </>
                ) : (
                  <>
                    <Save className="h-4 w-4" />
                    {t('settings:userEdit.save')}
                  </>
                )}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
