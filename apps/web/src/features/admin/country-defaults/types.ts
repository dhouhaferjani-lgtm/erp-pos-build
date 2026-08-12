export type TemplateSummary = App.Modules.CountryDefaults.Application.DTOs.TemplateSummaryData
export type CountryDefaultTemplate = App.Modules.CountryDefaults.Application.DTOs.TemplateData
export type TemplateAccount = App.Modules.CountryDefaults.Application.DTOs.TemplateAccountData
export type AssignmentMatrixRow = App.Modules.CountryDefaults.Application.DTOs.AssignmentMatrixRowData
export type TemplateDomain = App.Modules.CountryDefaults.Domain.Enums.TemplateDomain
export type TemplateStatus = App.Modules.CountryDefaults.Domain.Enums.TemplateStatus
export type AccountType = App.Modules.Accounting.Domain.Enums.AccountType
export type SystemAccountPurpose = App.Modules.Accounting.Domain.Enums.SystemAccountPurpose

export interface ValidationReport {
  valid: boolean
  scope: string[]
  errors: string[]
}

export interface AssignmentMatrix {
  data: AssignmentMatrixRow[]
  meta: { catalog_version: string }
}
