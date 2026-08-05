/**
 * MONEY TEST CAMPAIGN — wave W-7 — `MTP-I18N-09..11`: money rendering on the
 * document surfaces (campaign plan §B.1 "i18n money render on document detail
 * (**G** `MTP-I18N-09`)" and §B.8 row 106 "`MTP-I18N-09..11`").
 *
 * The plan names the three case IDs but not their bodies. Scoped here as the
 * three renderings §B.1/§B.8 actually owe, each on a REAL posted document read
 * live (this file AUTHORS NOTHING — it reads an existing posted invoice, so it
 * adds no money to a tenant that is already carrying documented W-4/W-5/W-6
 * noise):
 *
 *   * `MTP-I18N-09` — invoice DETAIL under `?lang=fr`: every rendered money
 *     figure is the API's figure digit-for-digit, at the TND scale of 3, with
 *     U+202F grouping and a comma decimal. **This is where finding F-7 lives.**
 *   * `MTP-I18N-10` — the same document under `?lang=ar`: RTL applies and the
 *     money survives it (no digit substitution, no lost scale).
 *   * `MTP-I18N-11` — the invoice LIST and DETAIL under `?lang=fr`: a
 *     D6-style sweep for a money cell rendered in the WRONG currency.
 *     `lib/format.ts:77` defaults `formatCurrency`'s currency to `'EUR'`, and
 *     W-6 found six tiles doing exactly that on `/finance/overview` — this
 *     case asks whether the sales document surfaces have the same hole.
 *
 * Live local stack, real login, real backend, no mocks.
 */
import { test, expect, type Page } from '@playwright/test'
import { apiRequest, FR_NBSP } from './helpers'
import { digitsOf, loginAsRoleResilient, settleAfterNav, toScale3, waitForMoneyRender } from './w7-support'

interface InvoiceRow {
  id: string
  number: string | null
  status: string
  total: string
  subtotal?: string
}

/** Both no-break grouping separators this app can emit: U+202F (fr Intl) and
 * U+00A0. ASCII comma is accepted too — the en-US formatter groups with it. */
const NUMBER_CHARS = `\\d.,${FR_NBSP}\u00a0 `

/**
 * Every money figure on a page, keyed on the CURRENCY LABEL that follows it
 * (`… TND`). Keying on the label rather than on a bare number shape is what
 * keeps quantities (`1.0000`), percentages and dates out of the sample — the
 * first version of this matcher swept a `1.0000` quantity cell into the
 * scale-3 assertion and failed for the wrong reason.
 *
 * Returns the numeric part only, under EITHER decimal convention: the
 * document detail page renders both (see the F-7 tripwire below).
 */
function moneyTokens(text: string): string[] {
  const matches = text.matchAll(new RegExp(`([${NUMBER_CHARS}]*\\d)\\s*([A-Z]{3})`, 'g'))
  return [...matches].map((m) => m[1]!.trim()).filter((t) => /\d/.test(t))
}

/** The decimal separator and decimals of a money token, whichever convention
 * it used — `'2,499.000'` -> `['.', '000']`, `'2 500,000'` -> `[',', '000']`. */
function decimalPart(token: string): { separator: string; decimals: string } {
  const m = token.match(/([.,])(\d{1,4})$/)
  return m === null ? { separator: '', decimals: '' } : { separator: m[1]!, decimals: m[2]! }
}

/**
 * Reads the page's money tokens once the TOTALS PANEL has actually mounted.
 *
 * Three weaker gates were tried first and all flaked. `waitForMoneyRender()`
 * only proves the FIRST currency figure arrived (the header's "Montant dû").
 * A "two identical consecutive reads" settle can lock onto that partial state.
 * And anchoring on the SUBTOTAL FIGURE is ambiguous on a single-line invoice,
 * where the subtotal and the line total are the same number — the poll passes
 * on the line cell while the panel is still loading its tax breakdown, which
 * is exactly how MTP-I18N-09 failed in the whole-wave run after passing
 * scoped.
 *
 * The panel's own LABEL is the unambiguous signal. `DocumentTotals.tsx`
 * renders it partially translated (fr "Sous-total", English fallback
 * "Subtotal" under ar), so both spellings are accepted.
 */
