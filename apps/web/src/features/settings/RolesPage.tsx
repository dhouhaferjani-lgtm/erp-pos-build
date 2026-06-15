import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Shield, Check, X, Plus, Pencil, Trash2, Users, Lock } from 'lucide-react'
import { api } from '../../lib/api'
import { tenantScopedKey } from '../../lib/tenantScopedKey'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'
import { cn } from '../../lib/utils'
import { tokens, textColors, borderColors, colors } from '../../lib/designTokens'
import { Button, Checkbox, FormField, Input } from '../../components/atoms'
import { Modal, ModalContent, ModalFooter } from '../../components/organisms/Modal'
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

export function RolesPage() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [isModalOpen, setIsModalOpen] = useState(false)
  const [editingRole, setEditingRole] = useState<Role | null>(null)
  const [deleteRole, setDeleteRole] = useState<Role | null>(null)
  const [roleName, setRoleName] = useState('')
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([])

  const { data: rolesData, isLoading: loadingRoles, error: rolesError } = useQuery({
    queryKey: tenantScopedKey(['roles']),
    queryFn: async () => {
      const response = await api.get<RolesResponse>('/roles')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const { data: permissionsData, isLoading: loadingPermissions } = useQuery({
    queryKey: tenantScopedKey(['permissions']),
    queryFn: async () => {
      const response = await api.get<PermissionsResponse>('/permissions')
      return response.data
    },
    enabled: tenantId !== null && companyId !== null,
  })

  const createMutation = useMutation({
    mutationFn: async (data: { name: string; permissions: string[] }) => {
      const response = await api.post('/roles', data)
      return response.data
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('roles', tenantId, companyId),
      })
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
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('roles', tenantId, companyId),
      })
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
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        predicate: scopedNamespacePredicate('roles', tenantId, companyId),
      })
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
      const isSystem = SYSTEM_ROLES.includes(editingRole.name)
      updateMutation.mutate({
        id: editingRole.id,
        data: {
          ...(isSystem ? {} : { name: roleName }),
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

  const isSystemRole = (name: string) => SYSTEM_ROLES.includes(name)

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Link
            to="/settings"
            className={cn('inline-flex items-center gap-2 text-sm', textColors.tertiary, textColors.hoverPrimary)}
          >
            <ArrowLeft className="h-4 w-4" />
            {t('actions.back')}
          </Link>
          <div>
            <h1 className={cn('text-2xl font-bold flex items-center gap-2', textColors.primary)}>
              <Shield className={cn('h-6 w-6', textColors.brand)} />
              {t('roles.title')}
            </h1>
            <p className={textColors.tertiary}>
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
          <div className={textColors.tertiary}>{t('status.loading')}</div>
        </div>
      ) : rolesError ? (
        <div className={cn(tokens.alert.base, tokens.alert.error)}>
          {t('errors.loadingFailed')}
        </div>
      ) : (
        <div className="space-y-6">
          {/* Roles Overview */}
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {roles.map((role) => (
              <div
                key={role.id}
                className={cn(
                  'rounded-lg border bg-white p-4 transition-shadow',
                  isSystemRole(role.name)
                    ? cn(borderColors.primary, colors.primary[50])
                    : cn(borderColors.light, 'hover:shadow-md cursor-pointer'),
                )}
                onClick={() => { if (!isSystemRole(role.name)) openEditModal(role) }}
              >
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className={cn('rounded-lg p-2', tokens.badge.blue)}>
                      {isSystemRole(role.name) ? (
                        <Lock className="h-5 w-5" />
                      ) : (
                        <Shield className="h-5 w-5" />
                      )}
                    </div>
                    <div>
                      <h3 className={cn('font-semibold capitalize flex items-center gap-2', textColors.primary)}>
                        {role.name}
                        {isSystemRole(role.name) && (
                          <span className={cn('text-xs font-normal', tokens.badge.base, tokens.badge.blue)}>
                            {t('roles.systemRole')}
                          </span>
                        )}
                      </h3>
                      <p className={cn('text-sm', textColors.tertiary)}>
                        {String(role.permissions.length)} {t('roles.permissions').toLowerCase()}
                      </p>
                    </div>
                  </div>
                  {!isSystemRole(role.name) && (
                    <div className="flex items-center gap-1">
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={(e) => {
                          e.stopPropagation()
                          openEditModal(role)
                        }}
                        title={t('roles.editRole')}
                      >
                        <Pencil className="h-4 w-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={(e) => {
                          e.stopPropagation()
                          setDeleteRole(role)
                        }}
                        title={t('roles.deleteRole')}
                      >
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  )}
                </div>
                <div className={cn('mt-3 flex items-center gap-2 text-xs', textColors.tertiary)}>
                  <Users className="h-3.5 w-3.5" />
                  {role.users_count > 0
                    ? t('roles.usersAssigned', { count: role.users_count })
                    : t('roles.noUsers')
                  }
                </div>
                {!isSystemRole(role.name) && (
                  <div className={cn('mt-2 text-xs', textColors.disabled)}>
                    {t('roles.clickToEdit')}
                  </div>
                )}
              </div>
            ))}
          </div>

          {/* Permission Matrix */}
          <div className={cn('rounded-lg border bg-white overflow-hidden', borderColors.light)}>
            <div className={cn('p-4 border-b', borderColors.light)}>
              <h2 className={cn('text-lg font-semibold', textColors.primary)}>{t('roles.permissionMatrix')}</h2>
              <p className={cn('text-sm', textColors.tertiary)}>
                {t('roles.permissionMatrixDescription')}
              </p>
            </div>
            <div className="overflow-x-auto">
              <table className={cn('min-w-full divide-y', borderColors.divideDefault)}>
                <thead className={tokens.table.header}>
                  <tr>
                    <th className={cn('sticky left-0 px-4 py-3 text-start text-xs font-medium uppercase tracking-wider', tokens.table.header, textColors.tertiary)}>
                      {t('roles.permissions')}
                    </th>
                    {roles.map((role) => (
                      <th
                        key={role.id}
                        className={cn('px-4 py-3 text-center text-xs font-medium uppercase tracking-wider', textColors.tertiary)}
                      >
                        {role.name}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className={cn('divide-y bg-white', borderColors.divideDefault)}>
                  {Object.entries(permissionGroups).map(([module, permissions]) => (
                    <>
                      {/* Module Header Row */}
                      <tr key={module} className={tokens.table.header}>
                        <td
                          colSpan={roles.length + 1}
                          className={cn('px-4 py-2 text-sm font-semibold capitalize', textColors.secondary)}
                        >
                          {translateModule(t, module)}
                        </td>
                      </tr>
                      {/* Permission Rows */}
                      {permissions.map((permission) => (
                        <tr key={permission} className={tokens.table.rowHover}>
                          <td className={cn('sticky left-0 bg-white whitespace-nowrap px-4 py-2 text-sm', textColors.secondary)}>
                            {translateAction(t, module, permission)}
                          </td>
                          {roles.map((role) => {
                            const hasPermission = role.permissions.includes(permission)
                            return (
                              <td key={role.id} className="px-4 py-2 text-center">
                                {hasPermission ? (
                                  <Check className={cn('inline-block h-4 w-4', textColors.success)} />
                                ) : (
                                  <X className={cn('inline-block h-4 w-4', textColors.disabled)} />
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
          <div className={cn(tokens.alert.base, tokens.alert.info)}>
            <h3 className="font-medium">{t('roles.info.title')}</h3>
            <p className="mt-1 text-sm">
              {t('roles.info.description')}
            </p>
          </div>
        </div>
      )}

      {/* Create/Edit Modal */}
      <Modal
        isOpen={isModalOpen}
        onClose={closeModal}
        title={editingRole ? t('roles.editRole') : t('roles.createRole')}
        size="lg"
      >
        <form onSubmit={handleSubmit}>
          <ModalContent className="max-h-[60vh] overflow-y-auto">
            {/* Role Name */}
            <FormField label={t('roles.roleName')} htmlFor="role-name">
              <Input
                id="role-name"
                type="text"
                value={roleName}
                onChange={(e) => { setRoleName(e.target.value) }}
                placeholder={t('roles.roleNamePlaceholder')}
                disabled={!!(editingRole && isSystemRole(editingRole.name))}
                required
              />
              {editingRole && isSystemRole(editingRole.name) && (
                <p className={tokens.helperText.base}>{t('roles.systemRoleHint')}</p>
              )}
            </FormField>

            {/* Permissions */}
            <div>
              <label className={cn('block mb-3', tokens.label.base)}>
                {t('roles.selectPermissions')}
              </label>
              <div className={cn('space-y-4 border rounded-lg p-4 max-h-80 overflow-y-auto', borderColors.light)}>
                {Object.entries(permissionGroups).map(([module, permissions]) => {
                  const moduleSelected = permissions.filter((p) =>
                    selectedPermissions.includes(p)
                  ).length
                  const allModuleSelected = moduleSelected === permissions.length

                  return (
                    <div key={module} className="space-y-2">
                      <button
                        type="button"
                        onClick={() => { toggleModulePermissions(permissions) }}
                        className={cn('flex items-center gap-2 text-sm font-medium capitalize', textColors.primary, textColors.brand)}
                      >
                        <span
                          className={cn(
                            'h-4 w-4 rounded border flex items-center justify-center',
                            allModuleSelected
                              ? cn(colors.primary[600], borderColors.primary)
                              : moduleSelected > 0
                              ? cn(colors.primary[100], borderColors.primary)
                              : borderColors.default,
                          )}
                        >
                          {(allModuleSelected || moduleSelected > 0) && (
                            <Check className={cn('h-3 w-3', textColors.inverse)} />
                          )}
                        </span>
                        {translateModule(t, module)}
                        <span className={cn('text-xs', textColors.disabled)}>
                          ({moduleSelected}/{permissions.length})
                        </span>
                      </button>
                      <div className="ms-6 grid grid-cols-2 gap-2">
                        {permissions.map((permission) => (
                          <label
                            key={permission}
                            className={cn('flex items-center gap-2 text-sm cursor-pointer', textColors.tertiary, textColors.hoverPrimary)}
                          >
                            <Checkbox
                              checked={selectedPermissions.includes(permission)}
                              onChange={() => { togglePermission(permission) }}
                            />
                            {translateAction(t, module, permission)}
                          </label>
                        ))}
                      </div>
                    </div>
                  )
                })}
              </div>
              <p className={tokens.helperText.base}>
                {selectedPermissions.length} {t('roles.permissions').toLowerCase()} {t('status.active').toLowerCase()}
              </p>
            </div>
          </ModalContent>
          <ModalFooter>
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
          </ModalFooter>
        </form>
      </Modal>

      {/* Delete Confirmation Modal */}
      <Modal
        isOpen={deleteRole !== null}
        onClose={() => { setDeleteRole(null) }}
        title={t('roles.confirmations.delete.title')}
        size="sm"
      >
        {deleteRole && (
          <ModalContent>
            <p className={cn('text-sm', textColors.tertiary)}>
              {t('roles.confirmations.delete.message', { name: deleteRole.name })}
            </p>
            {deleteRole.users_count > 0 && (
              <div className={cn(tokens.alert.base, tokens.alert.warning)}>
                {t('roles.messages.cannotDeleteWithUsers')}
              </div>
            )}
            <ModalFooter>
              <Button variant="secondary" onClick={() => { setDeleteRole(null) }}>
                {t('actions.cancel')}
              </Button>
              <Button
                variant="danger"
                onClick={() => { deleteMutation.mutate(deleteRole.id) }}
                disabled={deleteMutation.isPending || deleteRole.users_count > 0}
              >
                {deleteMutation.isPending ? t('status.processing') : t('roles.confirmations.delete.confirm')}
              </Button>
            </ModalFooter>
          </ModalContent>
        )}
      </Modal>
    </div>
  )
}
