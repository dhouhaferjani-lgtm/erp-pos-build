import { useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { LockKeyhole, Plus, ShieldCheck, Trash2, X } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { Button, Checkbox, Input, Select } from '@/components/atoms'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { borderColors, semanticColorTokens, textColors, tokens } from '@/lib/designTokens'
import { publishTemplate, saveTemplateRows, updateTemplate, validateTemplate } from '../api/countryDefaultsApi'
import { useCountryDefaultsMutation, useTemplate } from '../hooks/useCountryDefaults'
import type { TemplateAccount } from '../types'

interface PublishFields {
  standardRef: string
  scope: string
}

function isAccountType(value: string, choices: readonly string[]): value is TemplateAccount['type'] {
  return choices.includes(value)
}

function isSystemPurpose(value: string, choices: readonly string[]): value is NonNullable<TemplateAccount['system_purpose']> {
  return choices.includes(value)
}

function blankRow(sortOrder: number): TemplateAccount {
  return {
    id: crypto.randomUUID(), code: '', name: '', type: 'asset', parent_code: null,
    system_purpose: null, is_system: false, sort_order: sortOrder,
    is_protected: false, protection_source: null,
  }
}

function rowCodeSet(rows: readonly TemplateAccount[]): Set<string> {
  const codes = new Set<string>()
  for (const row of rows) {
    if (row.code !== '') codes.add(row.code)
  }
  return codes
}

export function TemplateEditorPage() {
  const { t } = useTranslation('adminCountryDefaults')
  const { templateId = '' } = useParams()
  const templateQuery = useTemplate(templateId)
  const [rowEdits, setRowEdits] = useState<TemplateAccount[] | null>(null)
  const [showPublish, setShowPublish] = useState(false)
  const [publishedHash, setPublishedHash] = useState<string | null>(null)
  const [gridErrors, setGridErrors] = useState<Record<string, string>>({})
  const publishForm = useForm<PublishFields>({ defaultValues: { standardRef: '', scope: '' } })
  const scope = useWatch({ control: publishForm.control, name: 'scope' })
  const validation = useQuery({
    queryKey: ['admin', 'country-defaults', 'templates', templateId, 'validation', scope],
    queryFn: () => validateTemplate(templateId, scope),
    enabled: templateId !== '',
  })
  const saveRows = useCountryDefaultsMutation((nextRows: TemplateAccount[]) => saveTemplateRows(templateId, nextRows))
  const saveMetadata = useCountryDefaultsMutation((name: string) => updateTemplate(templateId, {
    name, description: templateQuery.data?.description ?? null, standard_ref: templateQuery.data?.standard_ref ?? null,
  }))
  const publish = useCountryDefaultsMutation((fields: PublishFields) => publishTemplate(templateId, {
    standard_ref: fields.standardRef.trim(),
    certified_country_codes: fields.scope.split(',').map((country) => country.trim().toUpperCase()).filter(Boolean),
  }))

  const rows = rowEdits ?? templateQuery.data?.rows ?? []
  const rowCodes = rowCodeSet(rows)
  const updateRow = (id: string, patch: Partial<TemplateAccount>) => {
    setRowEdits((current) => (current ?? rows).map((row) => row.id === id ? { ...row, ...patch } : row))
  }
  const handleSaveRows = () => {
    const errors: Record<string, string> = {}
    rows.forEach((row) => {
      if (row.code.trim() === '') errors[row.id] = t('editor.validation.codeRequired')
      else if (row.name.trim() === '') errors[row.id] = t('editor.validation.nameRequired')
      else if (row.parent_code !== null && row.parent_code !== '' && !rowCodes.has(row.parent_code)) errors[row.id] = t('editor.validation.parentMissing')
    })
    setGridErrors(errors)
    if (Object.keys(errors).length === 0) saveRows.mutate(rows)
  }
  const submitPublish = publishForm.handleSubmit(async (fields) => {
    const result = await publish.mutateAsync(fields)
    setPublishedHash(result.content_hash)
    setShowPublish(false)
  })

  if (templateQuery.isLoading) return <div className={`p-8 ${textColors.tertiary}`}>{t('common.loading')}</div>
  if (templateQuery.data === undefined) return <div className={`p-8 ${textColors.error}`}>{t('editor.loadError')}</div>
  const template = templateQuery.data
  const locked = template.status !== 'draft'

  return (
    <div className="p-8">
      <div className="mx-auto max-w-[96rem] space-y-6">
        <PageHeader title={template.name} breadcrumb={<Link className={`text-sm ${textColors.brand} hover:underline`} to="/admin/country-defaults">{t('editor.back')}</Link>} actions={!locked ? <Button onClick={() => { setShowPublish(true) }}><ShieldCheck className="mr-2 h-4 w-4" />{t('editor.publish')}</Button> : undefined} />

        {publishedHash !== null && <div className={tokens.alert.success}><p>{t('editor.published')}</p><code className="mt-1 block break-all font-mono">{publishedHash}</code></div>}

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
          <section className={`overflow-hidden rounded-xl border ${borderColors.light} ${semanticColorTokens.surface.base}`}>
            <div className={`flex items-center justify-between border-b p-4 ${borderColors.light}`}>
              <div>
                <h2 className={tokens.heading.section}>{t('editor.grid.title')}</h2>
                <p className={`mt-1 text-sm ${textColors.tertiary}`}>{t('editor.grid.subtitle')}</p>
              </div>
              {!locked && <Button variant="secondary" size="sm" onClick={() => { setRowEdits((current) => { const source = current ?? rows; return [...source, blankRow(source.length)] }) }}><Plus className="mr-1 h-4 w-4" />{t('editor.grid.add')}</Button>}
            </div>
            <DataTable className="min-w-[1100px]" aria-label={t('editor.grid.tableLabel')}>
              <thead className={tokens.table.header}><tr>
                {['code', 'name', 'type', 'parent', 'purpose', 'system', 'actions'].map((column) => <th className={`px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide ${textColors.disabled}`} key={column}>{t(`editor.grid.columns.${column}`)}</th>)}
              </tr></thead>
              <tbody className={`divide-y ${borderColors.divideDefault}`}>
                {rows.map((row) => {
                  const protectedTitle = row.protection_source === null ? undefined : t(`protection.${row.protection_source}`)
                  const disabled = locked || row.is_protected
                  return <tr className={row.is_protected ? semanticColorTokens.intent.warning.bgSubtleAlpha : tokens.table.rowHover} key={row.id}>
                    <td className="w-32 px-3 py-2 align-top">
                      <div aria-label={row.is_protected ? t('editor.grid.protectedAria', { code: row.code }) : undefined} title={protectedTitle}>
                        <Input aria-label={t('editor.grid.codeAria', { code: row.code })} disabled={disabled} error={row.id in gridErrors} value={row.code} onChange={(event) => { updateRow(row.id, { code: event.target.value }) }} />
                        {row.is_protected && <span className={`mt-1 flex items-center gap-1 text-xs ${semanticColorTokens.intent.warning.textStrong}`}><LockKeyhole className="h-3 w-3" />{t('editor.grid.protected')}</span>}
                        {row.id in gridErrors && <p className={tokens.helperText.error}>{gridErrors[row.id]}</p>}
                      </div>
                    </td>
                    <td className="min-w-56 px-3 py-2 align-top"><Input disabled={disabled} value={row.name} onChange={(event) => { updateRow(row.id, { name: event.target.value }) }} /></td>
                    <td className="w-40 px-3 py-2 align-top"><Select disabled={disabled} value={row.type} onChange={(event) => { if (isAccountType(event.target.value, template.account_types)) updateRow(row.id, { type: event.target.value }) }}>{template.account_types.map((type) => <option value={type} key={type}>{t(`accountTypes.${type}`)}</option>)}</Select></td>
                    <td className="w-36 px-3 py-2 align-top"><Select disabled={disabled} value={row.parent_code ?? ''} onChange={(event) => { updateRow(row.id, { parent_code: event.target.value || null }) }}><option value="">{t('common.none')}</option>{rows.map((candidate) => candidate.id === row.id ? null : <option value={candidate.code} key={candidate.id}>{candidate.code}</option>)}</Select></td>
                    <td className="min-w-52 px-3 py-2 align-top"><Select disabled={disabled} value={row.system_purpose ?? ''} onChange={(event) => { const purpose = event.target.value; if (purpose === '' || isSystemPurpose(purpose, template.system_account_purposes)) updateRow(row.id, { system_purpose: purpose || null }) }}><option value="">{t('common.none')}</option>{template.system_account_purposes.map((purpose) => <option value={purpose} key={purpose}>{t(`purposes.${purpose}`, { defaultValue: purpose })}</option>)}</Select></td>
                    <td className="px-3 py-4 text-center align-top"><Checkbox aria-label={t('editor.grid.systemAria', { code: row.code })} disabled={disabled} checked={row.is_system} onChange={(event) => { updateRow(row.id, { is_system: event.target.checked }) }} /></td>
                    <td className="px-3 py-3 align-top">{!disabled && <Button variant="ghost" size="sm" aria-label={t('editor.grid.deleteAria', { code: row.code })} onClick={() => { setRowEdits((current) => (current ?? rows).filter((candidate) => candidate.id !== row.id)) }}><Trash2 className="h-4 w-4" /></Button>}</td>
                  </tr>
                })}
              </tbody>
            </DataTable>
            {!locked && <div className={`flex justify-end gap-3 border-t p-4 ${borderColors.light}`}><Button variant="secondary" onClick={() => { saveMetadata.mutate(template.name) }}>{t('editor.saveMetadata')}</Button><Button onClick={handleSaveRows}>{t('editor.saveRows')}</Button></div>}
          </section>

          <aside aria-label={t('validation.title')} className={`${tokens.card.base} self-start xl:sticky xl:top-6`}>
            <h2 className={tokens.heading.section}>{t('validation.title')}</h2>
            <p className={`mt-2 text-sm ${textColors.tertiary}`}>{t('validation.subtitle')}</p>
            <div className={`mt-4 ${validation.data?.valid === true ? tokens.alert.success : tokens.alert.warning}`}>
              {validation.isLoading ? t('validation.checking') : validation.data?.valid === true ? t('validation.valid') : t('validation.invalid')}
            </div>
            <ul className={`mt-4 space-y-2 text-sm ${textColors.secondary}`}>
              {(validation.data?.errors ?? []).map((error) => <li className={`border-l-2 pl-3 ${semanticColorTokens.intent.danger.borderStrong}`} key={error}>{error}</li>)}
            </ul>
          </aside>
        </div>
      </div>

      {showPublish && <dialog aria-labelledby="publish-template-title" className={tokens.modal.backdrop} open>
        <form className={tokens.modal.container} onSubmit={(event) => { void submitPublish(event) }}>
          <div className={tokens.modal.header}><h2 className={tokens.modal.title} id="publish-template-title">{t('publish.title')}</h2><Button variant="ghost" size="sm" aria-label={t('publish.close')} onClick={() => { setShowPublish(false) }} type="button"><X className="h-4 w-4" /></Button></div>
          <p className={`text-sm ${textColors.tertiary}`}>{t('publish.description')}</p>
          <label className={`mt-5 ${tokens.label.base}`} htmlFor="standard-ref">{t('publish.standard')}</label>
          <Input id="standard-ref" {...publishForm.register('standardRef', { required: true })} />
          <label className={`mt-4 ${tokens.label.base}`} htmlFor="certified-scope">{t('publish.scope')}</label>
          <Input id="certified-scope" placeholder={t('publish.scopePlaceholder')} {...publishForm.register('scope', { required: true })} />
          <p className={tokens.helperText.base}>{t('publish.scopeHelp')}</p>
          <div className={tokens.modal.footer}><Button variant="secondary" type="button" onClick={() => { setShowPublish(false) }}>{t('common.cancel')}</Button><Button type="submit" disabled={publish.isPending}>{t('publish.confirm')}</Button></div>
        </form>
      </dialog>}
    </div>
  )
}