async function moneyTokensOnceTotalsRendered(page: Page, timeout = 30_000): Promise<string[]> {
  await expect
    .poll(async () => /sous-total|subtotal/i.test(await page.locator('body').innerText()), {
      timeout,
      message: 'the document totals panel never mounted',
    })
    .toBe(true)
  return moneyTokens(await page.locator('body').innerText())
}

/** The largest-total posted invoice on the first pages of the list, so the
 * grouping-separator assertions have a >= 1 000 figure to work with. */
async function findPostedInvoice(page: Page): Promise<InvoiceRow> {
  let best: InvoiceRow | null = null
  for (const status of ['posted', 'paid']) {
    const res = await apiRequest(page, 'GET', `/invoices?per_page=100&status=${status}`)
    if (res.status !== 200) continue
    const rows = (res.body as { data?: InvoiceRow[] }).data ?? []
    for (const row of rows) {
      if (best === null || Number(row.total) > Number(best.total)) best = row
    }
  }
  expect(best, 'a posted invoice exists on this tenant to render').toBeTruthy()
  return best!
}

/**
 * Reads the page's money tokens once a KNOWN figure has rendered.
 *
 * Two weaker gates were tried first and both flaked: `waitForMoneyRender()`
 * only proves the FIRST currency figure arrived (the header's "Montant dû"),
 * and a "two identical consecutive reads" settle can lock onto that partial
 * state while the totals panel is still mounting — which is exactly how
 * MTP-I18N-09 failed in the whole-wave run after passing scoped. Anchoring on
 * a figure that ONLY the totals panel renders (the subtotal) is deterministic:
 * either the panel is up or the poll times out and says so.
 */
async function moneyTokensOnceRendered(page: Page, anchor: string, timeout = 30_000): Promise<string[]> {
  const wanted = digitsOf(anchor)
  await expect
    .poll(async () => digitsOf(await page.locator('body').innerText()).includes(wanted), {
      timeout,
      message: `the page never rendered the anchor figure ${anchor}`,
    })
    .toBe(true)
  return moneyTokens(await page.locator('body').innerText())
}

/** `body`, not `main`: on the document DETAIL route the rendered content does
 * not sit under the `<main>` element the LIST route uses, and
 * `main.innerText()` comes back empty there (verified live). */
async function pageText(page: Page): Promise<string> {
  return page.locator('body').innerText()
}

