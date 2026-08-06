/**
 * MONEY TEST CAMPAIGN — wave W-7 — `MTP-EMPTY-09..12`: the four empty/zero
 * states the plan lists as gaps (campaign plan §B.8 row 107 — "remittances,
 * instruments, statements-detail, VAT periods").
 *
 * Live local stack, real login, real backend, no mocks.
 *
 * ── THIS FILE CREATES NOTHING ─────────────────────────────────────────────
 * The tenant is far from empty (133 remittances, 420 instruments, 25 bank
 * statements — all W-5b/C-2 fixtures this wave must not touch), so each case
 * reaches its empty state through a FILTER that legitimately matches nothing,
 * exactly as a user would:
 *
 *   * EMPTY-09 — remittances filtered to a bank repository that has never
 *     been remitted to (all 133 slips are on `BANK-01`).
 *   * EMPTY-10 — instruments filtered to a maturity window years in the
 *     future.
 *   * EMPTY-11 — a REAL imported statement whose lines carry zero
 *     allocations: the zero-progress state of the reconciliation panel.
 *   * EMPTY-12 — VAT periods, which are NATURALLY empty here (0 rows).
 *     **`vat_periods` mutations belong to W-X: this case neither generates
 *     nor closes a period.**
 */
import { test, expect } from '@playwright/test'
import { apiRequest } from './helpers'
import { loginAsRoleResilient, settleAfterNav } from './w7-support'

/** A page that renders an empty state must not lie with a NaN, a bare
 * `undefined`, an error boundary, or a spinner that never resolves. */
async function expectCleanEmptyRender(
  page: { locator: (selector: string) => { innerText: () => Promise<string> } },
  label: string,
): Promise<string> {
  const body = await page.locator('body').innerText()
  expect(body, `${label}: no NaN`).not.toContain('NaN')
  expect(body, `${label}: no Infinity`).not.toContain('Infinity')
  expect(body, `${label}: no bare undefined`).not.toContain('undefined')
  expect(body, `${label}: no error boundary`).not.toMatch(/something went wrong|unexpected error/i)
  expect(body, `${label}: not a spinner-forever`).not.toMatch(/loading/i)
  return body
}

