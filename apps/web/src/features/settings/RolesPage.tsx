import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Shield, Check, X, Plus, Pencil, Trash2, Users, Lock } from 'lucide-react'
import { api } from '../../lib/api'
import { Button } from '../../components/atoms/Button/Button'
import { toast } from 'sonner'

interface Role {
  id: number
  name: string
  permissions: string[]
  users_count: number
  created_at: string | null
  updated_at: string | null
}

interface RolesResponse {
  data: Role[]
}

interface PermissionsResponse {
  data: Record<string, string[]>
}

const SYSTEM_ROLES = ['super-admin', 'admin', 'owner']

const translateModule = (t: (key: string, fallback: string) => string, module: string) =>
  t('permissions.modules.' + module, module)

const translateAction = (t: (key: string, fallback: string) => string, module: string, permission: string) => {
  const action = permission.replace(`${module}.`, '')
  return t('permissions.actions.' + action, action)
}

export function RolesPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingRole, setEditingRole] = useState<Role | null>(null)
  const [deleteRole, setDeleteRole] = useState<Role | null>(null)
  const [roleName, setRoleName] = useState('')
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([])

  const { data: rolesData, isLoading: loadingRoles, error: rolesError } = useQuery({
    queryKey: ['roles'],
    queryFn: async () => {
      const response = await api.get<RolesResponse>('/roles')
      return response.data
    },
  })

  const { data: permissionsData, isLoading: loadingPermissions } = useQuery({
    queryKey: ['permissions'],
    queryFn: async () => {
      const response = await api.get<PermissionsResponse>('/permissions')
      return response.data
    },
  })

  const createMutation = useMutation({
    mutationFn: async (data: { name: string; permissions: string[] }) => {
      const response = await api.post('/roles', data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      toast.success(t('roles.messages.created'))
      closeModal()
    },
    onError: () => {
      toast.error(t('errorMessages.generic'))
    },
  })

  const updateMutation = useMutation({
    mutationFn: async ({ id, data }: { id: number; data: { name?: string; permissions?: string[] } }) => {
      const response = await api.patch(`/roles/${id}`, data)
      return response.data
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      toast.success(t('roles.messages.updated'))
      closeModal()
    },
    onError: () => {
      toast.error(t('errorMessages.generic'))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: number) => {
      await api.delete(`/roles/${id}`)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['roles'] })
      toast.success(t('roles.messages.deleted'))
      setDeleteRole(null)
    },
    onError: (error: unknown) => {
      const err = error as { response?: { data?: { error?: { code?: string } } } }
      if (err.response?.data?.error?.code === 'ROLE_HAS_USERS') {
        toast.error(t('roles.messages.cannotDeleteWithUsers'))
      } else if (err.response?.data?.error?.code === 'SYSTEM_ROLE_PROTECTED') {
        toast.error(t('roles.messages.cannotDeleteSystem'))
      } else {
        toast.error(t('errorMessages.generic'))
      }
    },
  })

  const isLoading = loadingRoles || loadingPermissions
  const roles = rolesData?.data ?? []
  const permissionGroups = permissionsData?.data ?? {}
  const allPermissions = Object.values(permissionGroups).flat()

  const openCreateModal = () => {
    setEditingRole(null)
    setRoleName('')
    setSelectedPermissions([])
    setIsModalOpen(true)
  }

  const openEditModal = (role: Role) => {
    setEditingRole(role)
    setRoleName(role.name)
    setSelectedPermissions([...role.permissions])
    setIsModalOpen(true)
  }

  const closeModal = () => {
    setIsModalOpen(false)
    setEditingRole(null)
    setRoleName('')
    setSelectedPermissions([])
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!roleName.trim()) return

    if (editingRole) {
      const isSystemRole = SYSTEM_ROLES.includes(editingRole.name)
      updateMutation.mutate({
        id: editingRole.id,
        data: {
          ...(isSystemRole ? {} : { name: roleName }),
          permissions: selectedPermissions,
        },
      })
    } else {
      createMutation.mutate({
        name: roleName,
        permissions: selectedPermissions,
      })
    }
  }

  const togglePermission = (permission: string) => {
    setSelectedPermissions((prev) =>
      prev.includes(permission)
        ? prev.filter((p) => p !== permission)
        : [...prev, permission]
    )
  }

  const toggleModulePermissions = (permissions: string[]) => {
    const allSelected = permissions.every((p) => selectedPermissions.includes(p))
    if (allSelected) {
      setSelectedPermissions((prev) => prev.filter((p) => !permissions.includes(p)))
    } else {
      setSelectedPermissions((prev) => [...new Set([...prev, ...permissions])])
    }
  }

  const isSystemRole = (roleName: string) => SYSTEM_ROLES.includes(roleName)

  return (
    <div className="space-y-6">
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
              <Shield className="h-6 w-6 text-purple-500" />
              {t('roles.title')}
            </h1>
            <p className="text-gray-500">
              {t('roles.subtitle', { count: roles.length, permCount: allPermissions.length })}
            </p>
          </div>
        </div>
        <Button onClick={openCreateModal}>
          <Plus className="h-4 w-4 me-2" />
          {t('roles.addRole')}
        </Button>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-12">
          <div className="text-gray-500">{t('status.loading')}</div>
        </div>
      ) : rolesError ? (
        <div className="rounded-lg bg-red-50 p-4 text-red-700">
          {t('errors.loadingFailed')}
        </div>
      ) : (
        <div className="space-y-6">
          {/* Roles Overview */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {roles.map((role) => (
              <div
                key={role.id}
                className={`rounded-lg border bg-white p-4 transition-shadow ${
                  isSystemRole(role.name)
                    ? 'border-purple-200 bg-purple-50/50'
                    : 'border-gray-200 hover:shadow-md cursor-pointer'
                }`}
                onClick={() => !isSystemRole(role.name) && openEditModal(role)}
              >
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className={`rounded-lg p-2 ${
                      isSystemRole(role.name) ? 'bg-purple-200 text-purple-700' : 'bg-purple-100 text-purple-600'
                    }`}>
                      {isSystemRole(role.name) ? (
                        <Lock className="h-5 w-5" />
                      ) : (
                        <Shield className="h-5 w-5" />
                      )}
                    </div>
                    <div>
                      <h3 className="font-semibold text-gray-900 capitalize flex items-center gap-2">
                        {role.name}
                        {isSystemRole(role.name) && (
                          <span className="text-xs font-normal text-purple-600 bg-purple-100 px-2 py-0.5 rounded">
                            {t('roles.systemRole')}
                          </span>
                        )}
                      </h3>
                      <p className="text-sm text-gray-500">
                        {String(role.permissions.length)} {t('roles.permissions').toLowerCase()}
                      </p>
                    </div>
                  </div>
                  {!isSystemRole(role.name) && (
                    <div className="flex items-center gap-1">
                      <button
                        onClick={(e) => {
                          e.stopPropagation()
                          openEditModal(role)
                        }}
                        className="p-1.5 text-gray-400 hover:text-gray-600 rounded"
                        title={t('roles.editRole')}
                      >
                        <Pencil className="h-4 w-4" />
                      </button>
                      <button
                        onClick={(e) => {
                          e.stopPropagation()
                          setDeleteRole(role)
                        }}
                        className="p-1.5 text-gray-400 hover:text-red-600 rounded"
                        title={t('roles.deleteRole')}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </div>
                  )}
                </div>
                <div className="mt-3 flex items-center gap-2 text-xs text-gray-500">
                  <Users className="h-3.5 w-3.5" />
                  {role.users_count > 0
                    ? t('roles.usersAssigned', { count: role.users_count })
                    : t('roles.noUsers')
                  }
                </div>
                {!isSystemRole(role.name) && (
                  <div className="mt-2 text-xs text-gray-400">
                    {t('roles.clickToEdit')}
                  </div>
                )}
              </div>
            ))}
          </div>

          {/* Permission Matrix */}
          <div className="rounded-lg border border-gray-200 bg-white overflow-hidden">
            <div className="p-4 border-b border-gray-200">
              <h2 className="text-lg font-semibold text-gray-900">{t('roles.permissionMatrix')}</h2>
              <p className="text-sm text-gray-500">
                {t('roles.permissionMatrixDescription')}
              </p>
            </div>
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                  <tr>
                    <th className="sticky left-0 bg-gray-50 px-4 py-3 text-start text-xs font-medium uppercase tracking-wider text-gray-500">
                      {t('roles.permissions')}
                    </th>
                    {roles.map((role) => (
                      <th
                        key={role.id}
                        className="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500"
                      >
                        {role.name}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-200 bg-white">
                  {Object.entries(permissionGroups).map(([module, permissions]) => (
                    <>
                      {/* Module Header Row */}
                      <tr key={module} className="bg-gray-50">
                        <td
                          colSpan={roles.length + 1}
                          className="px-4 py-2 text-sm font-semibold text-gray-700 capitalize"
                        >
                          {translateModule(t, module)}
                        </td>
                      </tr>
                      {/* Permission Rows */}
                      {permissions.map((permission) => (
                        <tr key={permission} className="hover:bg-gray-50">
                          <td className="sticky left-0 bg-white whitespace-nowrap px-4 py-2 text-sm text-gray-700">
                            {translateAction(t, module, permission)}
                          </td>
                          {roles.map((role) => {
                            const hasPermission = role.permissions.includes(permission)
                            return (
                              <td key={role.id} className="px-4 py-2 text-center">
                                {hasPermission ? (
                                  <Check className="inline-block h-4 w-4 text-green-600" />
                                ) : (
                                  <X className="inline-block h-4 w-4 text-gray-300" />
                                )}
                              </td>
                            )
                          })}
                        </tr>
                      ))}
                    </>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          {/* Info */}
          <div className="rounded-lg bg-blue-50 border border-blue-200 p-4">
            <h3 className="font-medium text-blue-900">{t('roles.info.title')}</h3>
            <p className="mt-1 text-sm text-blue-700">
              {t('roles.info.description')}
            </p>
          </div>
        </div>
      )}

      {/* Create/Edit Modal */}
      {isModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-2xl max-h-[90vh] overflow-hidden rounded-lg bg-white shadow-xl">
            <div className="border-b border-gray-200 px-6 py-4">
              <h2 className="text-lg font-semibold text-gray-900">
                {editingRole ? t('roles.editRole') : t('roles.createRole')}
              </h2>
            </div>
            <form onSubmit={handleSubmit}>
              <div className="max-h-[60vh] overflow-y-auto p-6 space-y-6">
                {/* Role Name */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-1">
                    {t('roles.roleName')}
                  </label>
                  <input
                    type="text"
                    value={roleName}
                    onChange={(e) => { setRoleName(e.target.value); }}
                    placeholder={t('roles.roleNamePlaceholder')}
                    disabled={!!(editingRole && isSystemRole(editingRole.name))}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-purple-500 focus:outline-none focus:ring-1 focus:ring-purple-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                    required
                  />
                  {editingRole && isSystemRole(editingRole.name) && (
                    <p className="mt-1 text-xs text-gray-500">{t('roles.systemRoleHint')}</p>
                  )}
                </div>

                {/* Permissions */}
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-3">
                    {t('roles.selectPermissions')}
                  </label>
                  <div className="space-y-4 border border-gray-200 rounded-lg p-4 max-h-80 overflow-y-auto">
                    {Object.entries(permissionGroups).map(([module, permissions]) => {
                      const moduleSelected = permissions.filter((p) =>
                        selectedPermissions.includes(p)
                      ).length
                      const allModuleSelected = moduleSelected === permissions.length

                      return (
                        <div key={module} className="space-y-2">
                          <button
                            type="button"
                            onClick={() => { toggleModulePermissions(permissions); }}
                            className="flex items-center gap-2 text-sm font-medium text-gray-900 capitalize hover:text-purple-600"
                          >
                            <div
                              className={`h-4 w-4 rounded border ${
                                allModuleSelected
                                  ? 'bg-purple-600 border-purple-600'
                                  : moduleSelected > 0
                                  ? 'bg-purple-200 border-purple-400'
                                  : 'border-gray-300'
                              } flex items-center justify-center`}
                            >
                              {(allModuleSelected || moduleSelected > 0) && (
                                <Check className="h-3 w-3 text-white" />
                              )}
                            </div>
                            {translateModule(t, module)}
                            <span className="text-xs text-gray-400">
                              ({moduleSelected}/{permissions.length})
                            </span>
                          </button>
                          <div className="ms-6 grid grid-cols-2 gap-2">
                            {permissions.map((permission) => (
                              <label
                                key={permission}
                                className="flex items-center gap-2 text-sm text-gray-600 cursor-pointer hover:text-gray-900"
                              >
                                <input
                                  type="checkbox"
                                  checked={selectedPermissions.includes(permission)}
                                  onChange={() => { togglePermission(permission); }}
                                  className="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                                />
                                {translateAction(t, module, permission)}
                              </label>
                            ))}
                          </div>
                        </div>
                      )
                    })}
                  </div>
                  <p className="mt-2 text-xs text-gray-500">
                    {selectedPermissions.length} {t('roles.permissions').toLowerCase()} {t('status.active').toLowerCase()}
                  </p>
                </div>
              </div>
              <div className="border-t border-gray-200 px-6 py-4 flex justify-end gap-3">
                <Button type="button" variant="secondary" onClick={closeModal}>
                  {t('actions.cancel')}
                </Button>
                <Button
                  type="submit"
                  disabled={!roleName.trim() || createMutation.isPending || updateMutation.isPending}
                >
                  {createMutation.isPending || updateMutation.isPending
                    ? t('status.saving')
                    : editingRole
                    ? t('actions.save')
                    : t('roles.createRole')
                  }
                </Button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Delete Confirmation Modal */}
      {deleteRole && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
            <h2 className="text-lg font-semibold text-gray-900">
              {t('roles.confirmations.delete.title')}
            </h2>
            <p className="mt-2 text-sm text-gray-600">
              {t('roles.confirmations.delete.message', { name: deleteRole.name })}
            </p>
            {deleteRole.users_count > 0 && (
              <div className="mt-3 rounded-lg bg-amber-50 border border-amber-200 p-3">
                <p className="text-sm text-amber-800">
                  {t('roles.messages.cannotDeleteWithUsers')}
                </p>
              </div>
            )}
            <div className="mt-6 flex justify-end gap-3">
              <Button variant="secondary" onClick={() => { setDeleteRole(null); }}>
                {t('actions.cancel')}
              </Button>
              <Button
                variant="danger"
                onClick={() => { deleteMutation.mutate(deleteRole.id); }}
                disabled={deleteMutation.isPending || deleteRole.users_count > 0}
              >
                {deleteMutation.isPending ? t('status.processing') : t('roles.confirmations.delete.confirm')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
