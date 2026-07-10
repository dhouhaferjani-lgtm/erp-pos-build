import { useState } from 'react'
import {
  useTenants,
  useExtendTrial,
  useSuspendTenant,
  useActivateTenant,
} from '../hooks/useTenants'
import { TenantDetailModal } from '../components/TenantDetailModal'
import { QueryError } from '@/components/QueryError'
import { colorClasses } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'

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
        <div className={`${colorClasses.textGray500}`}>Loading tenants...</div>
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
        <h1 className={`mb-8 text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>
          Tenant Management
        </h1>

        <div className="mb-6 flex gap-4">
          <input
            type="text"
            placeholder="Search tenants..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); }}
            className={`flex-1 rounded-lg border ${colorClasses.borderGray300} px-4 py-2 ${colorClasses.focusBorderBlue500} focus:outline-none`}
          />
          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); }}
            className={`rounded-lg border ${colorClasses.borderGray300} px-4 py-2 ${colorClasses.focusBorderBlue500} focus:outline-none`}
          >
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="trial">Trial</option>
            <option value="suspended">Suspended</option>
            <option value="expired">Expired</option>
          </select>
        </div>

        <div className="overflow-hidden rounded-lg bg-white shadow">
          <DataTable className={`min-w-full divide-y ${colorClasses.divideGray200}`}>
            <thead className={`${colorClasses.bgGray50}`}>
              <tr>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Name
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Status
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Plan
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Email
                </th>
                <th className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider ${colorClasses.textGray500}`}>
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className={`divide-y ${colorClasses.divideGray200} bg-white`}>
              {tenants.map((tenant) => (
                <tr key={tenant.id}>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm font-medium ${colorClasses.textGray900}`}>
                    {tenant.name}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray500}`}>
                    <span
                      className={`inline-flex rounded-full px-2 text-xs font-semibold leading-5 ${
                        tenant.status === 'active'
                          ? `${colorClasses.bgGreen100} ${colorClasses.textGreen800}`
                          : tenant.status === 'trial'
                            ? `${colorClasses.bgBlue100} ${colorClasses.textBlue800}`
                            : tenant.status === 'suspended'
                              ? `${colorClasses.bgRed100} ${colorClasses.textRed800}`
                              : `${colorClasses.bgGray100} ${colorClasses.textGray800}`
                      }`}
                    >
                      {tenant.status}
                    </span>
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray500}`}>
                    {tenant.subscription?.plan.name ?? 'N/A'}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray500}`}>
                    {tenant.email ?? 'N/A'}
                  </td>
                  <td className={`whitespace-nowrap px-6 py-4 text-sm ${colorClasses.textGray500}`}>
                    <div className="flex gap-2">
                      <button
                        onClick={() => { setSelectedTenantId(tenant.id); }}
                        className={`${colorClasses.textIndigo600} ${colorClasses.hoverTextIndigo900}`}
                      >
                        View
                      </button>
                      {tenant.subscription?.status === 'trial' && (
                        <button
                          onClick={() => { handleExtendTrial(tenant.id); }}
                          className={`${colorClasses.textBlue600} ${colorClasses.hoverTextBlue900}`}
                        >
                          Extend Trial
                        </button>
                      )}
                      {tenant.status === 'active' && (
                        <button
                          onClick={() => { handleSuspend(tenant.id); }}
                          className={`${colorClasses.textRed600} ${colorClasses.hoverTextRed900}`}
                        >
                          Suspend
                        </button>
                      )}
                      {tenant.status === 'suspended' && (
                        <button
                          onClick={() => { handleActivate(tenant.id); }}
                          className={`${colorClasses.textGreen600} ${colorClasses.hoverTextGreen900}`}
                        >
                          Activate
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>

          {tenants.length === 0 && (
            <div className={`p-8 text-center ${colorClasses.textGray500}`}>
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
