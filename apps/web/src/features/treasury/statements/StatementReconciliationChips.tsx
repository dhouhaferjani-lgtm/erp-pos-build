import { useQuery } from '@tanstack/react-query'
import { Link2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

import { StatusBadge } from '@/components/atoms'
import { usePermissions } from '@/hooks/usePermissions'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { getStatementTargetProvenance } from './api'

interface StatementReconciliationChipsProps {
  targetType: 'payment_instrument' | 'expense_document' | 'income_document'
  targetId: string
}

export function StatementReconciliationChips({ targetType, targetId }: StatementReconciliationChipsProps) {
  const { t } = useTranslation('treasury')
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)
  const { hasPermission } = usePermissions()
  const allowed = hasPermission('bank-statements.view')
  const provenanceQuery = useQuery({
    queryKey: tenantScopedKey(['bank-statement-target-provenance', targetType, targetId]),
    queryFn: () => getStatementTargetProvenance(targetType, targetId),
    enabled: allowed && Boolean(targetId) && tenantId !== null && companyId !== null,
  })

  if (!allowed || !provenanceQuery.data?.length) return null

  return <div className="inline-flex flex-wrap gap-2">{provenanceQuery.data.map((provenance) => <Link key={`${provenance.bank_statement_id}-${provenance.bank_statement_line_id}`} to={`/treasury/statements/${provenance.bank_statement_id}?line=${provenance.bank_statement_line_id}`}><StatusBadge tone="info"><Link2 className="me-1 h-3 w-3" />{t('statements.workspace.reconciledBy')}</StatusBadge></Link>)}</div>
}
