export type TemplateSummary = App.Modules.CountryDefaults.Application.DTOs.TemplateSummaryData
export type CountryDefaultTemplate = App.Modules.CountryDefaults.Application.DTOs.TemplateData
export type TemplateAccount = App.Modules.CountryDefaults.Application.DTOs.TemplateAccountData
export type AssignmentMatrixRow = App.Modules.CountryDefaults.Application.DTOs.AssignmentMatrixRowData
export type TemplateDomain = App.Modules.CountryDefaults.Domain.Enums.TemplateDomain
export type TemplateStatus = App.Modules.CountryDefaults.Domain.Enums.TemplateStatus
export type AccountType = App.Modules.Accounting.Domain.Enums.AccountType
export type SystemAccountPurpose = App.Modules.Accounting.Domain.Enums.SystemAccountPurpose
export type ValidationReport = App.Modules.CountryDefaults.Application.DTOs.TemplateValidationReportData
export type AssignmentMatrix = App.Modules.CountryDefaults.Application.DTOs.AssignmentMatrixData
export type TemplateAccountSaveRow = Omit<TemplateAccount, 'id' | 'is_protected' | 'protection_source'> & {
  id?: string
}
