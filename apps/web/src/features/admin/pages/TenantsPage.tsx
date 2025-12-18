import { useState } from 'react'
import {
  useTenants,
  useExtendTrial,
  useSuspendTenant,
  useActivateTenant,
} from '../hooks/useTenants'
import { TenantDetailModal } from '../components/TenantDetailModal'
import { QueryError } from '@/components/QueryError'

export function TenantsPage() {
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [selectedTenantId, setSelectedTenantId] = useState<string | null>(null)

  const params: { search?: string; status?: string } = {}
  if (search) params.search = search
  if (statusFilter) params.status = statusFilter

  const { data: tenantsData, isLoading, error, refetch } = useTenants(params)
  const extendTrialMutation = useExtendTrial()
  const suspendMutation = useSuspendTenant()
  const activateMutation = useActivateTenant()

  const tenants = tenantsData?.data ?? []

  const handleExtendTrial = (tenantId: string) => {
    const days = prompt('Enter number of days to extend trial:')
    if (days && !isNaN(parseInt(days))) {
      extendTrialMutation.mutate({ tenantId, days: parseInt(days) })
    }
  }

  const handleSuspend = (tenantId: string) => {
    const reason = prompt('Enter suspension reason (optional):')
    if (confirm('Are you sure you want to suspend this tenant?')) {
      const mutateData: { tenantId: string; reason?: string } = { tenantId }
      if (reason) mutateData.reason = reason
      suspendMutation.mutate(mutateData)
    }
  }

  const handleActivate = (tenantId: string) => {
    if (confirm('Are you sure you want to activate this tenant?')) {
      activateMutation.mutate(tenantId)
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="text-gray-500">Loading tenants...</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-8">
        <QueryError
          error={error}
          onRetry={refetch}
          title="Failed to load tenants"
        />
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <h1 className="mb-8 text-3xl font-bold text-gray-900">
          Tenant Management
        </h1>

        <div className="mb-6 flex gap-4">
          <input
            type="text"
            placeholder="Search tenants..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); }}
            className="flex-1 rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none"
          />
          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); }}
            className="rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none"
          >
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="trial">Trial</option>
            <option value="suspended">Suspended</option>
            <option value="expired">Expired</option>
          </select>
        </div>

        <div className="overflow-hidden rounded-lg bg-white shadow">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Name
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Plan
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Email
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {tenants.map((tenant) => (
                <tr key={tenant.id}>
                  <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                    {tenant.name}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    <span
                      className={`inline-flex rounded-full px-2 text-xs font-semibold leading-5 ${
                        tenant.status === 'active'
                          ? 'bg-green-100 text-green-800'
                          : tenant.status === 'trial'
                            ? 'bg-blue-100 text-blue-800'
                            : tenant.status === 'suspended'
                              ? 'bg-red-100 text-red-800'
                              : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {tenant.status}
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {tenant.subscription?.plan.name ?? 'N/A'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {tenant.email ?? 'N/A'}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    <div className="flex gap-2">
                      <button
                        onClick={() => { setSelectedTenantId(tenant.id); }}
                        className="text-indigo-600 hover:text-indigo-900"
                      >
                        View
                      </button>
                      {tenant.subscription?.status === 'trial' && (
                        <button
                          onClick={() => { handleExtendTrial(tenant.id); }}
                          className="text-blue-600 hover:text-blue-900"
                        >
                          Extend Trial
                        </button>
                      )}
                      {tenant.status === 'active' && (
                        <button
                          onClick={() => { handleSuspend(tenant.id); }}
                          className="text-red-600 hover:text-red-900"
                        >
                          Suspend
                        </button>
                      )}
                      {tenant.status === 'suspended' && (
                        <button
                          onClick={() => { handleActivate(tenant.id); }}
                          className="text-green-600 hover:text-green-900"
                        >
                          Activate
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {tenants.length === 0 && (
            <div className="p-8 text-center text-gray-500">
              No tenants found
            </div>
          )}
        </div>
      </div>

      <TenantDetailModal
        tenantId={selectedTenantId}
        onClose={() => { setSelectedTenantId(null); }}
      />
    </div>
  )
}
