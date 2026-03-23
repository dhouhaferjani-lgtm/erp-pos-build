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
  MoreVertical,
  CheckCircle,
  XCircle,
  KeyRound,
  Trash2,
  Hash,
  Pencil,
} from 'lucide-react'
import { api, getErrorMessage } from '../../lib/api'
import { useAuthStore } from '../../stores/authStore'
import { SearchInput } from '../../components/ui/SearchInput'
import { FilterTabs } from '../../components/ui/FilterTabs'
import { UserEditModal } from './components/UserEditModal'
import type { User } from '../users/types'

interface UsersResponse {
  data: User[]
  meta?: {
    total: number
    current_page: number
    per_page: number
    last_page: number
  }
}

interface Role {
  name: string
  permissions: string[]
}

interface CreateUserData {
  name: string
  email: string
  phone?: string | undefined
  role: string
  locale?: string | undefined
  timezone?: string | undefined
}

type StatusFilter = 'all' | 'active' | 'inactive' | 'pending_verification'

const statusColors: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  inactive: 'bg-gray-100 text-gray-800',
  pending_verification: 'bg-yellow-100 text-yellow-800',
  locked: 'bg-red-100 text-red-800',
}

// Status labels are now handled via translations - see users.statusLabels

const roleColors: Record<string, string> = {
  admin: 'bg-purple-100 text-purple-800',
  manager: 'bg-blue-100 text-blue-800',
  cashier: 'bg-green-100 text-green-800',
  accountant: 'bg-indigo-100 text-indigo-800',
  operator: 'bg-teal-100 text-teal-800',
  technician: 'bg-orange-100 text-orange-800',
  viewer: 'bg-gray-100 text-gray-800',
}

