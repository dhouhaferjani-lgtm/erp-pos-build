import { useMemo, useState } from 'react'
import { AlertTriangle, ArrowLeft, ArrowRight, CheckCircle2, FileSpreadsheet, Plus } from 'lucide-react'
import { useTranslation } from 'react-i18next'

import { Button, Checkbox, Input, MoneyInput, Select } from '@/components/atoms'
import { DataTable, type DataTableColumn } from '@/components/molecules/DataTable/DataTable'
import { getErrorMessage } from '@/lib/api'
import { semanticColorTokens, tokens, textColors } from '@/lib/designTokens'
import { formatCurrency } from '@/lib/format'
import { cn } from '@/lib/utils'

import {
  createStatementProfile,
  type StatementConfirmInput,
  type StatementDecimalFormat,
  type StatementDirectionConvention,
  type StatementParserKey,
  type StatementPreview,
  type StatementPreviewInput,
  type StatementProfile,
  type StatementProfileInput,
  uploadStatementPreview,
  confirmBankStatement,
} from './api'

interface WizardRepository { id: string; name: string; currency: string }

interface StatementUploadWizardProps {
  repositories: WizardRepository[]
  profiles: StatementProfile[]
  onPreview?: (input: StatementPreviewInput) => Promise<StatementPreview>
  onConfirm?: (input: StatementConfirmInput) => Promise<{ statementId: string }>
  onCreateProfile?: (input: StatementProfileInput) => Promise<StatementProfile>
  onProfileCreated?: (profile: StatementProfile) => Promise<void> | void
  onImported: (statementId: string) => void
}

type Step = 'repository' | 'file' | 'profile' | 'preview'

const mappingFields = ['value_date', 'booking_date', 'amount', 'debit', 'credit', 'reference', 'bank_transaction_id', 'label', 'counterparty_hint'] as const
const wizardSteps: Step[] = ['repository', 'file', 'profile', 'preview']