test.describe('I18N — money rendering on document surfaces', () => {
  test.setTimeout(120_000)

  test('MTP-I18N-09 (P1): the fr document detail renders ONE decimal convention — the currency`s — everywhere', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')
    const invoice = await findPostedInvoice(page)

    const detail = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    expect(detail.status).toBe(200)
    const data = (detail.body as { data: Record<string, unknown> }).data
    const total = toScale3(String(data.total))
    const subtotal = toScale3(String(data.subtotal ?? '0'))

    await page.goto(`/sales/invoices/${invoice.id}?lang=fr`)
    await settleAfterNav(page)
    await waitForMoneyRender(page)
    // The SUBTOTAL is rendered only by the totals panel, so anchoring on it
    // guarantees the F-7 assertions below sample a fully-mounted page.
    const tokens = await moneyTokensOnceTotalsRendered(page)
    const body = await pageText(page)
    expect(body, 'the fr document detail really rendered').toContain('Facture')

    // 1) The API's figures really are on the page — compared as DIGITS, so
    //    the assertion is immune to the separator and to symbol placement.
    expect(
      digitsOf(body),
      `the invoice total ${total} renders on its own detail page`,
    ).toContain(digitsOf(total))
    expect(digitsOf(body), `the subtotal ${subtotal} renders too`).toContain(digitsOf(subtotal))

    // 2) Scale 3, not 2: a TND figure must show three decimals. EVERY
    //    money-shaped token is checked, under either decimal convention, so a
    //    single scale-2 cell fails this.
    expect(tokens.length, 'the detail page renders money at all').toBeGreaterThan(0)
    const wrongScale = tokens.filter((t) => decimalPart(t).decimals.length !== 3)
    expect(
      wrongScale,
      `every money figure on a TND document renders at scale 3 (offenders: ${wrongScale.join(', ')})`,
    ).toEqual([])

    // 3) fr grouping is the NARROW NO-BREAK SPACE (U+202F), not an ASCII
    //    space — the vector MTP-I18N-01/02 pinned, re-asserted here because a
    //    DIFFERENT formatter is in play on this surface (see 4).
    if (Number(total) >= 1000) {
      expect(
        tokens.filter((t) => t.includes(FR_NBSP) || t.includes(' ')).length,
        'a >= 1 000 figure groups with a no-break space on the document detail page',
      ).toBeGreaterThan(0)
    } else {
      test.info().annotations.push({
        type: 'PARTIAL',
        description: `The largest posted invoice on this tenant totals ${total} (< 1 000), so the grouping half of I18N-09 is not exercised; the scale-3, digit-identity and F-7 halves are.`,
      })
    }

    // 4) ── W-7 F-7 — FIXED (fix lane L4) ────────────────────────────────
    //    ONE DECIMAL CONVENTION PER DOCUMENT. This used to be two: the header
    //    ("Montant dû") and the line cells rendered `499,000 TND` — comma
    //    decimal, fr-TN, correct — while the TOTALS PANEL on the same screen
    //    rendered `499.000 TND`, because
    //    `features/documents/components/DocumentTotals.tsx` called
    //    `formatNumber(amount, decimals)` and `lib/format.ts` pinned that
    //    helper's `locale` parameter to `'en-US'`, ignoring both the UI
    //    language and the currency's locale. To a French or Tunisian reader
    //    `1,191.000` reads as one million — a 1 000x misread of the Subtotal,
    //    the VAT, the stamp duty, the Total and the Balance Due, on the
    //    document a customer is invoiced from.
    //
    //    The panel now formats against the DOCUMENT's currency
    //    (`formatCurrency(..., { includeCurrency: false })`), and
    //    `formatNumber`'s locale default resolves from the active company's
    //    currency instead of `en-US`. Note this was CURRENCY-driven, not
    //    language-driven: it fired in every UI language and for EUR companies
    //    too, so the assertion below is about the CURRENCY convention, not
    //    about `?lang=fr`.
    const dotFormatted = tokens.filter((t) => decimalPart(t).separator === '.')
    const commaFormatted = tokens.filter((t) => decimalPart(t).separator === ',')
    expect(
      commaFormatted.length,
      'the fr-TN formatter IS in use on this page (header + line cells)',
    ).toBeGreaterThan(0)
    expect(
      dotFormatted,
      `F-7 FIXED: no money on a TND document renders with a DOT decimal (offenders: ${dotFormatted.join(', ')})`,
    ).toEqual([])
    // …and specifically not the en-US shape (comma thousands + dot decimal),
    // which is the one that reads as a 1 000x error in fr.
    expect(
      tokens.filter((t) => /^\d{1,3}(,\d{3})*\.\d{3}$/.test(t)),
      'F-7 FIXED: the en-US shape (1,234.567) is gone from the document detail page',
    ).toEqual([])
    // The document TOTAL is one of the comma-formatted figures — the money is
    // still on the page, it is only rendered in the currency's convention now.
    expect(
      digitsOf(commaFormatted.join(' ')),
      'F-7 FIXED: the document TOTAL renders through the currency formatter',
    ).toContain(digitsOf(total))
  })

  test('MTP-I18N-10 (P1): the same document under ar is RTL and its money survives unchanged', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')
    const invoice = await findPostedInvoice(page)
    const detail = await apiRequest(page, 'GET', `/invoices/${invoice.id}`)
    const total = toScale3(String((detail.body as { data: { total: string } }).data.total))

    // fr first, to have a reference rendering from the same run.
    await page.goto(`/sales/invoices/${invoice.id}?lang=fr`)
    await settleAfterNav(page)
    await waitForMoneyRender(page)
    const frTokens = await moneyTokensOnceTotalsRendered(page)

    await page.goto(`/sales/invoices/${invoice.id}?lang=ar`)
    await settleAfterNav(page)
    expect(await page.locator('html').getAttribute('dir'), 'ar applies RTL at the document root').toBe(
      'rtl',
    )
    await waitForMoneyRender(page)
    const arTokens = await moneyTokensOnceTotalsRendered(page)
    const arBody = await pageText(page)

    // The money must survive the direction switch untouched: same digits, no
    // Arabic-Indic substitution (which would break every downstream parse),
    // still scale 3.
    expect(digitsOf(arBody), `the total ${total} still renders under ar`).toContain(digitsOf(total))
    expect(arBody, 'no Arabic-Indic digits in a money figure (U+0660-U+0669)').not.toMatch(/[٠-٩]/)

    expect(arTokens.length, 'ar still renders money').toBeGreaterThan(0)
    expect(
      arTokens.filter((t) => decimalPart(t).decimals.length !== 3),
      'scale 3 survives the RTL switch',
    ).toEqual([])

    // The numeric content is identical between the two locales — only the
    // surrounding labels change. (Same conclusion as MTP-I18N-04b: TND money
    // formats fr-TN via currencyMeta.ts regardless of the UI language. Since
    // the F-7 fix the totals panel does so too, rather than falling back to
    // en-US — see MTP-I18N-09 above.)
    expect(
      new Set(arTokens.map(digitsOf)),
      'the ar rendering carries exactly the fr rendering`s figures',
    ).toEqual(new Set(frTokens.map(digitsOf)))
  })

  test('MTP-I18N-11 (P1): no money cell on the sales-document surfaces is labelled in the wrong currency', async ({
    page,
  }) => {
    await loginAsRoleResilient(page, 'owner')
    const invoice = await findPostedInvoice(page)

    // The company currency, read live rather than assumed. `GET
    // /settings/company` exposes it as `currency_code`
    // (CompanyController::formatCompany) — not `currency`, which is the shape
    // `/user/companies` uses.
    const company = await apiRequest(page, 'GET', '/settings/company')
    expect(company.status).toBe(200)
    const currency = String(
      (company.body as { data?: { currency_code?: string } }).data?.currency_code ?? '',
    )
    expect(currency, 'this tenant is the Tunisian dinar company').toBe('TND')

    // Sweep the LIST and the DETAIL — W-6's D6 found the wrong-currency
    // default on a page whose sibling tiles were correct, so both are checked.
    for (const route of ['/sales/invoices?lang=fr', `/sales/invoices/${invoice.id}?lang=fr`]) {
      await page.goto(route)
      await settleAfterNav(page)
      await waitForMoneyRender(page)
      const body = await pageText(page)

      expect(
        body,
        `${route}: no EUR-labelled figure on a TND company (the lib/format.ts:77 default)`,
      ).not.toMatch(/\d\s*(EUR|€)/)
      expect(body, `${route}: no US-dollar figure either`).not.toMatch(/\$\s*\d/)
      expect(body, `${route}: no NaN`).not.toContain('NaN')

      // Where a currency IS printed next to a figure it must be the
      // company's.
      const labelled = body.match(new RegExp(`[${NUMBER_CHARS}]*\\d\\s*[A-Z]{3}`, 'g')) ?? []
      const foreign = labelled.filter((t) => !t.trim().endsWith(currency))
      expect(
        foreign,
        `${route}: every currency-labelled figure carries ${currency} (offenders: ${foreign.join(', ')})`,
      ).toEqual([])
    }
  })
})
