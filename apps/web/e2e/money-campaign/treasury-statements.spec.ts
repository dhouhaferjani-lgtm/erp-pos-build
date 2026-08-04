/**
 * MONEY TEST CAMPAIGN — wave W-5b — §E.4 `TRE` bank-statement IMPORT
 * (MTP-TRE-36..40, plan §B.5 row 67, ruling C-2).
 *
 * Live local stack (web :5173 -> api :8010, tenant `demo-pharmacy-tn`), real login,
 * real backend, no mocks. Money is compared as EXACT decimal strings (integer
 * millime arithmetic from `treasury-support.ts`) — never a float.
 *
 * FIXTURE: reuses `statement-support.ts` per ruling C-2 — `discoverOrProvisionRepository`
 * (which self-provisions a fresh GL-linked bank repository once every existing one
 * carries an open statement), `createParserProfile`, `uploadStatementPreview` /
 * `uploadStatementPreviewRaw`, `confirmStatement`. It deliberately does NOT call
 * `buildReconciliationFixture()`: that builder authors a Tier-4 candidate by creating
 * a DEDICATED POS TERMINAL per build, which pollutes the claim list and threatens the
 * launch's 1-active-terminal enable preflight
 * (`docs/superpowers/tickets/2026-08-02-w2-wave-minor-findings.md`). Import-only cases
 * need no match candidates at all.
 *
 * `createParserProfile` gained an additive `overrides` argument for MTP-TRE-39/40
 * (debit/credit columns, European decimals); `uploadStatementPreviewRaw` was added so
 * these cases can read the whole classification envelope and assert a refusal status.
 * Both are additive — the pre-existing C-2 behaviour is byte-identical.
 */
import { test, expect, type APIRequestContext } from '@playwright/test'
import { login, get, addMoney, TODAY, type Session } from './treasury-support'
import {
  confirmStatement,
  createParserProfile,
  deactivateRepository,
  discoverOrProvisionRepository,
  retireParserProfile,
  uploadStatementPreview,
  uploadStatementPreviewRaw,
  uniq,
  type BankRepository,
} from './statement-support'

let owner: Session

/** The parser profiles below all use `date_format: 'd/m/Y'`. */
const DISPLAY_DATE = TODAY.split('-').reverse().join('/')

interface PreviewEnvelope {
  preview_token: string
  accepted_line_count: number
  duplicate_fingerprint_count: number
  dropped_zero_amount_rows: number
  unparseable_rows: Array<{ row: number; reason: string }>
  preview_lines: Array<{
    line_number: number
    value_date: string
    direction: string
    amount: string
    reference: string | null
    bank_transaction_id: string | null
    label: string
  }>
}

function signedCsv(rows: Array<{ amount: string; reference: string; label: string }>): string {
  return [
    'Date,Amount,Reference,Transaction ID,Label',
    ...rows.map((row) => `${DISPLAY_DATE},${row.amount},${row.reference},TX-${row.reference},${row.label}`),
    '',
  ].join('\n')
}

/** Signed sum of a signed-amount CSV's rows, as an exact decimal string. */
function signedSum(amounts: string[]): string {
  return amounts.reduce((total, amount) => addMoney(total, amount), '0.000')
}

async function repositoryBalance(request: APIRequestContext, repositoryId: string): Promise<string> {
  const res = await get(request, owner, `/payment-repositories/${repositoryId}`)
  expect(res.ok).toBeTruthy()
  return String(res.data.balance)
}

/**
 * Fixture retirement (fix round 1, I-2a/I-2b) — profiles via DELETE, falling
 * back to `is_active:false` when a statement references them (the path the API
 * itself prescribes); self-provisioned `C2-STMT-*` repositories via
 * `is_active:false` (no DELETE route exists). A SEEDED repository that
 * discovery happened to pick is never deactivated.
 *
 * Retirement runs in `afterEach`, NOT in a `finally`: a throwing cleanup inside
 * `finally` REPLACES the in-flight exception, whereas Playwright reports an
 * afterEach failure ALONGSIDE the test's own error. The assertion is gated on
 * the test having passed, so cleanup can never be mistaken for the verdict (M-1).
 */
let fixtures: { profileIds: string[]; repositoryIds: string[] } = { profileIds: [], repositoryIds: [] }

async function freshRepository(request: APIRequestContext): Promise<BankRepository> {
  const { repository } = await discoverOrProvisionRepository(request, owner)
  if (repository.code.startsWith('C2-STMT-')) fixtures.repositoryIds.push(repository.id)
  return repository
}

async function trackedProfile(
  request: APIRequestContext,
  repositoryId: string,
  overrides: Record<string, unknown> = {},
): Promise<string> {
  const profileId = await createParserProfile(request, owner, repositoryId, overrides)
  fixtures.profileIds.push(profileId)
  return profileId
}

