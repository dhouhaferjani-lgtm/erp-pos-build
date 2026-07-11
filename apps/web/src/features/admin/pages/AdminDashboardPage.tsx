import { useAdminDashboard } from '../hooks/useAdminDashboard'
import { QueryError } from '@/components/QueryError'
import { PageHeader } from '@/components/molecules/PageHeader'
import { colorClasses } from '@/lib/designTokens'

export function AdminDashboardPage() {
  const { data: stats, isLoading, error, refetch } = useAdminDashboard()

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className={`${colorClasses.textGray500}`}>Loading dashboard...</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-8">
        <QueryError
          error={error}
          onRetry={refetch}
          title="Failed to load dashboard"
        />
      </div>
    )
  }

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <PageHeader title="Super Admin Dashboard" />

        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="p-6">
              <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                Total Tenants
              </div>
              <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>
                {stats?.total_tenants ?? 0}
              </div>
            </div>
          </div>

          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="p-6">
              <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                Active Tenants
              </div>
              <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGreen600}`}>
                {stats?.active_tenants ?? 0}
              </div>
            </div>
          </div>

          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="p-6">
              <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                Trial Tenants
              </div>
              <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textBlue600}`}>
                {stats?.trial_tenants ?? 0}
              </div>
            </div>
          </div>

          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="p-6">
              <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                Expired Tenants
              </div>
              <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textRed600}`}>
                {stats?.expired_tenants ?? 0}
              </div>
            </div>
          </div>

          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="p-6">
              <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                Total Users
              </div>
              <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>
                {stats?.total_users ?? 0}
              </div>
            </div>
          </div>

          <div className="overflow-hidden rounded-lg bg-white shadow">
            <div className="p-6">
              <div className={`text-sm font-medium ${colorClasses.textGray500}`}>
                Total Companies
              </div>
              <div className={`mt-2 text-[1.875rem] leading-9 font-bold ${colorClasses.textGray900}`}>
                {stats?.total_companies ?? 0}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
