import { useState } from 'react'
import { Plus } from 'lucide-react'
import { useAccounts } from '../hooks/useAccounts'
import { AccountTreeView } from '../components/AccountTreeView'
import { AddAccountModal } from '../components/AddAccountModal'
import { EditAccountModal } from '../components/EditAccountModal'
import { QueryError } from '@/components/QueryError'
import type { Account } from '../types'

export function ChartOfAccountsPage() {
  const { data: accounts, isLoading, error, refetch } = useAccounts()
  const [isAddModalOpen, setIsAddModalOpen] = useState(false)
  const [editingAccount, setEditingAccount] = useState<Account | null>(null)

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <p className="text-gray-500">Loading...</p>
      </div>
    )
  }

  if (error) {
    return (
      <QueryError
        error={error}
        onRetry={refetch}
        title="Failed to load chart of accounts"
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Chart of Accounts</h1>
          <p className="text-gray-500">Manage your chart of accounts</p>
        </div>
        <button
          onClick={() => { setIsAddModalOpen(true); }}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
        >
          <Plus className="h-4 w-4" />
          Add Account
        </button>
      </div>

      <div className="bg-white rounded-lg border border-gray-200">
        <AccountTreeView
          accounts={accounts || []}
          onEdit={(account) => { setEditingAccount(account); }}
        />
      </div>

      <AddAccountModal
        open={isAddModalOpen}
        onClose={() => { setIsAddModalOpen(false); }}
        onSuccess={() => { setIsAddModalOpen(false); }}
      />

      {editingAccount && (
        <EditAccountModal
          account={editingAccount}
          open={!!editingAccount}
          onClose={() => { setEditingAccount(null); }}
          onSuccess={() => { setEditingAccount(null); }}
        />
      )}
    </div>
  )
}