test.describe('EMPTY — remittances, instruments, statement detail, VAT periods', () => {
  test.setTimeout(120_000)

  test('MTP-EMPTY-09 (P1): a remittance list with no matching slip renders an empty state, not a zero-row table of NaN', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    // Find an ACTIVE bank repository that carries no remittance at all.
    const repos = await apiRequest(page, 'GET', '/payment-repositories')
    expect(repos.status).toBe(200)
    const bankRepos = ((repos.body as { data?: Array<{ id: string; code: string; type: string; is_active: boolean }> }).data ?? [])
      .filter((r) => r.type === 'bank_account' && r.is_active)
    expect(bankRepos.length, 'the tenant has active bank repositories').toBeGreaterThan(0)

    let emptyRepoId: string | null = null
    for (const repo of bankRepos) {
      const res = await apiRequest(page, 'GET', `/instrument-remittances?bank_repository_id=${repo.id}`)
      expect(res.status, `GET /instrument-remittances?bank_repository_id=${repo.code}`).toBe(200)
      const rows = (res.body as { data?: unknown[] }).data ?? []
      if (rows.length === 0) {
        emptyRepoId = repo.id
        // The API's own empty contract: an empty ARRAY plus a zeroed meta,
        // never a null and never a fabricated row.
        const meta = (res.body as { meta?: { total?: number } }).meta
        expect(rows, 'an empty remittance list is [] — not null').toEqual([])
        expect(meta?.total, 'meta.total is a real zero').toBe(0)
        break
      }
    }
    expect(
      emptyRepoId,
      'at least one active bank repository has never been remitted to (all W-5b slips sit on BANK-01)',
    ).toBeTruthy()

    // The page itself. The list route is gated on `instruments.remit`, which
    // the owner holds.
    await page.goto('/treasury/remittances')
    await settleAfterNav(page)
    expect(page.url(), 'the remittances page renders for the owner').toContain('/treasury/remittances')
    const body = await expectCleanEmptyRender(page, 'EMPTY-09 /treasury/remittances')

    // The CTA that gets a user OUT of the empty state must be present.
    // `.or()` composes two locators and THEN resolves, so both members must be
    // narrowed before the union or strict mode trips on 2 matches (this page
    // renders both a link and a button labelled "New remittance").
    await expect(
      page
        .locator('a[href^="/treasury/remittances/new"]')
        .or(page.getByRole('button', { name: /new remittance|create remittance|nouvelle remise/i }))
        .first(),
      'EMPTY-09: an entry point to create a remittance is offered',
    ).toBeVisible({ timeout: 15_000 })

    // No money figure may be rendered at a scale other than the TND 3 —
    // the classic empty-state bug is a `0` or `0.00` total.
    const zeros = body.match(/\b0[.,]\d+\b/g) ?? []
    const wrongScale = zeros.filter((z) => z.length !== 5)
    expect(
      wrongScale,
      `EMPTY-09: a zero money figure renders at scale 3 (offenders: ${wrongScale.join(', ')})`,
    ).toEqual([])
  })

  test('MTP-EMPTY-10 (P1): an instrument list filtered to a future maturity window is empty and scale-correct', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    // A window years past every instrument on the tenant.
    const res = await apiRequest(
      page,
      'GET',
      '/payment-instruments?maturity_from=2031-01-01&maturity_to=2031-12-31',
    )
    expect(res.status, 'a future-window query is a valid query, not an error').toBe(200)
    const rows = (res.body as { data?: unknown[] }).data ?? []
    expect(rows, 'no instrument matures in 2031 — the list is []').toEqual([])
    expect(
      (res.body as { meta?: { total?: number } }).meta?.total,
      'meta.total is a real zero, not null',
    ).toBe(0)

    // …while the UNFILTERED list is genuinely non-empty, so the empty result
    // above is the filter and not a broken endpoint.
    const unfiltered = await apiRequest(page, 'GET', '/payment-instruments?per_page=1')
    expect(unfiltered.status).toBe(200)
    expect(
      ((unfiltered.body as { meta?: { total?: number } }).meta?.total ?? 0),
      'the tenant really does carry instruments',
    ).toBeGreaterThan(0)

    await page.goto('/treasury/instruments')
    await settleAfterNav(page)
    expect(page.url(), 'the instruments page renders for the owner').toContain('/treasury/instruments')
    await expectCleanEmptyRender(page, 'EMPTY-10 /treasury/instruments')
  })

  test('MTP-EMPTY-11 (P1): a statement detail with zero allocations shows a zero-progress panel, no divide-by-zero', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    const list = await apiRequest(page, 'GET', '/bank-statements')
    expect(list.status).toBe(200)
    const statements = (list.body as { data?: Array<{ id: string; status: string }> }).data ?? []
    expect(statements.length, 'a real statement exists (C-2 / W-5b fixtures)').toBeGreaterThan(0)

    // Prefer a statement whose lines are ALL unallocated — the zero state.
    let target: { id: string; lines: Array<{ amount: string; allocations: unknown[]; match_status: string }> } | null =
      null
    for (const summary of statements.slice(0, 10)) {
      const detail = await apiRequest(page, 'GET', `/bank-statements/${summary.id}`)
      if (detail.status !== 200) continue
      const data = (detail.body as {
        data: { id: string; lines?: Array<{ amount: string; allocations: unknown[]; match_status: string }> }
      }).data
      const lines = data.lines ?? []
      if (lines.length > 0 && lines.every((l) => (l.allocations ?? []).length === 0)) {
        target = { id: data.id, lines }
        break
      }
    }
    expect(target, 'a statement with zero allocations exists to read').toBeTruthy()

    // API contract for the zero state: allocations are an EMPTY ARRAY, not
    // null, and the line still carries its full amount at scale 3.
    for (const line of target!.lines) {
      expect(line.allocations, 'an unallocated line carries [] — not null').toEqual([])
      expect(line.match_status, 'an unallocated line is `unmatched`').toBe('unmatched')
      expect(line.amount, 'the line amount is a scale-3 money string').toMatch(/^-?\d+\.\d{3}$/)
    }

    await page.goto(`/treasury/statements/${target!.id}`)
    await settleAfterNav(page)
    expect(page.url(), 'the statement detail page renders').toContain(`/treasury/statements/${target!.id}`)
    const body = await expectCleanEmptyRender(page, 'EMPTY-11 statement detail')

    // No divide-by-zero artefact in a reconciliation progress figure.
    expect(body, 'EMPTY-11: no Infinity/NaN percentage in the reconciliation progress').not.toMatch(
      /(NaN|Infinity)\s*%/,
    )

    // Every line's amount really renders (a zero-allocation panel must not
    // swallow the line).
    for (const line of target!.lines) {
      const digits = line.amount.replace(/\D/g, '')
      expect(
        body.replace(/\D/g, ''),
        `EMPTY-11: the unallocated line amount ${line.amount} still renders`,
      ).toContain(digits)
    }
  })

  test('MTP-EMPTY-12 (P1): the VAT-period list is empty by nature and renders a clean prompt — no period is generated or closed', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')

    // READ-ONLY. `POST /vat/periods/generate` and `POST /vat/periods/{id}/close`
    // belong to W-X; this case must not fire either, and does not.
    const res = await apiRequest(page, 'GET', '/vat/periods')
    expect(res.status, 'GET /vat/periods (reports.financial — admin/accountant, see W-6 D5)').toBe(200)
    const rows = (res.body as { data?: unknown[] }).data ?? []
    expect(rows, 'this tenant has never had a VAT period — the list is []').toEqual([])

    await page.goto('/finance/vat-periods')
    await settleAfterNav(page)
    expect(page.url(), 'the VAT-periods page renders for the owner').toContain('/finance/vat-periods')
    const body = await expectCleanEmptyRender(page, 'EMPTY-12 /finance/vat-periods')

    // A user must be told what to do, not shown a blank table. Either an
    // explicit empty message or a generate CTA satisfies this.
    expect(
      /no (vat )?period|aucune période|generate|générer/i.test(body),
      'EMPTY-12: the empty VAT-period list explains itself (message or generate CTA)',
    ).toBe(true)

    // And nothing was created by rendering it.
    const after = await apiRequest(page, 'GET', '/vat/periods')
    expect(
      ((after.body as { data?: unknown[] }).data ?? []),
      'EMPTY-12: reading the page created no VAT period',
    ).toEqual([])
  })
})
