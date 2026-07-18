import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeft,
  Users,
  UserPlus,
  Mail,
  Phone,
  CheckCircle,
  XCircle,
  KeyRound,
  Trash2,
  Hash,
  Pencil,
  Loader2,
} from 'lucide-react'
import { api, getErrorMessage } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { cn } from '../../lib/utils'
import { tokens, textColors } from '../../lib/designTokens'
import { SearchInput } from '../../components/molecules/SearchInput'
import { FilterTabs } from '../../components/molecules/FilterTabs'
import { ActionMenu, type ActionMenuItem } from '../../components/ui/ActionMenu'
import { Button, StatusBadge, statusTone, FormField, Input, Select } from '../../components/atoms'
import {
  DataTable,
  type DataTableColumn,
  EmptyState,
  ListPageLayout,
} from '../../components/molecules'
import { Modal, ModalContent, ModalFooter } from '../../components/organisms/Modal'
import { UserEditModal } from './components/UserEditModal'
import type { User } from '../users/types'
import type { OffsetPaginationMeta } from '../../types/pagination'

interface UsersResponse {
  data: User[]
  meta?: OffsetPaginationMeta
}

interface Role {
  name: string
  permissions: string[]
}

interface CreateUserData {
  name: string
  email?: string | undefined
  phone?: string | undefined
  role: string
  locale?: string | undefined
  timezone?: string | undefined
}

type StatusFilter = 'all' | 'active' | 'inactive' | 'pending_verification'

// Domain status/role → semantic tone overrides for StatusBadge.
const STATUS_TONE_OVERRIDES = {
  pending_verification: 'pending',
  locked: 'danger',
} as const

const ROLE_TONE_OVERRIDES = {
  admin: 'info',
  manager: 'info',
  cashier: 'success',
  accountant: 'info',
  operator: 'info',
  technician: 'warning',
  viewer: 'neutral',
} as const

function scopedNamespacePredicate(
  namespace: string,
  tenantId: string | null,
  companyId: string | null,
): (q: { queryKey: readonly unknown[] }) => boolean {
  return (q) => {
    const k = q.queryKey
    return (
      k.length >= 3 &&
      k[0] === namespace &&
      k[k.length - 2] === tenantId &&
      k[k.length - 1] === companyId
    )
  }
}

