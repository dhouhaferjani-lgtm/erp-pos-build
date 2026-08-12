import { adminApiGet, adminApiGetPaginated, adminApiPost, adminApiPut } from '@/features/admin/lib/adminApi'
import type {
  AssignmentMatrix,
  CountryDefaultTemplate,
  TemplateAccount,
  TemplateDomain,
  TemplateSummary,
  ValidationReport,
} from '../types'

const root = '/admin/country-defaults'

export function listTemplates(domain: TemplateDomain): Promise<TemplateSummary[]> {
  return adminApiGet(`${root}/templates`, { domain })
}

export function getTemplate(id: string): Promise<CountryDefaultTemplate> {
  return adminApiGet(`${root}/templates/${id}`)
}

export function cloneTemplate(id: string, name: string): Promise<CountryDefaultTemplate> {
  return adminApiPost(`${root}/templates/${id}/clone`, { name })
}

export function archiveTemplate(id: string): Promise<CountryDefaultTemplate> {
  return adminApiPost(`${root}/templates/${id}/archive`)
}

export function updateTemplate(
  id: string,
  payload: { name: string; description: string | null; standard_ref: string | null },
): Promise<CountryDefaultTemplate> {
  return adminApiPut(`${root}/templates/${id}`, payload)
}

export function saveTemplateRows(id: string, rows: TemplateAccount[]): Promise<CountryDefaultTemplate> {
  return adminApiPut(`${root}/templates/${id}/rows`, {
    rows: rows.map(({ is_protected: _locked, protection_source: _source, ...row }) => row),
  })
}

export function validateTemplate(id: string, scope: string): Promise<ValidationReport> {
  return adminApiGet(`${root}/templates/${id}/validation`, { scope })
}

export function publishTemplate(
  id: string,
  payload: { standard_ref: string; certified_country_codes: string[] },
): Promise<CountryDefaultTemplate> {
  return adminApiPost(`${root}/templates/${id}/publish`, payload)
}

export function listAssignments(domain: TemplateDomain): Promise<AssignmentMatrix> {
  return adminApiGetPaginated(`${root}/assignments?domain=${encodeURIComponent(domain)}`)
}

export function assignTemplate(
  countryCode: string,
  payload: { domain: TemplateDomain; template_id: string },
): Promise<App.Modules.CountryDefaults.Application.DTOs.CountryTemplateAssignmentData> {
  return adminApiPut(`${root}/assignments/${encodeURIComponent(countryCode)}`, payload)
}
