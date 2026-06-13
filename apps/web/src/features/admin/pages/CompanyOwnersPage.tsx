import { useState } from 'react'
import { useAdminUsers, useVerifyUserEmail } from '../hooks/useUsers'
import { QueryError } from '@/components/QueryError'
import { CheckCircle, Mail, AlertCircle, Building2 } from 'lucide-react'

export function CompanyOwnersPage() {
  const [search, setSearch] = useState('')
  const [verificationFilter, setVerificationFilter] = useState<string>('unverified')

  const params: { search?: string; email_verified?: boolean } = {}
  if (search) params.search = search
  if (verificationFilter === 'unverified') params.email_verified = false
  else if (verificationFilter === 'verified') params.email_verified = true

  const { data: usersData, isLoading, error, refetch } = useAdminUsers(params)
  const verifyEmailMutation = useVerifyUserEmail()

  const users = usersData?.data ?? []

  const handleVerifyEmail = (userId: string, tenantId: string, userName: string) => {
    const notes = prompt(`Enter verification notes for ${userName} (optional):`)
    if (confirm(`Are you sure you want to verify email for ${userName}?`)) {
      verifyEmailMutation.mutate(notes ? { userId, tenantId, notes } : { userId, tenantId })
    }
  }

  if (isLoading) {
    return (
      <div className="flex h-screen items-center justify-center">
        <div className="text-gray-500">Loading company owners...</div>
      </div>
    )
  }

  if (error) {
    return (
      <div className="p-8">
        <QueryError
          error={error}
          onRetry={refetch}
          title="Failed to load company owners"
        />
      </div>
    )
  }

  const unverifiedCount = users.filter(u => !u.email_verified_at).length

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl">
        <div className="mb-8 flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900">
              Company Owners
            </h1>
            <p className="mt-1 text-sm text-gray-500">
              Manage email verification for users who registered new companies
            </p>
          </div>
          {unverifiedCount > 0 && verificationFilter !== 'unverified' && (
            <div className="flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-2 text-amber-700">
              <AlertCircle className="h-5 w-5" />
              <span className="text-sm font-medium">
                {unverifiedCount} pending verification{unverifiedCount !== 1 ? 's' : ''}
              </span>
            </div>
          )}
        </div>

        <div className="mb-6 flex gap-4">
          <input
            type="text"
            placeholder="Search by name or email..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); }}
            className="flex-1 rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none"
          />
          <select
            value={verificationFilter}
            onChange={(e) => { setVerificationFilter(e.target.value); }}
            className="rounded-lg border border-gray-300 px-4 py-2 focus:border-blue-500 focus:outline-none"
          >
            <option value="">All Users</option>
            <option value="unverified">Pending Verification</option>
            <option value="verified">Verified</option>
          </select>
        </div>

        <div className="overflow-hidden rounded-lg bg-white shadow">
          <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  User
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Company / Tenant
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Email Status
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Registered
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                  Actions
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200 bg-white">
              {users.map((user) => (
                <tr key={user.id}>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex items-center">
                      <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gray-100">
                        <span className="text-sm font-medium text-gray-600">
                          {user.name.charAt(0).toUpperCase()}
                        </span>
                      </div>
                      <div className="ms-4">
                        <div className="text-sm font-medium text-gray-900">
                          {user.name}
                        </div>
                        <div className="text-sm text-gray-500">{user.email}</div>
                      </div>
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    <div className="flex items-center gap-2">
                      <Building2 className="h-4 w-4 text-gray-400" />
                      <span className="text-sm text-gray-900">
                        {user.tenant?.name ?? 'Unknown'}
                      </span>
                    </div>
                  </td>
                  <td className="whitespace-nowrap px-6 py-4">
                    {user.email_verified_at ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                        <CheckCircle className="h-3 w-3" />
                        Verified
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                        <Mail className="h-3 w-3" />
                        Pending
                      </span>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                    {new Date(user.created_at).toLocaleDateString()}
                  </td>
                  <td className="whitespace-nowrap px-6 py-4 text-sm">
                    {!user.email_verified_at ? (
                      <button
                        onClick={() => { handleVerifyEmail(user.id, user.tenant_id, user.name); }}
                        disabled={verifyEmailMutation.isPending}
                        className="inline-flex items-center gap-1 rounded-md bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700 disabled:opacity-50"
                      >
                        <CheckCircle className="h-3.5 w-3.5" />
                        Verify Email
                      </button>
                    ) : (
                      <span className="text-gray-400">-</span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {users.length === 0 && (
            <div className="p-8 text-center">
              <Mail className="mx-auto h-12 w-12 text-gray-300" />
              <h3 className="mt-2 text-sm font-medium text-gray-900">
                No company owners found
              </h3>
              <p className="mt-1 text-sm text-gray-500">
                {verificationFilter === 'unverified'
                  ? 'All company owners have verified their email addresses.'
                  : 'No users match your search criteria.'}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