export function StatementUploadWizard({
  repositories,
  profiles,
  onPreview = uploadStatementPreview,
  onConfirm = confirmBankStatement,
  onCreateProfile = createStatementProfile,
  onProfileCreated,
  onImported,
}: StatementUploadWizardProps) {
  const { t } = useTranslation('treasury')
  const [step, setStep] = useState<Step>('repository')
  const [repositoryId, setRepositoryId] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [profileId, setProfileId] = useState('')
  const [createdProfiles, setCreatedProfiles] = useState<StatementProfile[]>([])
  const [showProfileForm, setShowProfileForm] = useState(false)
  const [profileName, setProfileName] = useState('')
  const [parserKey, setParserKey] = useState<StatementParserKey>('csv')
  const [directionConvention, setDirectionConvention] = useState<StatementDirectionConvention>('signed_amount')
  const [decimalFormat, setDecimalFormat] = useState<StatementDecimalFormat>('comma_decimal')
  const [dateFormat, setDateFormat] = useState('d/m/Y')
  const [headerRows, setHeaderRows] = useState('0')
  const [mapping, setMapping] = useState<Record<string, string | null>>({ value_date: '', amount: '', label: '' })
  const [preview, setPreview] = useState<StatementPreview | null>(null)
  const [periodStart, setPeriodStart] = useState('')
  const [periodEnd, setPeriodEnd] = useState('')
  const [openingBalance, setOpeningBalance] = useState('0.000')
  const [closingBalance, setClosingBalance] = useState('0.000')
  const [acknowledgeEmpty, setAcknowledgeEmpty] = useState(false)
  const [pending, setPending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const repository = repositories.find((item) => item.id === repositoryId)
  const availableProfiles = useMemo(
    () => [...profiles, ...createdProfiles].filter((item) => item.payment_repository_id === repositoryId && item.is_active),
    [createdProfiles, profiles, repositoryId],
  )

  function changeRepository(nextRepositoryId: string) {
    if (nextRepositoryId === repositoryId) return
    setRepositoryId(nextRepositoryId)
    setFile(null)
    setProfileId('')
    setPreview(null)
    setPeriodStart('')
    setPeriodEnd('')
    setOpeningBalance('0.000')
    setClosingBalance('0.000')
    setAcknowledgeEmpty(false)
    setMapping({ value_date: '', amount: '', label: '' })
    setShowProfileForm(false)
    setError(null)
  }

  async function createProfile() {
    setPending(true)
    setError(null)
    try {
      const created = await onCreateProfile({
        payment_repository_id: repositoryId,
        name: profileName,
        is_active: true,
        parser_key: parserKey,
        column_map: mapping,
        date_format: dateFormat,
        decimal_format: decimalFormat,
        direction_convention: directionConvention,
        header_rows: Math.min(100, Math.max(0, Number.parseInt(headerRows, 10) || 0)),
        matching_window_days: 5,
      })
      setCreatedProfiles((current) => [...current, created])
      await onProfileCreated?.(created)
      setProfileId(created.id)
      setShowProfileForm(false)
    } catch (caught) {
      setError(getErrorMessage(caught))
    } finally {
      setPending(false)
    }
  }

  async function requestPreview() {
    if (!file) return
    setPending(true)
    setError(null)
    try {
      const nextPreview = await onPreview({ repositoryId, profileId, file })
      setPreview(nextPreview)
      setOpeningBalance(nextPreview.detected_opening ?? '0.000')
      setClosingBalance(nextPreview.detected_closing ?? '0.000')
      setStep('preview')
    } catch (caught) {
      setError(getErrorMessage(caught))
    } finally {
      setPending(false)
    }
  }

  async function confirm() {
    if (!preview || !repository) return
    setPending(true)
    setError(null)
    try {
      const result = await onConfirm({
        previewToken: preview.preview_token,
        repositoryId,
        currency: repository.currency,
        periodStart,
        periodEnd,
        openingBalance,
        closingBalance,
        acknowledgeEmpty,
      })
      onImported(result.statementId)
    } catch (caught) {
      setError(getErrorMessage(caught))
    } finally {
      setPending(false)
    }
  }

  const currentStep = wizardSteps.indexOf(step)
  const confirmBlocked = !preview || !periodStart || !periodEnd || (preview.accepted_line_count === 0 && !acknowledgeEmpty)
  const previewColumns: DataTableColumn<StatementPreview['preview_lines'][number]>[] = [
    { key: 'date', header: t('statements.upload.date'), render: (line) => line.value_date },
    { key: 'label', header: t('statements.upload.label'), render: (line) => line.label },
    {
      key: 'amount',
      header: t('statements.upload.amount'),
      numeric: true,
      render: (line) => <span>{line.direction === 'out' ? '−' : '+'}{formatCurrency(line.amount, { currency: repository?.currency ?? 'TND' })}</span>,
    },
  ]

  return (
    <div className="space-y-5">
      <ol className="grid grid-cols-4 gap-2" aria-label={t('statements.upload.progress')}>
        {wizardSteps.map((item, index) => (
          <li key={item} aria-current={item === step ? 'step' : undefined} className={cn('border-t-2 pt-2 text-xs font-medium', index <= currentStep ? semanticColorTokens.intent.primary.border : semanticColorTokens.border.subtle, index <= currentStep ? textColors.brand : textColors.tertiary)}>
            {t(`statements.upload.steps.${item}`)}
          </li>
        ))}
      </ol>

      {error ? <div className={cn(tokens.alert.base, tokens.alert.error)}>{error}</div> : null}

      {step === 'repository' ? (
        <section className="space-y-4">
          <label className={tokens.label.base} htmlFor="statement-repository">{t('statements.upload.repository')}</label>
          <Select id="statement-repository" value={repositoryId} onChange={(event) => changeRepository(event.target.value)}>
            <option value="">{t('statements.upload.selectRepository')}</option>
            {repositories.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.currency}</option>)}
          </Select>
          <div className="flex justify-end"><Button disabled={!repositoryId} onClick={() => setStep('file')}>{t('statements.upload.next')}<ArrowRight className="ms-2 h-4 w-4" /></Button></div>
        </section>
      ) : null}

      {step === 'file' ? (
        <section className="space-y-4">
          <label className={tokens.label.base} htmlFor="statement-file">{t('statements.upload.file')}</label>
          <Input id="statement-file" type="file" accept=".csv,.xlsx" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
          <p className={tokens.helperText.base}>{t('statements.upload.fileHelp')}</p>
          <WizardNav back={() => setStep('repository')} next={() => setStep('profile')} nextDisabled={!file} t={t} />
        </section>
      ) : null}

      {step === 'profile' ? (
        <section className="space-y-4">
          <div className="flex items-end gap-3">
            <label className={cn(tokens.label.base, 'grow')} htmlFor="statement-profile">
              {t('statements.upload.profile')}
              <Select id="statement-profile" value={profileId} onChange={(event) => setProfileId(event.target.value)}>
                <option value="">{t('statements.upload.selectProfile')}</option>
                {availableProfiles.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </Select>
            </label>
            <Button variant="secondary" onClick={() => setShowProfileForm((current) => !current)}><Plus className="me-2 h-4 w-4" />{t('statements.upload.newProfile')}</Button>
          </div>
          {showProfileForm ? (
            <div className={cn('space-y-4 rounded-lg border p-4', semanticColorTokens.border.subtle, semanticColorTokens.surface.pageAlpha)}>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className={tokens.label.base}>{t('statements.profile.name')}<Input value={profileName} onChange={(event) => setProfileName(event.target.value)} /></label>
                <label className={tokens.label.base}>{t('statements.profile.parser')}<Select value={parserKey} onChange={(event) => setParserKey(event.target.value as StatementParserKey)}><option value="csv">CSV</option><option value="xlsx">XLSX</option></Select></label>
                <label className={tokens.label.base}>{t('statements.profile.direction')}<Select value={directionConvention} onChange={(event) => setDirectionConvention(event.target.value as StatementDirectionConvention)}><option value="signed_amount">{t('statements.profile.signed')}</option><option value="debit_credit_columns">{t('statements.profile.debitCredit')}</option></Select></label>
                <label className={tokens.label.base}>{t('statements.profile.decimal')}<Select value={decimalFormat} onChange={(event) => setDecimalFormat(event.target.value as StatementDecimalFormat)}><option value="comma_decimal">1 234,56</option><option value="dot_decimal">1,234.56</option><option value="comma">1234,56</option><option value="dot">1234.56</option></Select></label>
                <label className={tokens.label.base}>{t('statements.profile.dateFormat')}<Input value={dateFormat} onChange={(event) => setDateFormat(event.target.value)} /></label>
                <label className={tokens.label.base}>{t('statements.profile.headerRows')}<Input type="number" min="0" max="100" value={headerRows} onChange={(event) => setHeaderRows(event.target.value)} /></label>
              </div>
              <div className="grid gap-3 sm:grid-cols-3">
                {mappingFields.map((field) => {
                  const hidden = directionConvention === 'signed_amount'
                    ? field === 'debit' || field === 'credit'
                    : field === 'amount'
                  return hidden ? null : <label key={field} className={tokens.label.base}>{t(`statements.profile.fields.${field}`)}<Input value={mapping[field] ?? ''} onChange={(event) => setMapping((current) => ({ ...current, [field]: event.target.value || null }))} /></label>
                })}
              </div>
              <div className="flex justify-end"><Button size="sm" disabled={pending || !profileName || !mapping['value_date'] || (directionConvention === 'signed_amount' ? !mapping['amount'] : !mapping['debit'] || !mapping['credit'])} onClick={() => void createProfile()}>{t('statements.profile.save')}</Button></div>
            </div>
          ) : null}
          <div className="flex items-center justify-between">
            <Button variant="secondary" onClick={() => setStep('file')}><ArrowLeft className="me-2 h-4 w-4" />{t('statements.upload.back')}</Button>
            <Button disabled={!profileId || pending} onClick={() => void requestPreview()}><FileSpreadsheet className="me-2 h-4 w-4" />{pending ? t('statements.upload.previewing') : t('statements.upload.preview')}</Button>
          </div>
        </section>
      ) : null}

      {step === 'preview' && preview ? (
        <section className="space-y-5">
          <div className="grid gap-3 sm:grid-cols-4">
            <ReportCard label={t('statements.upload.report.accepted')} value={preview.accepted_line_count} tone="success" />
            <ReportCard label={t('statements.upload.report.duplicates')} value={preview.duplicate_fingerprint_count} tone="neutral" />
            <ReportCard label={t('statements.upload.report.droppedZero')} value={preview.dropped_zero_amount_rows} tone="neutral" />
            <ReportCard label={t('statements.upload.report.unparseable')} value={preview.unparseable_rows.length} tone={preview.unparseable_rows.length ? 'danger' : 'success'} />
          </div>
          {preview.unparseable_rows.length ? <div className={cn(tokens.alert.base, tokens.alert.warning)}><AlertTriangle className="h-4 w-4" /><ul>{preview.unparseable_rows.map((row) => <li key={`${String(row.row)}-${row.reason}`}>{t('statements.upload.row')} {row.row}: {row.reason}</li>)}</ul></div> : null}
          <div className="overflow-x-auto rounded-lg border">
            <DataTable columns={previewColumns} data={preview.preview_lines} keyExtractor={(line) => line.line_number} ariaLabel={t('statements.upload.preview')} className="min-w-full text-sm" />
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <label className={tokens.label.base}>{t('statements.upload.periodStart')}<Input type="date" value={periodStart} onChange={(event) => setPeriodStart(event.target.value)} /></label>
            <label className={tokens.label.base}>{t('statements.upload.periodEnd')}<Input type="date" value={periodEnd} onChange={(event) => setPeriodEnd(event.target.value)} /></label>
            <label className={tokens.label.base}>{t('statements.upload.openingBalance')}<MoneyInput currency={repository?.currency ?? 'TND'} min="-999999999999999999" value={openingBalance} onChange={setOpeningBalance} /></label>
            <label className={tokens.label.base}>{t('statements.upload.closingBalance')}<MoneyInput currency={repository?.currency ?? 'TND'} min="-999999999999999999" value={closingBalance} onChange={setClosingBalance} /></label>
          </div>
          {preview.accepted_line_count === 0 ? <fieldset aria-label={t('statements.upload.emptyGate')} className={cn('rounded-lg border p-4', semanticColorTokens.intent.caution.border, semanticColorTokens.intent.caution.bgSubtle)}><label className="flex items-start gap-3 text-sm"><Checkbox checked={acknowledgeEmpty} onChange={(event) => setAcknowledgeEmpty(event.target.checked)} /><span>{t('statements.upload.acknowledgeEmpty')}</span></label></fieldset> : null}
          <div className="flex items-center justify-between"><Button variant="secondary" onClick={() => setStep('profile')}><ArrowLeft className="me-2 h-4 w-4" />{t('statements.upload.back')}</Button><Button disabled={confirmBlocked || pending} onClick={() => void confirm()}><CheckCircle2 className="me-2 h-4 w-4" />{pending ? t('statements.upload.importing') : t('statements.upload.confirm')}</Button></div>
        </section>
      ) : null}
    </div>
  )
}

function WizardNav({ back, next, nextDisabled, t }: { back: () => void; next: () => void; nextDisabled: boolean; t: (key: string) => string }) {
  return <div className="flex items-center justify-between"><Button variant="secondary" onClick={back}><ArrowLeft className="me-2 h-4 w-4" />{t('statements.upload.back')}</Button><Button disabled={nextDisabled} onClick={next}>{t('statements.upload.next')}<ArrowRight className="ms-2 h-4 w-4" /></Button></div>
}

function ReportCard({ label, value, tone }: { label: string; value: number; tone: 'success' | 'danger' | 'neutral' }) {
  const toneClass = tone === 'success' ? semanticColorTokens.intent.success.text : tone === 'danger' ? semanticColorTokens.intent.danger.text : textColors.secondary
  return <div className={cn('rounded-lg border p-3', semanticColorTokens.border.subtle, semanticColorTokens.surface.base)}><p className={cn('text-2xl font-semibold tabular-nums', toneClass)}>{value}</p><p className={cn('mt-1 text-xs', textColors.tertiary)}>{label}</p></div>
}
