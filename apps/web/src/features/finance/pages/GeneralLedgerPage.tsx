import { useState } from 'react'
import { Download } from 'lucide-react'
import { useLedger } from '../hooks/useLedger'
import { useAccounts } from '../hooks/useAccounts'
import { LedgerFilters } from '../components/LedgerFilters'
import { LedgerTable } from '../components/LedgerTable'
import { QueryError } from '@/components/QueryError'
import type { LedgerFilters as LedgerFiltersType } from '../types'

export function GeneralLedgerPage() {
  const [filters, setFilters] = useState<LedgerFiltersType>({})
  const { data: ledgerData, isLoading, error, refetch } = useLedger(filters)
  const { data: accounts } = useAccounts()

  const handleExport = () => {
    // Export functionality to be implemented
    console.log('Export ledger')
  }

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
        title="Failed to load ledger"
      />
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">General Ledger</h1>
          <p className="text-gray-500">View all journal entries and account balances</p>
        </div>
        <button
          onClick={handleExport}
          className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
        >
          <Download className="h-4 w-4" />
          Export
        </button>
      </div>

      <div className="bg-white rounded-lg border border-gray-200 p-6">
        <LedgerFilters
          filters={filters}
          accounts={accounts || []}
          onFiltersChange={setFilters}
        />
      </div>

      <div className="bg-white rounded-lg border border-gray-200">
        <LedgerTable lines={ledgerData?.lines || []} />
      </div>
    </div>
  )
}
