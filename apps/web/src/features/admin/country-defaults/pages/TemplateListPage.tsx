import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Archive, Copy } from 'lucide-react'
import { Button, Select } from '@/components/atoms'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { PageHeader } from '@/components/molecules/PageHeader'
import { borderColors, semanticColorTokens, textColors, tokens } from '@/lib/designTokens'
import { archiveTemplate, cloneTemplate } from '../api/countryDefaultsApi'
import { useCountryDefaultsMutation, useTemplates } from '../hooks/useCountryDefaults'
import type { TemplateDomain, TemplateSummary } from '../types'

export function TemplateListPage() {
  const { t } = useTranslation('adminCountryDefaults')
  const [domain, setDomain] = useState<TemplateDomain>('chart_of_accounts')
  const templates = useTemplates(domain)
  const clone = useCountryDefaultsMutation(
    (template: TemplateSummary) => cloneTemplate(template.id, t('templates.cloneName', { name: template.name })),
  )
  const archive = useCountryDefaultsMutation((template: TemplateSummary) => archiveTemplate(template.id))
  const columns: DataTableColumn<TemplateSummary>[] = [
    {
      key: 'template',
      header: t('templates.columns.template'),
      render: (template) => (
        <div>
          <Link className={`font-medium ${textColors.brand} hover:underline`} to={`/admin/country-defaults/templates/${template.id}`} aria-label={t('templates.edit')}>
            {template.name}
          </Link>
          {template.description !== null && <p className={`mt-1 text-xs ${textColors.tertiary}`}>{template.description}</p>}
        </div>
      ),
    },
    {
      key: 'status',
      header: t('templates.columns.status'),
      render: (template) => <span className={`${tokens.badge.base} ${template.status === 'published' ? tokens.badge.green : template.status === 'archived' ? tokens.badge.gray : tokens.badge.yellow}`}>{t(`status.${template.status}`)}</span>,
    },
    {
      key: 'certification',
      header: t('templates.columns.certification'),
      render: (template) => (
        <div className={`space-y-1 text-sm ${textColors.secondary}`}>
          <p>{template.standard_ref ?? t('common.notCertified')}</p>
          <div className="flex flex-wrap gap-1">
            {(template.certified_country_codes ?? []).map((country) => <span className={`${tokens.badge.base} ${tokens.badge.outline}`} key={country}>{country}</span>)}
          </div>
          <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2 text-xs">
            <dt className={textColors.tertiary}>{t('templates.certification.hash')}</dt>
            <dd className="break-all font-mono">{template.content_hash ?? '—'}</dd>
            <dt className={textColors.tertiary}>{t('templates.certification.version')}</dt>
            <dd>{template.capability_registry_version ?? '—'}</dd>
            <dt className={textColors.tertiary}>{t('templates.certification.by')}</dt>
            <dd className="break-all">{template.certified_by ?? '—'}</dd>
            <dt className={textColors.tertiary}>{t('templates.certification.at')}</dt>
            <dd>{template.published_at ?? '—'}</dd>
          </dl>
        </div>
      ),
    },
    {
      key: 'actions',
      header: t('templates.columns.actions'),
      render: (template) => (
        <div className="flex gap-2">
          <Button size="sm" variant="secondary" onClick={() => { clone.mutate(template) }}><Copy className="mr-1 h-4 w-4" />{t('templates.clone')}</Button>
          <Button size="sm" variant="dangerOutline" disabled={template.status === 'archived'} onClick={() => { archive.mutate(template) }}><Archive className="mr-1 h-4 w-4" />{t('templates.archive')}</Button>
        </div>
      ),
    },
  ]

  return (
    <div className="p-8">
      <div className="mx-auto max-w-7xl space-y-6">
        <PageHeader
          title={t('templates.title')}
          subtitle={t('templates.subtitle')}
          breadcrumb={<p className={`text-xs font-semibold uppercase tracking-[0.18em] ${textColors.brand}`}>{t('eyebrow')}</p>}
          actions={<Link className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`} to="/admin/country-defaults/assignments">{t('navigation.assignments')}</Link>}
        />
        <section className={tokens.card.base}>
          <label className={`block max-w-sm ${tokens.label.base}`} htmlFor="template-domain">{t('common.domain')}</label>
          <Select id="template-domain" value={domain} onChange={(event) => { if (event.target.value === 'chart_of_accounts') setDomain(event.target.value) }}>
            <option value="chart_of_accounts">{t('domains.chart_of_accounts')}</option>
          </Select>
        </section>
        <div className={`overflow-hidden rounded-xl border ${borderColors.light} ${semanticColorTokens.surface.base}`}>
          <DataTable columns={columns} data={templates.data ?? []} keyExtractor={(template) => template.id} isLoading={templates.isLoading} emptyTitle={t('templates.empty')} ariaLabel={t('templates.tableLabel')} />
        </div>
      </div>
    </div>
  )
}
