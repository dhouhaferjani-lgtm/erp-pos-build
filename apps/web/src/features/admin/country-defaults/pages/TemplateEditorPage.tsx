import { useEffect, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { LockKeyhole, Plus, ShieldCheck, Trash2 } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { Button, Checkbox, Input, Select } from '@/components/atoms'
import { DataTable } from '@/components/molecules/DataTable/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { borderColors, semanticColorTokens, textColors, tokens } from '@/lib/designTokens'
import { publishTemplate, saveTemplateRows, validateTemplate } from '../api/countryDefaultsApi'
import { useCountryDefaultsMutation, useTemplate } from '../hooks/useCountryDefaults'
import type { TemplateAccount, TemplateAccountSaveRow } from '../types'
import { countryDefaultsErrorKey, countryDefaultsErrorStatus } from '../lib/apiError'

interface PublishFields {
  standardRef: string
  scope: string
}

const VALIDATION_DEBOUNCE_MS = 300
const COMPLETE_SCOPE_PATTERN = /^(?:\*|[A-Za-z]{2})(?:\s*,\s*(?:\*|[A-Za-z]{2}))*$/

function normalizeScope(scope: string): string {
  return scope.split(',').map((country) => country.trim()).filter(Boolean).join(',')
}

function isAccountType(value: string, choices: readonly string[]): value is TemplateAccount['type'] {
  return choices.includes(value)
}

function isSystemPurpose(value: string, choices: readonly string[]): value is NonNullable<TemplateAccount['system_purpose']> {
  return choices.includes(value)
}

function blankRow(sortOrder: number): TemplateAccount {
  return {
    id: `new:${crypto.randomUUID()}`, code: '', name: '', type: 'asset', parent_code: null,
    system_purpose: null, is_system: false, sort_order: sortOrder,
    is_protected: false, protection_source: null,
  }
}

function nextSortOrder(rows: readonly TemplateAccount[]): number {
  return Math.max(-1, ...rows.map((row) => row.sort_order)) + 1
}

function rowForSave(row: TemplateAccount): TemplateAccountSaveRow {
  const { id, is_protected: _protected, protection_source: _source, ...payload } = row
  return id.startsWith('new:') ? payload : { ...payload, id }
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
  const [validationScope, setValidationScope] = useState<string | null>('')
  useEffect(() => {
    const trimmedScope = scope.trim()
    if (trimmedScope === '') {
      setValidationScope('')
      return
    }
    if (!COMPLETE_SCOPE_PATTERN.test(trimmedScope)) {
      setValidationScope(null)
      return
    }
    const timeout = setTimeout(() => {
      setValidationScope(normalizeScope(trimmedScope))
    }, VALIDATION_DEBOUNCE_MS)
    return () => { clearTimeout(timeout) }
  }, [scope])
  const validation = useQuery({
    queryKey: ['admin', 'country-defaults', 'templates', templateId, 'validation', validationScope],
    queryFn: () => validateTemplate(templateId, validationScope ?? ''),
    enabled: templateId !== '' && validationScope !== null,
    retry: false,
  })
  const saveRows = useCountryDefaultsMutation((nextRows: TemplateAccountSaveRow[]) => saveTemplateRows(templateId, nextRows))
  const publish = useCountryDefaultsMutation((fields: PublishFields) => publishTemplate(templateId, {
    standard_ref: fields.standardRef.trim(),
    certified_country_codes: fields.scope.split(',').map((country) => country.trim().toUpperCase()).filter(Boolean),
  }))

  const rows = rowEdits ?? templateQuery.data?.rows ?? []
  const rowCodes = rowCodeSet(rows)
  const updateRow = (id: string, patch: Partial<TemplateAccount>) => {
    setRowEdits((current) => (current ?? rows).map((row) => row.id === id ? { ...row, ...patch } : row))
    setGridErrors((current) => {
      if (!(id in current)) return current
      const { [id]: _removed, ...remaining } = current
      return remaining
    })
  }
  const handleSaveRows = () => {
    const errors: Record<string, string> = {}
    rows.forEach((row) => {
      if (row.code.trim() === '') errors[row.id] = t('editor.validation.codeRequired')
      else if (row.name.trim() === '') errors[row.id] = t('editor.validation.nameRequired')
      else if (row.parent_code !== null && row.parent_code !== '' && !rowCodes.has(row.parent_code)) errors[row.id] = t('editor.validation.parentMissing')
    })
    setGridErrors(errors)
    if (Object.keys(errors).length === 0) {
      saveRows.mutate(rows.map(rowForSave), {
        onSuccess: () => { setRowEdits(null) },
      })
    }
  }
  const deleteRow = (id: string) => {
    setRowEdits((current) => (current ?? rows).filter((candidate) => candidate.id !== id))
    setGridErrors((current) => {
      if (!(id in current)) return current
      const { [id]: _removed, ...remaining } = current
      return remaining
    })
  }
  const submitPublish = publishForm.handleSubmit((fields) => {
    publish.mutate(fields, {
      onSuccess: (result) => {
        setPublishedHash(result.content_hash)
        setShowPublish(false)
      },
    })
  })

  if (templateQuery.isLoading) return <div className={`p-8 ${textColors.tertiary}`}>{t('common.loading')}</div>
  if (templateQuery.data === undefined) return <div role="alert" className={`p-8 ${textColors.error}`}>{t('editor.loadError')}</div>
  const template = templateQuery.data
  const locked = template.status !== 'draft'

  return (
    <div className="p-8">
      <div className="mx-auto max-w-[96rem] space-y-6">
        <PageHeader title={template.name} breadcrumb={<Link className={`text-sm ${textColors.brand} hover:underline`} to="/admin/country-defaults">{t('editor.back')}</Link>} actions={!locked ? <Button onClick={() => { publish.reset(); setShowPublish(true) }}><ShieldCheck className="mr-2 h-4 w-4" />{t('editor.publish')}</Button> : undefined} />

        {publishedHash !== null && <div className={tokens.alert.success}><p>{t('editor.published')}</p><code className="mt-1 block break-all font-mono">{publishedHash}</code></div>}
        {templateQuery.isError && <div role="alert" className={tokens.alert.error}>{t(countryDefaultsErrorKey(templateQuery.error))}</div>}

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
          <section className={`overflow-hidden rounded-xl border ${borderColors.light} ${semanticColorTokens.surface.base}`}>
            <div className={`flex items-center justify-between border-b p-4 ${borderColors.light}`}>
              <div>
                <h2 className={tokens.heading.section}>{t('editor.grid.title')}</h2>
                <p className={`mt-1 text-sm ${textColors.tertiary}`}>{t('editor.grid.subtitle')}</p>
              </div>
              {!locked && <Button variant="secondary" size="sm" onClick={() => { setRowEdits((current) => { const source = current ?? rows; return [...source, blankRow(nextSortOrder(source))] }) }}><Plus className="mr-1 h-4 w-4" />{t('editor.grid.add')}</Button>}
            </div>
            <div className="overflow-x-auto">
            <DataTable className="min-w-[1100px]" aria-label={t('editor.grid.tableLabel')}>
              <thead className={tokens.table.header}><tr>
                {['code', 'name', 'type', 'parent', 'purpose', 'system', 'actions'].map((column) => <th className={`px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide ${textColors.disabled}`} key={column}>{t(`editor.grid.columns.${column}`)}</th>)}
              </tr></thead>
              <tbody className={`divide-y ${borderColors.divideDefault}`}>
                {rows.map((row, rowIndex) => {
                  const protectedTitle = row.protection_source === null ? undefined : t(`protection.${row.protection_source}`, { defaultValue: t('protection.unknown') })
                  const disabled = locked || row.is_protected
                  const rowIdentity = row.code.trim() || t('editor.grid.newRowIdentity', { number: rowIndex + 1 })
                  return <tr className={row.is_protected ? semanticColorTokens.intent.warning.bgSubtleAlpha : tokens.table.rowHover} key={row.id}>
                    <td className="w-32 px-3 py-2 align-top">
                      <div>
                        <Input aria-describedby={row.is_protected ? `protected-${row.id}` : undefined} aria-label={t('editor.grid.codeAria', { code: rowIdentity })} disabled={disabled} error={row.id in gridErrors} value={row.code} onChange={(event) => { updateRow(row.id, { code: event.target.value }) }} />
                        {row.is_protected && <span className={`mt-1 flex items-center gap-1 text-xs ${semanticColorTokens.intent.warning.textStrong}`}><LockKeyhole className="h-3 w-3" />{t('editor.grid.protected')}</span>}
                        {row.is_protected && protectedTitle !== undefined && <p className={`mt-1 text-xs ${textColors.tertiary}`} id={`protected-${row.id}`}>{protectedTitle}</p>}
                        {row.id in gridErrors && <p className={tokens.helperText.error}>{gridErrors[row.id]}</p>}
                      </div>
                    </td>
                    <td className="min-w-56 px-3 py-2 align-top"><Input disabled={disabled} value={row.name} onChange={(event) => { updateRow(row.id, { name: event.target.value }) }} /></td>
                    <td className="w-40 px-3 py-2 align-top"><Select disabled={disabled} value={row.type} onChange={(event) => { if (isAccountType(event.target.value, template.account_types)) updateRow(row.id, { type: event.target.value }) }}>{template.account_types.map((type) => <option value={type} key={type}>{t(`accountTypes.${type}`)}</option>)}</Select></td>
                    <td className="w-36 px-3 py-2 align-top"><Select disabled={disabled} value={row.parent_code ?? ''} onChange={(event) => { updateRow(row.id, { parent_code: event.target.value || null }) }}><option value="">{t('common.none')}</option>{rows.map((candidate) => candidate.id === row.id || candidate.code.trim() === '' ? null : <option value={candidate.code} key={candidate.id}>{candidate.code}</option>)}</Select></td>
                    <td className="min-w-52 px-3 py-2 align-top"><Select disabled={disabled} value={row.system_purpose ?? ''} onChange={(event) => { const purpose = event.target.value; if (purpose === '' || isSystemPurpose(purpose, template.system_account_purposes)) updateRow(row.id, { system_purpose: purpose || null }) }}><option value="">{t('common.none')}</option>{template.system_account_purposes.map((purpose) => <option value={purpose} key={purpose}>{t(`purposes.${purpose}`)}</option>)}</Select></td>
                    <td className="px-3 py-4 text-center align-top"><Checkbox aria-label={t('editor.grid.systemAria', { code: rowIdentity })} disabled={disabled} checked={row.is_system} onChange={(event) => { updateRow(row.id, { is_system: event.target.checked }) }} /></td>
                    <td className="px-3 py-3 align-top">{!disabled && <Button variant="ghost" size="sm" aria-label={t('editor.grid.deleteAria', { code: rowIdentity })} onClick={() => { deleteRow(row.id) }}><Trash2 className="h-4 w-4" /></Button>}</td>
                  </tr>
                })}
              </tbody>
            </DataTable>
            </div>
            {saveRows.error !== null && <div role="alert" className={`m-4 ${tokens.alert.error}`}>{t(countryDefaultsErrorKey(saveRows.error))}</div>}
            {!locked && <div className={`flex justify-end border-t p-4 ${borderColors.light}`}><Button onClick={handleSaveRows}>{t('editor.saveRows')}</Button></div>}
          </section>

          <aside aria-label={t('validation.title')} className={`${tokens.card.base} self-start xl:sticky xl:top-6`}>
            <h2 className={tokens.heading.section}>{t('validation.title')}</h2>
            <p className={`mt-2 text-sm ${textColors.tertiary}`}>{t('validation.subtitle')}</p>
            {validationScope === null && <div className={`mt-4 ${tokens.alert.info}`}>{t('validation.incompleteScope')}</div>}
            {validation.isLoading && <div className={`mt-4 ${tokens.alert.info}`}>{t('validation.checking')}</div>}
            {validation.isError && <div role="alert" className={`mt-4 ${tokens.alert.error}`}>{t(countryDefaultsErrorStatus(validation.error) === 422 ? 'validation.scopeRejected' : 'validation.unavailable')}</div>}
            {validation.data !== undefined && <div className={`mt-4 ${validation.data.valid ? tokens.alert.success : tokens.alert.warning}`}>
              {validation.data.valid ? t('validation.valid') : t('validation.invalid')}
            </div>}
            <ul className={`mt-4 space-y-2 text-sm ${textColors.secondary}`}>
              {(validation.data?.errors ?? []).map((error) => {
                const parameters = { ...error.parameters }
                const purpose = parameters['purpose']
                if (Object.hasOwn(parameters, 'purpose') && isSystemPurpose(purpose, template.system_account_purposes)) {
                  parameters['purpose'] = t(`purposes.${purpose}`)
                }
                return <li className={`border-l-2 pl-3 ${semanticColorTokens.intent.danger.borderStrong}`} key={`${error.code}:${JSON.stringify(error.parameters)}`}>{t(`validation.errors.${error.code}`, { ...parameters, defaultValue: t('validation.errors.validation_failed') })}</li>
              })}
            </ul>
          </aside>
        </div>
      </div>

      <Modal isOpen={showPublish} onClose={() => { setShowPublish(false) }} title={t('publish.title')}>
        <form onSubmit={(event) => { void submitPublish(event) }}>
          <ModalContent>
          <p className={`text-sm ${textColors.tertiary}`}>{t('publish.description')}</p>
          <label className={`mt-5 ${tokens.label.base}`} htmlFor="standard-ref">{t('publish.standard')}</label>
          <Input id="standard-ref" {...publishForm.register('standardRef', { required: true })} />
          <label className={`mt-4 ${tokens.label.base}`} htmlFor="certified-scope">{t('publish.scope')}</label>
          <Input id="certified-scope" placeholder={t('publish.scopePlaceholder')} {...publishForm.register('scope', { required: true })} />
          <p className={tokens.helperText.base}>{t('publish.scopeHelp')}</p>
          {publish.error !== null && <div role="alert" className={tokens.alert.error}>{t(countryDefaultsErrorKey(publish.error))}</div>}
          </ModalContent>
          <ModalFooter><Button variant="secondary" type="button" onClick={() => { setShowPublish(false) }}>{t('common.cancel')}</Button><Button type="submit" disabled={publish.isPending}>{t('publish.confirm')}</Button></ModalFooter>
        </form>
      </Modal>
    </div>
  )
}