export function UsersPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const currentUserId = useAuthStore((state) => state.user?.id ?? null)
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [showAddModal, setShowAddModal] = useState(false)
  const [editUser, setEditUser] = useState<User | null>(null)
  const [showPinModal, setShowPinModal] = useState<string | null>(null)
  const [actionLoading, setActionLoading] = useState<string | null>(null)
  const [notification, setNotification] = useState<{
    type: 'success' | 'error'
    message: string
  } | null>(null)

  // Fetch users
  const { data, isLoading, error } = useQuery({
    queryKey: tenantScopedKey(['users', searchQuery, statusFilter]),
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter !== 'all') params.append('status', statusFilter)
      const queryString = params.toString()
      const response = await api.get<UsersResponse>(`/users${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  // Fetch roles for the create user form
  const { data: rolesData } = useQuery({
    queryKey: tenantScopedKey(['roles']),
    queryFn: async () => {
      const response = await api.get<{ data: Role[] }>('/roles')
      return response.data.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  // Create user mutation
  const createUserMutation = useMutation({
    mutationFn: async (userData: CreateUserData): Promise<User> => {
      const response = await api.post<{ data: User }>('/users', userData)
      return response.data.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('users', tenantId, companyId),
      })
      setShowAddModal(false)
      showNotification('success', t('users.messages.created'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
  })

  // Action mutations
  const activateMutation = useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      setActionLoading(userId)
      await api.post(`/users/${userId}/activate`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('users', tenantId, companyId),
      })
      showNotification('success', t('users.messages.activated'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
    onSettled: () => {
      setActionLoading(null)
    },
  })

  const deactivateMutation = useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      setActionLoading(userId)
      await api.post(`/users/${userId}/deactivate`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('users', tenantId, companyId),
      })
      showNotification('success', t('users.messages.deactivated'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
    onSettled: () => {
      setActionLoading(null)
    },
  })

  const resetPasswordMutation = useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      setActionLoading(userId)
      await api.post(`/users/${userId}/reset-password`)
    },
    onSuccess: () => {
      showNotification('success', t('users.messages.passwordReset'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
    onSettled: () => {
      setActionLoading(null)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      setActionLoading(userId)
      await api.delete(`/users/${userId}`)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('users', tenantId, companyId),
      })
      showNotification('success', t('users.messages.deleted'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
    onSettled: () => {
      setActionLoading(null)
    },
  })

  const setPosPinMutation = useMutation({
    mutationFn: async ({ userId, pin }: { userId: string; pin: string | null }): Promise<void> => {
      await api.patch(`/users/${userId}/pos-pin`, { pin })
    },
    onSuccess: (_data, variables) => {
      showNotification('success', variables.pin ? t('users.messages.pinSet') : t('users.messages.pinCleared'))
      setShowPinModal(null)
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
  })

  const users = data?.data ?? []
  const total = data?.meta?.total ?? users.length
  const roles = rolesData ?? []

  const filterTabs = [
    { value: 'all' as StatusFilter, label: t('users.filterTabs.all'), count: total },
    { value: 'active' as StatusFilter, label: t('users.filterTabs.active') },
    { value: 'inactive' as StatusFilter, label: t('users.filterTabs.inactive') },
    { value: 'pending_verification' as StatusFilter, label: t('users.filterTabs.pending') },
  ]

  const showNotification = (type: 'success' | 'error', message: string) => {
    setNotification({ type, message })
    setTimeout(() => { setNotification(null) }, 5000)
  }

  const formatDate = (dateString: string | null) => {
    if (!dateString) return t('users.never')
    return new Date(dateString).toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  }

  const buildMenuItems = (user: User): ActionMenuItem[] => {
    const items: ActionMenuItem[] = [
      {
        key: 'edit',
        label: t('users.actions.edit', { defaultValue: 'Edit' }),
        icon: <Pencil className={cn('h-4 w-4', textColors.brand)} />,
        onClick: () => { setEditUser(user) },
      },
    ]

    if (user.status !== 'active') {
      items.push({
        key: 'activate',
        label: t('users.actions.activate'),
        icon: <CheckCircle className={cn('h-4 w-4', textColors.success)} />,
        onClick: () => { activateMutation.mutate(user.id) },
      })
    }

    if (user.status === 'active' && user.id !== currentUserId) {
      items.push({
        key: 'deactivate',
        label: t('users.actions.deactivate'),
        icon: <XCircle className={cn('h-4 w-4', textColors.warningDark)} />,
        onClick: () => {
          if (confirm(t('users.confirmations.deactivate'))) {
            deactivateMutation.mutate(user.id)
          }
        },
      })
    }

    items.push({
      key: 'set-pin',
      label: t('users.actions.setPosPin', { defaultValue: 'Set POS PIN' }),
      icon: <Hash className={cn('h-4 w-4', textColors.brand)} />,
      onClick: () => { setShowPinModal(user.id) },
    })

    if (user.email) {
      items.push({
        key: 'reset-password',
        label: t('users.actions.resetPassword'),
        icon: <KeyRound className={cn('h-4 w-4', textColors.brand)} />,
        onClick: () => { resetPasswordMutation.mutate(user.id) },
      })
    }

    if (user.id !== currentUserId) {
      items.push({
        key: 'delete',
        label: t('users.actions.delete'),
        icon: <Trash2 className="h-4 w-4" />,
        destructive: true,
        onClick: () => {
          if (confirm(t('users.confirmations.delete'))) {
            deleteMutation.mutate(user.id)
          }
        },
      })
    }

    return items
  }

  const columns: DataTableColumn<User>[] = [
    {
      key: 'user',
      header: t('users.table.user'),
      render: (user) => (
        <div className="flex items-center gap-3">
          <div className={cn('h-10 w-10 rounded-full flex items-center justify-center', tokens.badge.blue)}>
            <span className="text-sm font-semibold">
              {user.name.charAt(0).toUpperCase()}
            </span>
          </div>
          <div>
            <div className={cn('font-medium', textColors.primary)}>{user.name}</div>
            {user.email && (
              <div className={cn('text-sm flex items-center gap-1', textColors.tertiary)}>
                <Mail className="h-3.5 w-3.5" />
                {user.email}
              </div>
            )}
            {user.phone && (
              <div className={cn('text-sm flex items-center gap-1', textColors.tertiary)}>
                <Phone className="h-3.5 w-3.5" />
                {user.phone}
              </div>
            )}
          </div>
        </div>
      ),
    },
    {
      key: 'role',
      header: t('users.table.role'),
      render: (user) => (
        <div className="flex flex-wrap gap-1">
          {user.roles.map((role) => (
            <StatusBadge key={role} tone={statusTone(role, ROLE_TONE_OVERRIDES)} className="capitalize">
              {role}
            </StatusBadge>
          ))}
        </div>
      ),
    },
    {
      key: 'status',
      header: t('users.table.status'),
      render: (user) => (
        <StatusBadge tone={statusTone(user.status, STATUS_TONE_OVERRIDES)}>
          {t(`users.statusLabels.${user.status === 'pending_verification' ? 'pending' : user.status}`)}
        </StatusBadge>
      ),
    },
    {
      key: 'lastLogin',
      header: t('users.table.lastLogin'),
      render: (user) => (
        <span className={cn('text-sm', textColors.tertiary)}>{formatDate(user.lastLoginAt)}</span>
      ),
    },
    {
      key: 'actions',
      header: <span className="sr-only">{t('users.table.actions')}</span>,
      align: 'right',
      render: (user) => (
        <ActionMenu
          items={buildMenuItems(user)}
          ariaLabel={t('users.table.actions')}
          isLoading={actionLoading === user.id}
        />
      ),
    },
  ]

  return (
    <ListPageLayout
      title={t('users.title')}
      subtitle={t(total === 1 ? 'users.count' : 'users.count_plural', { count: total })}
      breadcrumb={
        <Link
          to="/settings"
          className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </Link>
      }
      actions={
        <Button className="gap-2" onClick={() => { setShowAddModal(true) }}>
          <UserPlus className="h-4 w-4" />
          {t('users.addUser')}
        </Button>
      }
      filters={
        <>
          <FilterTabs tabs={filterTabs} value={statusFilter} onChange={(value) => { setStatusFilter(value) }} />
          <SearchInput
            value={searchQuery}
            onChange={(value) => { setSearchQuery(value) }}
            placeholder={t('users.searchPlaceholder')}
            className="w-full sm:w-72"
          />
        </>
      }
    >
      {/* Notification */}
      {notification && (
        <div
          className={cn(
            'fixed top-4 right-4 z-50 rounded-lg p-4 shadow-lg',
            tokens.alert.base,
            notification.type === 'success' ? tokens.alert.success : tokens.alert.error,
          )}
        >
          <div className="flex items-center gap-2">
            {notification.type === 'success' ? (
              <CheckCircle className="h-5 w-5" />
            ) : (
              <XCircle className="h-5 w-5" />
            )}
            {notification.message}
          </div>
        </div>
      )}

      {error ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('users.errorLoading')}
        </div>
      ) : !isLoading && users.length === 0 ? (
        <div className="py-6">
          <EmptyState
            icon={<Users className={cn('mx-auto h-12 w-12', textColors.disabled)} />}
            title={searchQuery ? t('users.empty.noResults') : t('users.empty.title')}
            description={searchQuery ? t('users.empty.tryDifferent') : t('users.empty.getStarted')}
          />
          {!searchQuery && (
            <div className="mt-6 flex justify-center">
              <Button className="gap-2" onClick={() => { setShowAddModal(true) }}>
                <UserPlus className="h-4 w-4" />
                {t('users.addUser')}
              </Button>
            </div>
          )}
        </div>
      ) : (
        <DataTable
          columns={columns}
          data={users}
          keyExtractor={(user) => user.id}
          isLoading={isLoading}
          emptyTitle={t('users.empty.title')}
        />
      )}

      {/* Add User Modal */}
      <AddUserModal
        isOpen={showAddModal}
        roles={roles}
        onClose={() => { setShowAddModal(false) }}
        onSubmit={(data) => { createUserMutation.mutate(data) }}
        isLoading={createUserMutation.isPending}
      />

      {/* Edit User Modal */}
      {editUser && (
        <UserEditModal
          user={editUser}
          roles={roles}
          onClose={() => { setEditUser(null) }}
          onSuccess={(message) => { showNotification('success', message) }}
          onError={(message) => { showNotification('error', message) }}
        />
      )}

      {/* POS PIN Modal */}
      <PosPinModal
        isOpen={showPinModal !== null}
        onClose={() => { setShowPinModal(null) }}
        onSubmit={(pin) => { if (showPinModal) setPosPinMutation.mutate({ userId: showPinModal, pin }) }}
        onClear={() => { if (showPinModal) setPosPinMutation.mutate({ userId: showPinModal, pin: null }) }}
        isLoading={setPosPinMutation.isPending}
      />
    </ListPageLayout>
  )
}

interface AddUserModalProps {
  isOpen: boolean
  roles: Role[]
  onClose: () => void
  onSubmit: (data: CreateUserData) => void
  isLoading: boolean
}

interface PosPinModalProps {
  isOpen: boolean
  onClose: () => void
  onSubmit: (pin: string) => void
  onClear: () => void
  isLoading: boolean
}

/**
 * Generate a numeric PIN of `length` digits using a cryptographically
 * strong source when available. Used so admins can reset a PIN without
 * picking one themselves; the PIN is shown once on screen.
 */
export function generateRandomPin(length = 4): string {
  if (length < 4 || length > 6) {
    throw new Error('PIN length must be between 4 and 6 digits.')
  }
  const cryptoApi = typeof globalThis.crypto !== 'undefined' ? globalThis.crypto : null
  let result = ''
  if (cryptoApi !== null && typeof cryptoApi.getRandomValues === 'function') {
    const buffer = new Uint32Array(length)
    cryptoApi.getRandomValues(buffer)
    for (const value of buffer) {
      result += (value % 10).toString()
    }
  } else {
    for (let i = 0; i < length; i += 1) {
      result += Math.floor(Math.random() * 10).toString()
    }
  }
  return result
}

function PosPinModal({ isOpen, onClose, onSubmit, onClear, isLoading }: PosPinModalProps) {
  const { t } = useTranslation()
  const [pin, setPin] = useState('')
  const [pinError, setPinError] = useState<string | null>(null)
  const [wasGenerated, setWasGenerated] = useState(false)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!/^\d{4,6}$/.test(pin)) {
      setPinError(t('users.validation.invalidPin', { defaultValue: 'PIN must be 4-6 digits' }))
      return
    }
    setPinError(null)
    onSubmit(pin)
  }

  const handleGenerate = () => {
    setPin(generateRandomPin(4))
    setPinError(null)
    setWasGenerated(true)
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="sm" title={t('users.pinModal.title', { defaultValue: 'Set POS PIN' })}>
      <form onSubmit={handleSubmit}>
        <ModalContent>
          <FormField
            label={t('users.pinModal.pinLabel', { defaultValue: 'PIN (4-6 digits)' })}
            htmlFor="posPin"
            error={pinError ?? undefined}
          >
            <Input
              type="text"
              id="posPin"
              inputMode="numeric"
              pattern="[0-9]*"
              maxLength={6}
              value={pin}
              error={pinError !== null}
              onChange={(e) => {
                const v = e.target.value.replace(/\D/g, '')
                setPin(v)
                setPinError(null)
                setWasGenerated(false)
              }}
              className="text-center text-2xl tracking-[0.5em]"
              autoFocus
            />
          </FormField>
          <Button type="button" variant="ghost" size="sm" onClick={handleGenerate} disabled={isLoading}>
            {t('users.pinModal.generate', { defaultValue: 'Generate random PIN' })}
          </Button>
          {wasGenerated && (
            <p className={cn(tokens.alert.base, tokens.alert.warning)}>
              {t('users.pinModal.shareWithCashier', {
                defaultValue: 'Share this PIN with the cashier now — it will not be shown again after saving.',
              })}
            </p>
          )}
        </ModalContent>

        <ModalFooter className="justify-between">
          <Button type="button" variant="danger" onClick={onClear} disabled={isLoading}>
            {t('users.pinModal.clearPin', { defaultValue: 'Clear PIN' })}
          </Button>
          <div className="flex gap-3">
            <Button type="button" variant="secondary" onClick={onClose}>
              {t('actions.cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || !pin}>
              {isLoading ? t('status.saving', { defaultValue: 'Saving...' }) : t('actions.save', { defaultValue: 'Save' })}
            </Button>
          </div>
        </ModalFooter>
      </form>
    </Modal>
  )
}

function AddUserModal({ isOpen, roles, onClose, onSubmit, isLoading }: AddUserModalProps) {
  const { t } = useTranslation()
  const [formData, setFormData] = useState<CreateUserData>({
    name: '',
    email: '',
    phone: '',
    role: 'operator',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  const validate = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!formData.name.trim()) {
      newErrors['name'] = t('users.validation.nameRequired')
    }
    if (formData.role !== 'cashier') {
      if (!(formData.email ?? '').trim()) {
        newErrors['email'] = t('users.validation.emailRequired')
      } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email ?? '')) {
        newErrors['email'] = t('users.validation.invalidEmail')
      }
    }
    if (!formData.role) {
      newErrors['role'] = t('users.validation.roleRequired')
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (validate()) {
      onSubmit({
        ...formData,
        email: (formData.email ?? '').trim() || undefined,
        phone: formData.phone || undefined,
      })
    }
  }

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="md" title={t('users.modal.title')}>
      <form onSubmit={handleSubmit}>
        <ModalContent>
          <FormField label={t('users.modal.nameLabel')} htmlFor="name" required error={errors['name']}>
            <Input
              type="text"
              id="name"
              value={formData.name}
              error={Boolean(errors['name'])}
              onChange={(e) => { setFormData({ ...formData, name: e.target.value }) }}
              placeholder={t('users.modal.namePlaceholder')}
            />
          </FormField>

          <FormField label={t('users.modal.roleLabel')} htmlFor="role" required error={errors['role']}>
            <Select
              id="role"
              value={formData.role}
              error={Boolean(errors['role'])}
              onChange={(e) => { setFormData({ ...formData, role: e.target.value }) }}
            >
              {roles.map((role) => (
                <option key={role.name} value={role.name}>
                  {role.name.charAt(0).toUpperCase() + role.name.slice(1)}
                </option>
              ))}
            </Select>
          </FormField>

          <FormField
            label={`${t('users.modal.emailLabel')}${formData.role !== 'cashier' ? ' *' : ''}`}
            htmlFor="email"
            error={errors['email']}
            helperText={formData.role === 'cashier' ? t('users.modal.emailOptionalHint') : undefined}
          >
            <Input
              type="email"
              id="email"
              value={formData.email ?? ''}
              error={Boolean(errors['email'])}
              onChange={(e) => { setFormData({ ...formData, email: e.target.value }) }}
              placeholder={t('users.modal.emailPlaceholder')}
            />
          </FormField>

          <FormField label={t('users.modal.phoneLabel')} htmlFor="phone">
            <Input
              type="tel"
              id="phone"
              value={formData.phone}
              onChange={(e) => { setFormData({ ...formData, phone: e.target.value }) }}
              placeholder={t('users.modal.phonePlaceholder')}
            />
          </FormField>

          <p className={cn('text-sm', textColors.tertiary)}>
            {formData.role === 'cashier' && !formData.email?.trim()
              ? t('users.modal.cashierPinNote')
              : t('users.modal.invitationNote')}
          </p>
        </ModalContent>

        <ModalFooter>
          <Button type="button" variant="secondary" onClick={onClose}>
            {t('actions.cancel')}
          </Button>
          <Button type="submit" className="gap-2" disabled={isLoading}>
            {isLoading ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                {t('status.creating')}
              </>
            ) : (
              <>
                <UserPlus className="h-4 w-4" />
                {t('users.modal.createUser')}
              </>
            )}
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  )
}