test.describe('MTP-TRE — bank statement import (W-5b §E.4)', () => {
  test.describe.configure({ timeout: 180_000 })

  test.beforeAll(async ({ request }) => {
    owner = await login(request, 'owner')
  })

  test.beforeEach(() => {
    fixtures = { profileIds: [], repositoryIds: [] }
  })

  test.afterEach(async ({ request }, testInfo) => {
    const statuses: number[] = []
    for (const profileId of fixtures.profileIds) {
      statuses.push(await retireParserProfile(request, owner, profileId))
    }
    for (const repositoryId of fixtures.repositoryIds) {
      statuses.push(await deactivateRepository(request, owner, repositoryId))
    }
    if (testInfo.status === testInfo.expectedStatus) {
      expect(
        statuses.every((status) => status < 300),
        `fixture retirement statuses: ${JSON.stringify(statuses)}`,
      ).toBe(true)
    }
  })

  test('MTP-TRE-36 (P0): a clean 5-line CSV previews 5/0/0/0 and confirms with exact balances', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const profileId = await trackedProfile(request, repository.id)
    const label = uniq('T36')
    const amounts = ['120.500', '-45.250', '1000.000', '-0.125', '33.375']
    const csv = signedCsv(
      amounts.map((amount, index) => ({
        amount,
        reference: `${label}-R${index}`,
        label: `W5b TRE-36 row ${index}`,
      })),
    )

    const preview = await uploadStatementPreviewRaw(
      request,
      owner,
      repository.id,
      profileId,
      csv,
      `${label}.csv`,
    )
    expect(preview.status, `preview -> ${JSON.stringify(preview.body)}`).toBe(200)
    const envelope = preview.body.data as unknown as PreviewEnvelope
    expect(
      {
        accepted: envelope.accepted_line_count,
        duplicate: envelope.duplicate_fingerprint_count,
        droppedZero: envelope.dropped_zero_amount_rows,
        unparseable: envelope.unparseable_rows.length,
      },
      'the plan-exact classification envelope',
    ).toEqual({ accepted: 5, duplicate: 0, droppedZero: 0, unparseable: 0 })

    // Signed amounts survive the round trip at scale 3, sign carried by `direction`.
    expect(envelope.preview_lines.map((line) => `${line.direction}:${line.amount}`)).toEqual([
      'in:120.500',
      'out:45.250',
      'in:1000.000',
      'out:0.125',
      'in:33.375',
    ])

    const opening = await repositoryBalance(request, repository.id)
    const closing = addMoney(opening, signedSum(amounts))
    expect(signedSum(amounts), '120.500 - 45.250 + 1000.000 - 0.125 + 33.375').toBe('1108.500')

    const statementId = await confirmStatement(
      request,
      owner,
      repository.id,
      repository.currency,
      envelope.preview_token,
      opening,
      closing,
    )
    const statement = await get(request, owner, `/bank-statements/${statementId}`)
    expect(statement.ok).toBeTruthy()
    expect(statement.data.status).toBe('imported')
    expect(statement.data.opening_balance, 'opening persisted unrounded').toBe(opening)
    expect(statement.data.closing_balance, 'closing persisted unrounded').toBe(closing)
    expect((statement.data.lines as unknown[]).length, 'all 5 rows imported').toBe(5)
    // Importing a statement is a read of the bank, never a money movement.
    expect(await repositoryBalance(request, repository.id), 'import moves no money').toBe(opening)
  })

  test('MTP-TRE-37 (P0): re-uploading the same rows is deduped, never double-counted', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const profileId = await trackedProfile(request, repository.id)
    const label = uniq('T37')
    const rows = [
      { amount: '210.000', reference: `${label}-A`, label: 'W5b TRE-37 A' },
      { amount: '-60.500', reference: `${label}-B`, label: 'W5b TRE-37 B' },
    ]
    const csv = signedCsv(rows)

    const first = await uploadStatementPreview(request, owner, repository.id, profileId, csv, `${label}.csv`)
    expect(first.acceptedLineCount).toBe(2)
    const opening = await repositoryBalance(request, repository.id)
    const closing = addMoney(opening, signedSum(rows.map((r) => r.amount)))
    const statementId = await confirmStatement(
      request,
      owner,
      repository.id,
      repository.currency,
      first.previewToken,
      opening,
      closing,
    )

    // (a) The byte-identical file is refused outright — the source-file SHA-256
    //     is unique per repository (`StatementImportService::rejectDuplicateFile`).
    const identical = await uploadStatementPreviewRaw(
      request,
      owner,
      repository.id,
      profileId,
      csv,
      `${label}-again.csv`,
    )
    expect(identical.status, 'the same file cannot be imported twice').toBe(422)
    expect((identical.body.error as { code: string }).code).toBe('DUPLICATE_STATEMENT_FILE')
    expect((identical.body.errors as { existing_statement_id: string }).existing_statement_id).toBe(
      statementId,
    )

    // (b) A DIFFERENT file carrying the same two rows plus one new row: the two
    //     known rows classify as duplicates by fingerprint, only the new one is
    //     accepted. This is the "no double-counted bank lines" guarantee.
    const supersetCsv = signedCsv([
      ...rows,
      { amount: '15.750', reference: `${label}-C`, label: 'W5b TRE-37 C' },
    ])
    const superset = await uploadStatementPreviewRaw(
      request,
      owner,
      repository.id,
      profileId,
      supersetCsv,
      `${label}-superset.csv`,
    )
    expect(superset.status, `superset preview -> ${JSON.stringify(superset.body)}`).toBe(200)
    const envelope = superset.body.data as unknown as PreviewEnvelope
    expect(
      { accepted: envelope.accepted_line_count, duplicate: envelope.duplicate_fingerprint_count },
      'only the genuinely new row is accepted',
    ).toEqual({ accepted: 1, duplicate: 2 })
    expect(envelope.preview_lines.map((line) => `${line.direction}:${line.amount}`)).toEqual([
      'in:15.750',
    ])

    // The already-imported statement is untouched and still holds exactly 2 lines.
    const statement = await get(request, owner, `/bank-statements/${statementId}`)
    expect((statement.data.lines as unknown[]).length).toBe(2)
    expect(await repositoryBalance(request, repository.id), 'no phantom movement').toBe(opening)
  })

  test('MTP-TRE-38 (P1): a zero row is dropped and a malformed row is unparseable — neither imports', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const profileId = await trackedProfile(request, repository.id)
    const label = uniq('T38')
    // Row 2 = good, row 3 = zero amount, row 4 = malformed value date
    // (31/31/2026 is not a real date under `d/m/Y`), row 5 = good.
    // `line_number` is the PHYSICAL csv row, so the header is row 1.
    const csv = [
      'Date,Amount,Reference,Transaction ID,Label',
      `${DISPLAY_DATE},50.000,${label}-OK1,TX-${label}-OK1,W5b TRE-38 good`,
      `${DISPLAY_DATE},0.000,${label}-ZERO,TX-${label}-ZERO,W5b TRE-38 zero`,
      `31/31/2026,-77.000,${label}-BAD,TX-${label}-BAD,W5b TRE-38 malformed`,
      `${DISPLAY_DATE},-20.000,${label}-OK2,TX-${label}-OK2,W5b TRE-38 good 2`,
      '',
    ].join('\n')

    const preview = await uploadStatementPreviewRaw(
      request,
      owner,
      repository.id,
      profileId,
      csv,
      `${label}.csv`,
    )
    expect(preview.status, `preview -> ${JSON.stringify(preview.body)}`).toBe(200)
    const envelope = preview.body.data as unknown as PreviewEnvelope
    expect(
      {
        accepted: envelope.accepted_line_count,
        droppedZero: envelope.dropped_zero_amount_rows,
        unparseable: envelope.unparseable_rows.length,
      },
      'one dropped-zero, one unparseable, two accepted',
    ).toEqual({ accepted: 2, droppedZero: 1, unparseable: 1 })
    expect(envelope.unparseable_rows[0]!.row, 'the malformed row is reported by row number').toBe(4)
    expect(envelope.unparseable_rows[0]!.reason).toContain('date')
    expect(envelope.preview_lines.map((line) => `${line.direction}:${line.amount}`)).toEqual([
      'in:50.000',
      'out:20.000',
    ])

    const opening = await repositoryBalance(request, repository.id)
    const closing = addMoney(opening, signedSum(['50.000', '-20.000']))
    const statementId = await confirmStatement(
      request,
      owner,
      repository.id,
      repository.currency,
      envelope.preview_token,
      opening,
      closing,
    )
    const statement = await get(request, owner, `/bank-statements/${statementId}`)
    const lines = statement.data.lines as Array<{ amount: string; direction: string }>
    expect(lines.length, 'neither the zero nor the malformed row became a line').toBe(2)
    expect(lines.map((line) => `${line.direction}:${line.amount}`).sort()).toEqual([
      'in:50.000',
      'out:20.000',
    ])
    // A dropped or unparseable row must never become a phantom money movement.
    expect(await repositoryBalance(request, repository.id)).toBe(opening)
  })

  test('MTP-TRE-39 (P0): signed and debit/credit conventions produce identical signed amounts', async ({
    request,
  }) => {
    const repository = await freshRepository(request)
    const label = uniq('T39')
    const signedProfileId = await trackedProfile(request, repository.id)
    const debitCreditProfileId = await trackedProfile(request, repository.id, {
      column_map: {
        value_date: 'Date',
        debit: 'Debit',
        credit: 'Credit',
        reference: 'Reference',
        bank_transaction_id: 'Transaction ID',
        label: 'Label',
      },
      direction_convention: 'debit_credit_columns',
    })

    const signedFile = [
      'Date,Amount,Reference,Transaction ID,Label',
      `${DISPLAY_DATE},150.750,${label}-IN,TX-${label}-IN,W5b TRE-39 credit`,
      `${DISPLAY_DATE},-75.250,${label}-OUT,TX-${label}-OUT,W5b TRE-39 debit`,
      '',
    ].join('\n')
    const debitCreditFile = [
      'Date,Debit,Credit,Reference,Transaction ID,Label',
      `${DISPLAY_DATE},,150.750,${label}-IN,TX-${label}-IN,W5b TRE-39 credit`,
      `${DISPLAY_DATE},75.250,,${label}-OUT,TX-${label}-OUT,W5b TRE-39 debit`,
      '',
    ].join('\n')

    const signedPreview = await uploadStatementPreviewRaw(
      request,
      owner,
      repository.id,
      signedProfileId,
      signedFile,
      `${label}-signed.csv`,
    )
    const debitCreditPreview = await uploadStatementPreviewRaw(
      request,
      owner,
      repository.id,
      debitCreditProfileId,
      debitCreditFile,
      `${label}-dc.csv`,
    )
    expect(signedPreview.status, JSON.stringify(signedPreview.body)).toBe(200)
    expect(debitCreditPreview.status, JSON.stringify(debitCreditPreview.body)).toBe(200)

    const signedEnvelope = signedPreview.body.data as unknown as PreviewEnvelope
    const debitCreditEnvelope = debitCreditPreview.body.data as unknown as PreviewEnvelope
    const project = (envelope: PreviewEnvelope): string[] =>
      envelope.preview_lines.map((line) => `${line.direction}:${line.amount}`)

    // A convention mismatch that flips a sign is launch-blocking — assert the
    // ABSOLUTE expectation first, then the equality of the two conventions.
    expect(project(signedEnvelope), 'signed convention').toEqual(['in:150.750', 'out:75.250'])
    expect(project(debitCreditEnvelope), 'debit/credit convention').toEqual([
      'in:150.750',
      'out:75.250',
    ])
    expect(project(debitCreditEnvelope), 'both conventions agree exactly').toEqual(
      project(signedEnvelope),
    )
    expect(
      {
        accepted: debitCreditEnvelope.accepted_line_count,
        droppedZero: debitCreditEnvelope.dropped_zero_amount_rows,
        unparseable: debitCreditEnvelope.unparseable_rows.length,
      },
      'a blank debit or credit cell is not an error and not a dropped zero',
    ).toEqual({ accepted: 2, droppedZero: 0, unparseable: 0 })
  })

  test('MTP-TRE-40 (P1): a European decimal_format parses 1.234,56 to 1234.560', async ({ request }) => {
    const repository = await freshRepository(request)
    const label = uniq('T40')
    const profileId = await trackedProfile(request, repository.id, {
      decimal_format: 'comma',
    })
    // Semicolon-delimited, because the amounts themselves contain commas.
    // `CsvStatementParser::detectDelimiter()` picks the delimiter that yields the
    // most header columns, so `;` wins here.
    const csv = [
      'Date;Amount;Reference;Transaction ID;Label',
      `${DISPLAY_DATE};1.234,56;${label}-EU1;TX-${label}-EU1;W5b TRE-40 european thousands`,
      `${DISPLAY_DATE};-1.000,005;${label}-EU2;TX-${label}-EU2;W5b TRE-40 european millimes`,
      `${DISPLAY_DATE};0,125;${label}-EU3;TX-${label}-EU3;W5b TRE-40 european sub-unit`,
      '',
    ].join('\n')

    const preview = await uploadStatementPreviewRaw(
      request,
      owner,
      repository.id,
      profileId,
      csv,
      `${label}.csv`,
    )
    expect(preview.status, `preview -> ${JSON.stringify(preview.body)}`).toBe(200)
    const envelope = preview.body.data as unknown as PreviewEnvelope
    expect(
      { accepted: envelope.accepted_line_count, unparseable: envelope.unparseable_rows.length },
      'every European-formatted row parses',
    ).toEqual({ accepted: 3, unparseable: 0 })
    // `1.234,56` is one thousand two hundred thirty-four and fifty-six
    // centimes — NEVER `1.234`. Scale 3 pads the third decimal.
    expect(envelope.preview_lines.map((line) => `${line.direction}:${line.amount}`)).toEqual([
      'in:1234.560',
      'out:1000.005',
      'in:0.125',
    ])
  })
})
