import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Plus, Crown, Trash2, Play, Pause } from 'lucide-react'
import { Button, Select } from '@/components/atoms'
import { SearchInput } from '@/components/molecules/SearchInput/SearchInput'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { Spinner } from '@/components/atoms/Spinner/Spinner'

import { usePrograms, useDeleteProgram, useActivateProgram, useDeactivateProgram } from '../hooks/usePrograms'
import { ProgramStatusBadge } from '../components/ProgramStatusBadge'
import type { LoyaltyProgram, ProgramStatus } from '../types/loyalty'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

type ConfirmAction = {
  type: 'delete' | 'activate' | 'deactivate'
  program: LoyaltyProgram
}

export function ProgramListPage() {
  const { t } = useTranslation(['loyalty', 'common'])
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>('')
  const [confirmAction, setConfirmAction] = useState<ConfirmAction | null>(null)

  const { data: programs, isLoading } = usePrograms()
  const deleteMutation = useDeleteProgram()
  const activateMutation = useActivateProgram()
  const deactivateMutation = useDeactivateProgram()

  const filtered = (programs ?? []).filter((p) => {
    if (statusFilter && p.status !== statusFilter) return false
    if (search && !p.name.toLowerCase().includes(search.toLowerCase())) return false
    return true
  })

  const handleConfirm = () => {
    if (!confirmAction) return
    const { type, program } = confirmAction
    const onSettled = () => { setConfirmAction(null); }

    switch (type) {
      case 'delete':
        deleteMutation.mutate(program.id, { onSettled })
        break
      case 'activate':
        activateMutation.mutate(program.id, { onSettled })
        break
      case 'deactivate':
        deactivateMutation.mutate(program.id, { onSettled })
        break
    }
  }

  const getConfirmDialogProps = () => {
    if (!confirmAction) return { title: '', message: '', variant: 'warning' as const }
    switch (confirmAction.type) {
      case 'delete':
        return {
          title: t('loyalty:actions.delete'),
          message: t('loyalty:programs.deleteConfirm'),
          variant: 'danger' as const,
        }
      case 'activate':
        return {
          title: t('loyalty:actions.activate'),
          message: t('loyalty:programs.activateConfirm'),
          variant: 'info' as const,
        }
      case 'deactivate':
        return {
          title: t('loyalty:actions.deactivate'),
          message: t('loyalty:programs.deactivateConfirm'),
          variant: 'warning' as const,
        }
    }
  }

  const isActionPending = deleteMutation.isPending || activateMutation.isPending || deactivateMutation.isPending

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <PageHeaderTitle className={`text-2xl font-bold ${colorTokens.text.primary}`}>{t('loyalty:title')}</PageHeaderTitle>
          <p className={`text-sm mt-1 ${colorTokens.text.subtle}`}>{t('loyalty:subtitle')}</p>
        </div>
        <Button onClick={() => navigate('/pos/loyalty/programs/new')}>
          <Plus className="w-4 h-4 mr-2" />
          {t('loyalty:programs.create')}
        </Button>
      </div>

      <div className="flex gap-4">
        <SearchInput
          value={search}
          onChange={setSearch}
          placeholder={t('common:search')}
          className="max-w-xs"
        />
        <Select
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); }}
          className="w-auto"
        >
          <option value="">{t('common:all')}</option>
          {(['draft', 'active', 'paused', 'archived'] as ProgramStatus[]).map((s) => (
            <option key={s} value={s}>{t(`loyalty:statuses.${s}`)}</option>
          ))}
        </Select>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : filtered.length === 0 ? (
        <div className="text-center py-12">
          <Crown className={`w-12 h-12 mx-auto ${colorTokens.text.faint} mb-4`} />
          <p className={`${colorTokens.text.subtle} font-medium`}>{t('loyalty:programs.noPrograms')}</p>
          <p className={`${colorTokens.text.disabled} text-sm mt-1`}>{t('loyalty:programs.noProgramsDescription')}</p>
        </div>
      ) : (
        <div className={`overflow-x-auto rounded-lg border ${colorTokens.border.subtle}`}>
          <DataTable className={`min-w-full divide-y ${colorTokens.border.divider}`}>
            <thead className={`${colorTokens.surface.page}`}>
              <tr>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('loyalty:fields.name')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('loyalty:fields.programType')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('loyalty:fields.status')}
                </th>
                <th className={`px-4 py-3 text-left text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('loyalty:fields.startDate')}
                </th>
                <th className={`px-4 py-3 text-right text-xs font-medium ${colorTokens.text.subtle} uppercase`}>
                  {t('common:table.actions')}
                </th>
              </tr>
            </thead>
            <tbody className={`${colorTokens.surface.base} divide-y ${colorTokens.border.divider}`}>
              {filtered.map((program) => (
                <tr
                  key={program.id}
                  className={`${colorTokens.intent.neutral.bgHover} cursor-pointer`}
                  onClick={() => navigate(`/pos/loyalty/programs/${program.id}`)}
                >
                  <td className={`px-4 py-3 font-medium ${colorTokens.text.primary}`}>{program.name}</td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.secondary}`}>
                    {t(`loyalty:programTypes.${program.program_type}`)}
                  </td>
                  <td className="px-4 py-3">
                    <ProgramStatusBadge status={program.status} />
                  </td>
                  <td className={`px-4 py-3 text-sm ${colorTokens.text.subtle}`}>
                    {program.start_date ? new Date(program.start_date).toLocaleDateString() : '-'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex items-center justify-end gap-1" onClick={(e) => { e.stopPropagation(); }}>
                      {program.status === 'draft' || program.status === 'paused' ? (
                        <button
                          onClick={() => { setConfirmAction({ type: 'activate', program }); }}
                          className={`p-1.5 rounded ${colorTokens.intent.success.bgHover} ${colorTokens.intent.success.text}`}
                          title={t('loyalty:actions.activate')}
                        >
                          <Play className="w-4 h-4" />
                        </button>
                      ) : null}
                      {program.status === 'active' ? (
                        <button
                          onClick={() => { setConfirmAction({ type: 'deactivate', program }); }}
                          className={`p-1.5 rounded ${colorTokens.intent.notice.bgHover} ${colorTokens.intent.notice.text}`}
                          title={t('loyalty:actions.deactivate')}
                        >
                          <Pause className="w-4 h-4" />
                        </button>
                      ) : null}
                      {program.status !== 'active' ? (
                        <button
                          onClick={() => { setConfirmAction({ type: 'delete', program }); }}
                          className={`p-1.5 rounded ${colorTokens.intent.danger.bgHover} ${colorTokens.intent.danger.textSubtle}`}
                          title={t('loyalty:actions.delete')}
                        >
                          <Trash2 className="w-4 h-4" />
                        </button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </DataTable>
        </div>
      )}

      <ConfirmDialog
        isOpen={confirmAction !== null}
        onClose={() => { setConfirmAction(null); }}
        onConfirm={handleConfirm}
        isLoading={isActionPending}
        {...getConfirmDialogProps()}
      />
    </div>
  )
}