export function UsersPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const currentUser = useAuthStore((state) => state.user)
  const [searchQuery, setSearchQuery] = useState('')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [showAddModal, setShowAddModal] = useState(false)
  const [editUser, setEditUser] = useState<User | null>(null)
  const [showPinModal, setShowPinModal] = useState<string | null>(null)
  const [showActionMenu, setShowActionMenu] = useState<string | null>(null)
  const [actionLoading, setActionLoading] = useState<string | null>(null)
  const [notification, setNotification] = useState<{
    type: 'success' | 'error'
    message: string
  } | null>(null)

  // Fetch users
  const { data, isLoading, error } = useQuery({
    queryKey: ['users', searchQuery, statusFilter],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (searchQuery) params.append('search', searchQuery)
      if (statusFilter !== 'all') params.append('status', statusFilter)
      const queryString = params.toString()
      const response = await api.get<UsersResponse>(`/users${queryString ? `?${queryString}` : ''}`)
      return response.data
    },
  })

  // Fetch roles for the create user form
  const { data: rolesData } = useQuery({
    queryKey: ['roles'],
    queryFn: async () => {
      const response = await api.get<{ data: Role[] }>('/roles')
      return response.data.data
    },
  })

  // Create user mutation
  const createUserMutation = useMutation({
    mutationFn: async (userData: CreateUserData): Promise<User> => {
      const response = await api.post<{ data: User }>('/users', userData)
      return response.data.data
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
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
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      showNotification('success', t('users.messages.activated'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
    onSettled: () => {
      setActionLoading(null)
      setShowActionMenu(null)
    },
  })

  const deactivateMutation = useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      setActionLoading(userId)
      await api.post(`/users/${userId}/deactivate`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      showNotification('success', t('users.messages.deactivated'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
    onSettled: () => {
      setActionLoading(null)
      setShowActionMenu(null)
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
      setShowActionMenu(null)
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (userId: string): Promise<void> => {
      setActionLoading(userId)
      await api.delete(`/users/${userId}`)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      showNotification('success', t('users.messages.deleted'))
    },
    onError: (error) => {
      showNotification('error', getErrorMessage(error))
    },
    onSettled: () => {
      setActionLoading(null)
      setShowActionMenu(null)
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

  const handleAction = (action: string, userId: string) => {
    switch (action) {
      case 'edit': {
        setShowActionMenu(null)
        const userToEdit = users.find((u) => u.id === userId)
        if (userToEdit) {
          setEditUser(userToEdit as User)
        }
        break
      }
      case 'activate':
        activateMutation.mutate(userId)
        break
      case 'deactivate':
        if (confirm(t('users.confirmations.deactivate'))) {
          deactivateMutation.mutate(userId)
        } else {
          setShowActionMenu(null)
        }
        break
      case 'reset-password':
        resetPasswordMutation.mutate(userId)
        break
      case 'set-pin':
        setShowActionMenu(null)
        setShowPinModal(userId)
        break
      case 'delete':
        if (confirm(t('users.confirmations.delete'))) {
          deleteMutation.mutate(userId)
        } else {
          setShowActionMenu(null)
        }
        break
    }
  }

  return (
    <div className="space-y-6">
      {/* Notification */}
      {notification && (
        <div
          className={`fixed top-4 right-4 z-50 rounded-lg p-4 shadow-lg ${
            notification.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'
          }`}
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

      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/settings"
            className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900"
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <h1 className="text-2xl font-bold text-gray-900 flex items-center gap-2">
              <Users className="h-6 w-6 text-blue-500" />
              {t('users.title')}
            </h1>
            <p className="text-gray-500">
              {t(total === 1 ? 'users.count' : 'users.count_plural', { count: total })}
            </p>
          </div>
        </div>
        <button
          onClick={() => { setShowAddModal(true) }}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <UserPlus className="h-4 w-4" />
          {t('users.addUser')}
        </button>
      </div>

      {/* Filters */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <FilterTabs tabs={filterTabs} value={statusFilter} onChange={(value) => { setStatusFilter(value) }} />
        <SearchInput
          value={searchQuery}
          onChange={(value) => { setSearchQuery(value) }}
          placeholder={t('users.searchPlaceholder')}
          className="w-full sm:w-72"
        />
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : error ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('users.errorLoading')}
        </div>
      ) : users.length === 0 ? (
        <div className="rounded-lg border-2 border-dashed border-gray-300 p-12 text-center">
          <Users className="mx-auto h-12 w-12 text-gray-400" />
          <h3 className="mt-2 text-sm font-semibold text-gray-900">
            {searchQuery ? t('users.empty.noResults') : t('users.empty.title')}
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            {searchQuery
              ? t('users.empty.tryDifferent')
              : t('users.empty.getStarted')}
          </p>
          {!searchQuery && (
            <div className="mt-6">
              <button
                onClick={() => { setShowAddModal(true) }}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"
              >
                <UserPlus className="h-4 w-4" />
                {t('users.addUser')}
              </button>
            </div>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('users.table.user')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('users.table.role')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('users.table.status')}
                </th>
                <th className="px-6 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                  {t('users.table.lastLogin')}
                </th>
                <th className="relative px-6 py-3">
                  <span className="sr-only">{t('users.table.actions')}</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {users.map((user) => (
                <tr key={user.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex items-center gap-3">
                      <div className="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                        <span className="text-sm font-semibold text-blue-600">
                          {user.name.charAt(0).toUpperCase()}
                        </span>
                      </div>
                      <div>
                        <div className="font-medium text-gray-900">{user.name}</div>
                        <div className="text-sm text-gray-500 flex items-center gap-1">
                          <Mail className="h-3.5 w-3.5" />
                          {user.email}
                        </div>
                        {user.phone && (
                          <div className="text-sm text-gray-500 flex items-center gap-1">
                            <Phone className="h-3.5 w-3.5" />
                            {user.phone}
                          </div>
                        )}
                      </div>
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex flex-wrap gap-1">
                      {user.roles.map((role) => (
                        <span
                          key={role}
                          className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ${
                            roleColors[role] ?? 'bg-gray-100 text-gray-800'
                          }`}
                        >
                          {role}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        statusColors[user.status] ?? 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {t(`users.statusLabels.${user.status === 'pending_verification' ? 'pending' : user.status}`)}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {formatDate(user.lastLoginAt)}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-end text-sm">
                    <div className="relative">
                      <button
                        onClick={() => {
                          setShowActionMenu(showActionMenu === user.id ? null : user.id)
                        }}
                        disabled={actionLoading === user.id}
                        className="text-gray-400 hover:text-gray-600 p-1 rounded"
                      >
                        {actionLoading === user.id ? (
                          <div className="h-5 w-5 animate-spin rounded-full border-2 border-gray-300 border-t-blue-600" />
                        ) : (
                          <MoreVertical className="h-5 w-5" />
                        )}
                      </button>
                      {showActionMenu === user.id && (
                        <div className="absolute right-0 mt-2 w-48 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 z-10">
                          <div className="py-1">
                            <button
                              onClick={() => { handleAction('edit', user.id) }}
                              className="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                            >
                              <Pencil className="h-4 w-4 text-blue-500" />
                              {t('users.actions.edit', { defaultValue: 'Edit' })}
                            </button>
                            {user.status !== 'active' && (
                              <button
                                onClick={() => { handleAction('activate', user.id) }}
                                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                              >
                                <CheckCircle className="h-4 w-4 text-green-500" />
                                {t('users.actions.activate')}
                              </button>
                            )}
                            {user.status === 'active' && user.id !== currentUser?.id && (
                              <button
                                onClick={() => { handleAction('deactivate', user.id) }}
                                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                              >
                                <XCircle className="h-4 w-4 text-yellow-500" />
                                {t('users.actions.deactivate')}
                              </button>
                            )}
                            <button
                              onClick={() => { handleAction('set-pin', user.id) }}
                              className="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                            >
                              <Hash className="h-4 w-4 text-indigo-500" />
                              {t('users.actions.setPosPin', { defaultValue: 'Set POS PIN' })}
                            </button>
                            <button
                              onClick={() => { handleAction('reset-password', user.id) }}
                              className="flex w-full items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"
                            >
                              <KeyRound className="h-4 w-4 text-blue-500" />
                              {t('users.actions.resetPassword')}
                            </button>
                            {user.id !== currentUser?.id && (
                              <button
                                onClick={() => { handleAction('delete', user.id) }}
                                className="flex w-full items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50"
                              >
                                <Trash2 className="h-4 w-4" />
                                {t('users.actions.delete')}
                              </button>
                            )}
                          </div>
                        </div>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Add User Modal */}
      {showAddModal && (
        <AddUserModal
          roles={roles}
          onClose={() => { setShowAddModal(false) }}
          onSubmit={(data) => { createUserMutation.mutate(data) }}
          isLoading={createUserMutation.isPending}
        />
      )}

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
      {showPinModal && (
        <PosPinModal
          userId={showPinModal}
          onClose={() => { setShowPinModal(null) }}
          onSubmit={(pin) => { setPosPinMutation.mutate({ userId: showPinModal, pin }) }}
          onClear={() => { setPosPinMutation.mutate({ userId: showPinModal, pin: null }) }}
          isLoading={setPosPinMutation.isPending}
        />
      )}

      {/* Click outside to close action menu */}
      {showActionMenu && (
        <div className="fixed inset-0 z-0" onClick={() => { setShowActionMenu(null) }} />
      )}
    </div>
  )
}

interface AddUserModalProps {
  roles: Role[]
  onClose: () => void
  onSubmit: (data: CreateUserData) => void
  isLoading: boolean
}

interface PosPinModalProps {
  userId: string
  onClose: () => void
  onSubmit: (pin: string) => void
  onClear: () => void
  isLoading: boolean
}

function PosPinModal({ onClose, onSubmit, onClear, isLoading }: PosPinModalProps) {
  const { t } = useTranslation()
  const [pin, setPin] = useState('')
  const [pinError, setPinError] = useState<string | null>(null)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!/^\d{4,6}$/.test(pin)) {
      setPinError(t('users.validation.invalidPin', { defaultValue: 'PIN must be 4-6 digits' }))
      return
    }
    setPinError(null)
    onSubmit(pin)
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      <div className="flex min-h-full items-center justify-center p-4">
        <div className="fixed inset-0 bg-black bg-opacity-25" onClick={onClose} />
        <div className="relative w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">
            {t('users.pinModal.title', { defaultValue: 'Set POS PIN' })}
          </h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="posPin" className="block text-sm font-medium text-gray-700">
                {t('users.pinModal.pinLabel', { defaultValue: 'PIN (4-6 digits)' })}
              </label>
              <input
                type="text"
                id="posPin"
                inputMode="numeric"
                pattern="[0-9]*"
                maxLength={6}
                value={pin}
                onChange={(e) => {
                  const v = e.target.value.replace(/\D/g, '')
                  setPin(v)
                  setPinError(null)
                }}
                className={`mt-1 block w-full rounded-md border px-3 py-2 text-center text-2xl tracking-[0.5em] shadow-sm focus:outline-none focus:ring-1 ${
                  pinError
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                }`}
                autoFocus
              />
              {pinError && <p className="mt-1 text-sm text-red-600">{pinError}</p>}
            </div>

            <div className="flex justify-between gap-3 pt-2">
              <button
                type="button"
                onClick={onClear}
                disabled={isLoading}
                className="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
              >
                {t('users.pinModal.clearPin', { defaultValue: 'Clear PIN' })}
              </button>
              <div className="flex gap-3">
                <button
                  type="button"
                  onClick={onClose}
                  className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                  {t('actions.cancel')}
                </button>
                <button
                  type="submit"
                  disabled={isLoading || !pin}
                  className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                  {isLoading ? t('status.saving', { defaultValue: 'Saving...' }) : t('actions.save', { defaultValue: 'Save' })}
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}

function AddUserModal({ roles, onClose, onSubmit, isLoading }: AddUserModalProps) {
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
    if (!formData.email.trim()) {
      newErrors['email'] = t('users.validation.emailRequired')
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
      newErrors['email'] = t('users.validation.invalidEmail')
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
        phone: formData.phone || undefined,
      })
    }
  }

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto">
      <div className="flex min-h-full items-center justify-center p-4">
        <div className="fixed inset-0 bg-black bg-opacity-25" onClick={onClose} />
        <div className="relative w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
          <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('users.modal.title')}</h2>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                {t('users.modal.nameLabel')} *
              </label>
              <input
                type="text"
                id="name"
                value={formData.name}
                onChange={(e) => { setFormData({ ...formData, name: e.target.value }) }}
                className={`mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-1 ${
                  errors['name']
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                }`}
                placeholder={t('users.modal.namePlaceholder')}
              />
              {errors['name'] && <p className="mt-1 text-sm text-red-600">{errors['name']}</p>}
            </div>

            <div>
              <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                {t('users.modal.emailLabel')} *
              </label>
              <input
                type="email"
                id="email"
                value={formData.email}
                onChange={(e) => { setFormData({ ...formData, email: e.target.value }) }}
                className={`mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-1 ${
                  errors['email']
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                }`}
                placeholder={t('users.modal.emailPlaceholder')}
              />
              {errors['email'] && <p className="mt-1 text-sm text-red-600">{errors['email']}</p>}
            </div>

            <div>
              <label htmlFor="phone" className="block text-sm font-medium text-gray-700">
                {t('users.modal.phoneLabel')}
              </label>
              <input
                type="tel"
                id="phone"
                value={formData.phone}
                onChange={(e) => { setFormData({ ...formData, phone: e.target.value }) }}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                placeholder={t('users.modal.phonePlaceholder')}
              />
            </div>

            <div>
              <label htmlFor="role" className="block text-sm font-medium text-gray-700">
                {t('users.modal.roleLabel')} *
              </label>
              <select
                id="role"
                value={formData.role}
                onChange={(e) => { setFormData({ ...formData, role: e.target.value }) }}
                className={`mt-1 block w-full rounded-md border px-3 py-2 shadow-sm focus:outline-none focus:ring-1 ${
                  errors['role']
                    ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                    : 'border-gray-300 focus:border-blue-500 focus:ring-blue-500'
                }`}
              >
                {roles.map((role) => (
                  <option key={role.name} value={role.name}>
                    {role.name.charAt(0).toUpperCase() + role.name.slice(1)}
                  </option>
                ))}
              </select>
              {errors['role'] && <p className="mt-1 text-sm text-red-600">{errors['role']}</p>}
            </div>

            <p className="text-sm text-gray-500">
              {t('users.modal.invitationNote')}
            </p>

            <div className="flex justify-end gap-3 pt-4">
              <button
                type="button"
                onClick={onClose}
                className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
              >
                {t('actions.cancel')}
              </button>
              <button
                type="submit"
                disabled={isLoading}
                className="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
              >
                {isLoading ? (
                  <>
                    <div className="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent" />
                    {t('status.creating')}
                  </>
                ) : (
                  <>
                    <UserPlus className="h-4 w-4" />
                    {t('users.modal.createUser')}
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
