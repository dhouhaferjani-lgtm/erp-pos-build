/**
 * MONEY TEST CAMPAIGN — C-2 fixture canary
 * (docs/qa/2026-08-02-full-e2e-campaign-plan.md §C, item C-2).
 *
 * Proves `statement-support.ts`'s extracted fixture builds a REAL
 * `bank_statements` row (with real Tier 1/3/4-matchable lines) against the
 * live local stack (web :5173 -> api :8010, tenant demo-pharmacy-tn) — the
 * exact precondition `MTP-PERM-08` and `MTP-TRE-36..49` were BLOCKED on
 * ("no bank_statements row exists in this tenant").
 *
 * This spec does NOT author those 15 cases — it only proves the fixture
 * they depend on works end to end. Authoring PERM-08/TRE-36..49 themselves
 * is follow-up work this canary merely unblocks.
 */
import { test, expect } from '@playwright/test'
import { login, type Session } from './treasury-support'
import { buildReconciliationFixture, confirmStatement, uploadStatementPreview } from './statement-support'

test.describe('C-2 — bank-statement reconciliation fixture', () => {
  test.describe.configure({ timeout: 90_000 })

  test('buildReconciliationFixture() produces a real, confirmed bank statement with Tier 1/3/4-matchable lines', async ({ request }) => {
    const owner: Session = await login(request, 'owner')

    const fixture = await buildReconciliationFixture(request, owner)

    expect(fixture.repository.id).toMatch(/^[0-9a-f-]{36}$/)
    expect(fixture.repository.type).toBe('bank_account')
    expect(fixture.profileId).toMatch(/^[0-9a-f-]{36}$/)
    expect(fixture.outboundInstrumentId).toMatch(/^[0-9a-f-]{36}$/)
    expect(fixture.fiscalEventId).toMatch(/^[0-9a-f-]{36}$/)
    expect(fixture.mainCsv).toContain(fixture.labels.adjustment)
    expect(fixture.mainCsv).toContain(fixture.labels.card)

    const preview = await uploadStatementPreview(
      request,
      owner,
      fixture.repository.id,
      fixture.profileId,
      fixture.mainCsv,
      `c2-fixture-canary-${Date.now()}.csv`,
    )
    expect(preview.acceptedLineCount).toBe(3)

    const statementId = await confirmStatement(
      request,
      owner,
      fixture.repository.id,
      fixture.repository.currency,
      preview.previewToken,
      fixture.openingBalance,
      fixture.closingBalance,
    )
    expect(statementId).toMatch(/^[0-9a-f-]{36}$/)

    // This IS a real bank_statements row with real statement_lines — the
    // exact fixture PERM-08 and TRE-36..49 were blocked on the absence of.
    const statementRes = await request.get(`http://127.0.0.1:8010/api/v1/bank-statements/${statementId}`, {
      headers: { Authorization: `Bearer ${owner.token}`, Accept: 'application/json' },
    })
    expect(statementRes.ok()).toBeTruthy()
    const statement = ((await statementRes.json()).data) as {
      status: string
      lines: Array<{ id: string; label: string; match_status: string }>
    }
    expect(statement.status).toBe('imported')
    expect(statement.lines).toHaveLength(3)
    expect(statement.lines.map((line) => line.label).sort()).toEqual(
      [fixture.labels.adjustment, fixture.labels.cheque, fixture.labels.card].sort(),
    )
    expect(statement.lines.every((line) => line.id.match(/^[0-9a-f-]{36}$/))).toBe(true)
  })
})
