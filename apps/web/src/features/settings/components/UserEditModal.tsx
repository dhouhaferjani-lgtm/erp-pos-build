import { useState, useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Loader2, Save } from 'lucide-react'
import { updateUser } from '../../users/api/users'
import { getErrorMessage } from '../../../lib/api'
import { cn } from '../../../lib/utils'
import { tokens, textColors, borderColors, colors, focusRing } from '../../../lib/designTokens'
import { Modal, ModalContent, ModalFooter } from '../../../components/organisms/Modal'
import { Button } from '../../../components/atoms/Button'
import { FormField } from '../../../components/atoms/FormField'
import { Input } from '../../../components/atoms/Input'
import { Select } from '../../../components/atoms/Select'
import type { User } from '../../users/types'
import { usePermissions } from '../../../hooks/usePermissions'
import { useAuthStore } from '../../../stores/authStore'
import { LocationAccessField } from './LocationAccessField'

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
  const currentUserId = useAuthStore((state) => state.user?.id ?? null)
  const { hasPermission } = usePermissions()
  const canManageLocationAccess = hasPermission('users.manage_location_access')
  const isSelf = user.id === currentUserId
  const queryClient = useQueryClient()
  const { handleSubmit: handleFormSubmit } = useForm()

  const [name, setName] = useState(user.name)
  const [email, setEmail] = useState(user.email ?? '')
  const [phone, setPhone] = useState(user.phone ?? '')
  const [role, setRole] = useState(user.roles[0] ?? 'operator')
  const [canDiscount, setCanDiscount] = useState(user.canDiscount ?? false)
  const [maxDiscountPercent, setMaxDiscountPercent] = useState<string>(
    user.maxDiscountPercent ?? ''
  )
  const [allowedLocationIds, setAllowedLocationIds] = useState<string[] | null>(user.allowed_location_ids ?? null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  useEffect(() => {
    setName(user.name)
    setEmail(user.email ?? '')
    setPhone(user.phone ?? '')
    setRole(user.roles[0] ?? 'operator')
    setCanDiscount(user.canDiscount ?? false)
    setMaxDiscountPercent(user.maxDiscountPercent ?? '')
    setAllowedLocationIds(user.allowed_location_ids ?? null)
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
      if (canManageLocationAccess && !isSelf) {
        data.allowed_location_ids = allowedLocationIds
      }
      return updateUser(user.id, data)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['users'] })
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

  const submitUserEdit = () => {
    if (validate()) {
      mutation.mutate()
    }
  }

  return (
    <Modal isOpen onClose={onClose} size="md" title={t('settings:userEdit.title')}>
      <form onSubmit={(event) => { void handleFormSubmit(submitUserEdit)(event) }}>
        <ModalContent>
          {/* Name */}
          <FormField
            label={t('common:users.modal.nameLabel')}
            htmlFor="editName"
            required
            error={errors['name']}
          >
            <Input
              type="text"
              id="editName"
              value={name}
              error={Boolean(errors['name'])}
              onChange={(e) => { setName(e.target.value) }}
            />
          </FormField>

          {/* Email */}
          <FormField
            label={`${t('common:users.modal.emailLabel')}${role !== 'cashier' ? ' *' : ''}`}
            htmlFor="editEmail"
            error={errors['email']}
          >
            <Input
              type="email"
              id="editEmail"
              value={email}
              error={Boolean(errors['email'])}
              onChange={(e) => { setEmail(e.target.value) }}
            />
          </FormField>

          {/* Phone */}
          <FormField label={t('common:users.modal.phoneLabel')} htmlFor="editPhone">
            <Input
              type="tel"
              id="editPhone"
              value={phone}
              onChange={(e) => { setPhone(e.target.value) }}
            />
          </FormField>

          {/* Role */}
          <FormField
            label={t('common:users.modal.roleLabel')}
            htmlFor="editRole"
            required
            error={errors['role']}
          >
            <Select
              id="editRole"
              value={role}
              error={Boolean(errors['role'])}
              onChange={(e) => { setRole(e.target.value) }}
            >
              {roles.map((r) => (
                <option key={r.name} value={r.name}>
                  {r.name.charAt(0).toUpperCase() + r.name.slice(1)}
                </option>
              ))}
            </Select>
          </FormField>

          {/* Discount Section */}
          <div className={cn('border-t pt-4', borderColors.light)}>
            <h3 className={cn('mb-3', tokens.heading.section)}>
              {t('settings:userEdit.discountSection')}
            </h3>

            {/* Can Discount Toggle */}
            <div className="flex items-center justify-between">
              <label htmlFor="canDiscount" className={cn('text-sm', textColors.secondary)}>
                {t('settings:userEdit.canDiscount')}
              </label>
              {/* role="switch" toggle — a distinct control from the action Button atom */}
              <button
                type="button"
                id="canDiscount"
                role="switch"
                aria-checked={canDiscount}
                onClick={() => { setCanDiscount(!canDiscount) }}
                className={cn(
                  'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out',
                  focusRing.default,
                  focusRing.primary,
                  canDiscount ? colors.primary[600] : colors.neutral[200],
                )}
              >
                <span
                  className={cn(
                    'pointer-events-none inline-block h-5 w-5 transform rounded-full shadow ring-0 transition duration-200 ease-in-out',
                    colors.white,
                    canDiscount ? 'translate-x-5' : 'translate-x-0',
                  )}
                />
              </button>
            </div>

            {/* Max Discount Percent */}
            {canDiscount && (
              <FormField
                label={t('settings:userEdit.maxDiscountPercent')}
                htmlFor="maxDiscountPercent"
                error={errors['maxDiscountPercent']}
                helperText={t('settings:userEdit.maxDiscountHelp')}
                className="mt-3"
              >
                <div className="relative">
                  <Input
                    type="number"
                    id="maxDiscountPercent"
                    min={0}
                    max={100}
                    step={1}
                    value={maxDiscountPercent}
                    error={Boolean(errors['maxDiscountPercent'])}
                    onChange={(e) => { setMaxDiscountPercent(e.target.value) }}
                    placeholder="100"
                    className="pr-8"
                  />
                  <span className={cn('absolute inset-y-0 right-3 flex items-center text-sm', textColors.disabled)}>
                    %
                  </span>
                </div>
              </FormField>
            )}
          </div>

          {canManageLocationAccess && (
            <>
              <LocationAccessField
                value={allowedLocationIds}
                onChange={setAllowedLocationIds}
                readOnly={isSelf}
                disabled={isSelf}
              />
              {isSelf && (
                <p className={cn('mt-2 text-sm', textColors.tertiary)}>
                  {t('locations:staffAccess.selfDisabledHint')}
                </p>
              )}
            </>
          )}
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('common:actions.cancel')}
          </Button>
          <Button type="submit" className="gap-2" disabled={mutation.isPending}>
            {mutation.isPending ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('settings:userEdit.saving')}
              </>
            ) : (
              <>
                <Save className="h-4 w-4" />
                {t('settings:userEdit.save')}
              </>
            )}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
