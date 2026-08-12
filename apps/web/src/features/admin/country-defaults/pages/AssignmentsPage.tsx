import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Pin, RefreshCw } from 'lucide-react'
import { Button, Select } from '@/components/atoms'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { assignTemplate } from '../api/countryDefaultsApi'
import { useAssignments, useCountryDefaultsMutation, useTemplates } from '../hooks/useCountryDefaults'
import type { AssignmentMatrixRow, TemplateDomain } from '../types'
import { countryDefaultsErrorKey, countryDefaultsErrorStatus } from '../lib/apiError'

export function AssignmentsPage() {
  const { t } = useTranslation('adminCountryDefaults')
  const domain: TemplateDomain = 'chart_of_accounts'
  const assignments = useAssignments(domain)
  const templates = useTemplates(domain)
  const [selected, setSelected] = useState<Partial<Record<string, string>>>({})
  const [confirmCountry, setConfirmCountry] = useState<string | null>(null)
  const assign = useCountryDefaultsMutation(
    ({ countryCode, templateId }: { countryCode: string; templateId: string }) => (
      assignTemplate(countryCode, { domain, template_id: templateId })
    ),
  )
  const availableFor = (countryCode: string) => (templates.data ?? []).filter((template) => {
    if (template.status !== 'published') return false
    const scope = template.certified_country_codes ?? []
    return countryCode === '*' ? scope.length === 1 && scope[0] === '*' : scope.includes(countryCode)
  })
  const optionsFor = (row: AssignmentMatrixRow) => {
    const eligible = availableFor(row.country_code)
    if (row.template_id === null || eligible.some((template) => template.id === row.template_id)) return eligible
    return [{
      id: row.template_id,
      name: row.template?.name ?? row.template_id,
    }, ...eligible]
  }
  const columns: DataTableColumn<AssignmentMatrixRow>[] = [
    {
      key: 'country', header: t('assignments.columns.country'), render: (row) => (
        <div className="flex items-center gap-2">
          {row.pinned && <Pin aria-label={t('assignments.pinnedAria')} className={`h-4 w-4 ${textColors.brand}`} />}
          <div><p className={`font-medium ${textColors.primary}`}>{row.name}</p><p className={`font-mono text-xs ${textColors.tertiary}`}>{row.country_code}</p></div>
        </div>
      ),
    },
    {
      key: 'template', header: t('assignments.columns.template'), render: (row) => (
        <Select aria-label={t('assignments.templateAria', { country: row.country_code })} value={selected[row.country_code] ?? row.template_id ?? ''} onChange={(event) => { setSelected((current) => ({ ...current, [row.country_code]: event.target.value })) }}>
          {row.template_id === null && <option disabled value="">{t('assignments.selectTemplate')}</option>}
          {optionsFor(row).map((template) => <option value={template.id} key={template.id}>{template.name}</option>)}
        </Select>
      ),
    },
    {
      key: 'action', header: t('assignments.columns.action'), render: (row) => {
        const candidate = selected[row.country_code]
        return <Button size="sm" variant="secondary" disabled={candidate === undefined || candidate === '' || candidate === row.template_id} onClick={() => { assign.reset(); setConfirmCountry(row.country_code) }}><RefreshCw className="mr-1 h-4 w-4" />{t('assignments.repoint', { country: row.country_code })}</Button>
      },
    },
  ]

  return (
    <div className="p-8">
      <div className="mx-auto max-w-6xl space-y-6">
        <PageHeader title={t('assignments.title')} subtitle={t('assignments.subtitle')} breadcrumb={<Link className={`text-sm ${textColors.brand} hover:underline`} to="/admin/country-defaults">{t('assignments.back')}</Link>} />
        {!assignments.isError && <p className={`text-sm ${textColors.tertiary}`}>{t('assignments.count', { count: assignments.data?.data.length ?? 0 })}</p>}
        {assignments.isError && <div role="alert" className={tokens.alert.error}>{t('assignments.loadError')}</div>}
        {templates.isError && <div role="alert" className={tokens.alert.error}>{t(countryDefaultsErrorStatus(templates.error) === 403 ? 'assignments.templatesForbidden' : 'assignments.templatesLoadError')}</div>}
        {!assignments.isError && <div className={tokens.alert.info}>{t('assignments.catalogVersion', { version: assignments.data?.meta.catalog_version ?? '—' })}</div>}
        {!assignments.isError && <div className={`overflow-hidden rounded-xl border ${borderColors.light}`}>
          <DataTable columns={columns} data={assignments.data?.data ?? []} keyExtractor={(row) => row.country_code} isLoading={assignments.isLoading} emptyTitle={t('assignments.empty')} ariaLabel={t('assignments.tableLabel')} />
        </div>}
      </div>

      <Modal isOpen={confirmCountry !== null} onClose={() => { setConfirmCountry(null) }} title={t('assignments.confirmTitle', { country: confirmCountry ?? '' })}>
        {confirmCountry !== null && <>
          <ModalContent>
          <p className={tokens.alert.warning}>{t('assignments.newCompaniesOnly')}</p>
          <p className={`mt-4 text-sm ${textColors.tertiary}`}>{t('assignments.confirmDescription')}</p>
          {assign.error !== null && <div role="alert" className={tokens.alert.error}>{t(countryDefaultsErrorKey(assign.error))}</div>}
          </ModalContent>
          <ModalFooter><Button variant="secondary" onClick={() => { setConfirmCountry(null) }}>{t('common.cancel')}</Button><Button disabled={selected[confirmCountry] === undefined || selected[confirmCountry] === ''} onClick={() => { const templateId = selected[confirmCountry]; if (templateId !== undefined && templateId !== '') assign.mutate({ countryCode: confirmCountry, templateId }, { onSuccess: () => { setConfirmCountry(null) } }) }}>{t('assignments.confirm')}</Button></ModalFooter>
        </>}
      </Modal>
    </div>
  )
}
