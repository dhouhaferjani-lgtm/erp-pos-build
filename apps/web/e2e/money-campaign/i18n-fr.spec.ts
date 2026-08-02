import { test, expect } from '@playwright/test'
import { loginAsRole, FR_NBSP, normalizeSpaces } from './helpers'

/**
 * MONEY TEST CAMPAIGN - I.3 `I18N` fr-locale number formatting
 * (docs/qa/2026-08-01-money-test-plan.md, MTP-I18N-01..08).
 *
 * Targets the live local stack, tenant demo-pharmacy-tn, owner@pharmabio.tn (I18N cases
 * are role-agnostic; the plan does not require cashier). Locale is switched via the
 * `?lang=` querystring (i18next detection order is querystring > localStorage > navigator,
 * confirmed in src/lib/i18n.ts) - this sidesteps a TopBar click-interception defect
 * documented below (I18N-04a) rather than depending on it.
 *
 * Ground truth used throughout: /finance/trial-balance for demo-pharmacy-tn renders the
 * "401 Fournisseurs" row's CREDIT cell containing "3 200,000 TND" where the character
 * between "3" and "200" is code point 8239 (U+202F NARROW NO-BREAK SPACE) - verified via
 * charCodeAt at execution time, exactly the plan's predicted vector.
 */

test.describe('I18N - fr-locale number formatting', () => {
  test.setTimeout(60_000) // local single-process dev backend can be slow under sequential load

  test('MTP-I18N-01 (P1): TND amount renders with NBSP grouping + comma decimal + 3dp', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/trial-balance?lang=fr')
    const row = page.locator('tr', { hasText: 'Fournisseurs' }).first()
    await expect(row).toBeVisible({ timeout: 20_000 })
    const text = await row.textContent()
    expect(text).not.toBeNull()
    const value = text ?? ''

    // Exact vector: "3<NBSP>200,000" - grouping separator is U+202F, decimal is comma, 3dp.
    expect(value).toContain(`3${FR_NBSP}200,000`)
    // And prove it, not just contain a look-alike: the grouping character is genuinely
    // U+202F, not a plain ASCII space that a naive test/matcher could confuse it with.
    const nbspIndex = value.indexOf('200,000') - 1
    expect(value.charCodeAt(nbspIndex)).toBe(0x202f)
  })

  test('MTP-I18N-02 (P1): raw ASCII-space comparison must FAIL; only an explicit normalizer passes', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/trial-balance?lang=fr')
    const row = page.locator('tr', { hasText: 'Fournisseurs' }).first()
    await expect(row).toBeVisible({ timeout: 20_000 })
    const text = (await row.textContent()) ?? ''

    // A test asserting against an ASCII-space expectation must NOT pass on a raw compare -
    // if it did, the harness would be silently masking a real NBSP regression.
    expect(text.includes('3 200,000')).toBe(false) // ASCII space (U+0020) between 3 and 200 - absent
    expect(text.includes(`3${FR_NBSP}200,000`)).toBe(true) // real NBSP - present

    // Only passes once explicitly normalized - this is the required, stated mechanism,
    // not silent normalization inside a matcher.
    expect(normalizeSpaces(text)).toContain('3 200,000')
  })

  test('MTP-I18N-03 (P1): BLOCKED - EUR company (demo-garage) not available in this environment', async () => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'Logging in as test@example.com/password (demo-garage FR/EUR company, per plan section 2) ' +
        'returned "No organizations found for that email." on this local stack - the ' +
        'DatabaseSeeder default fixture was not run/loaded here. Only demo-pharmacy-tn ' +
        '(TND) credentials are available to verify.',
    })
    test.skip(true, 'demo-garage EUR company not seeded on this local stack')
  })

  test('MTP-I18N-04a (P1): en<->fr switch via TopBar language menu - real mouse click is BLOCKED (same defect as AUTH-09)', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/trial-balance')
    await expect(page.locator('tr', { hasText: 'Fournisseurs' }).first()).toBeVisible({ timeout: 20_000 })
    await page.getByRole('button', { name: /select language/i }).click()
    const frOption = page.getByText('Français', { exact: true })
    await expect(frOption).toBeVisible()
    // Same root cause as AUTH-09 (documented in helpers.ts logout()): the language
    // dropdown is another un-z-indexed position:absolute TopBar child, so a real,
    // unforced click on "Francais" is captured by <main> underneath and never reaches
    // it. Assert the failure mode directly rather than via a nested nowait/retry
    // combinator (which proved fragile against this app's per-test 30s budget).
    let clickError: unknown = null
    try {
      await frOption.click({ timeout: 5_000 })
    } catch (e) {
      clickError = e
    }
    expect(clickError).not.toBeNull()
    expect(String(clickError)).toContain('intercepts pointer events')
    // Prove nothing changed - the UI never actually left English.
    await expect(page.locator('body')).toContainText('Trial Balance')
  })

  test('MTP-I18N-04b (P1): en<->fr - numeric values identical; TND formatting is currency-locale-bound, not UI-locale-bound', async ({ page }) => {
    await loginAsRole(page, 'owner')

    // Order matters: i18next detection is querystring > localStorage > navigator, with
    // localStorage caching (src/lib/i18n.ts caches:['localStorage']) - an explicit
    // ?lang=fr visit PERSISTS, so a later plain (no querystring) visit would silently
    // stay on fr. Read the true-default (en, fresh login) state FIRST.
    await page.goto('/finance/trial-balance')
    await expect(page.locator('tr', { hasText: 'Fournisseurs' }).first()).toBeVisible({ timeout: 20_000 })
    const enHeading = (await page.locator('h1, h2').first().textContent()) ?? ''
    const enRow = (await page.locator('tr', { hasText: 'Fournisseurs' }).first().textContent()) ?? ''
    expect(enHeading).toMatch(/trial balance/i)

    await page.goto('/finance/trial-balance?lang=fr')
    await expect(page.locator('tr', { hasText: 'Fournisseurs' }).first()).toBeVisible({ timeout: 20_000 })
    const frHeading = (await page.locator('h1, h2').first().textContent()) ?? ''
    const frRow = (await page.locator('tr', { hasText: 'Fournisseurs' }).first().textContent()) ?? ''
    // Confirm ?lang= actually switched the UI (not two identical loads): the heading
    // translates even though, per below, this money cell does not.
    expect(frHeading.toLowerCase()).toContain('balance generale')

    // Corrected expectation vs. the plan's literal wording ("separators switch"): TND
    // amounts render via the currency->locale map (TND -> fr-TN, precision contract
    // section I.3 / src/lib/currencyMeta.ts) REGARDLESS of the selected UI language -
    // only surrounding labels/menus switch. Observed: en-UI and fr-UI both render
    // "3 200,000" for this TND figure (NBSP grouping present even under the English UI,
    // confirmed via charCodeAt in MTP-I18N-01/02). Checked here, not assumed: digits
    // identical across the UI-locale switch, and neither run re-rounds the value.
    const digitsOnly = (s: string) => s.replace(/[^\d]/g, '')
    expect(digitsOnly(frRow)).toBe(digitsOnly(enRow))
    expect(frRow).toContain(`3${FR_NBSP}200,000`)
    expect(enRow).toContain(`3${FR_NBSP}200,000`)

    // Separately: the account TYPE column ("Liability") is NOT translated under fr
    // either - a partial-translation gap in the same family as the ar fallback covered
    // by MTP-I18N-07, distinct from the money-formatting question above.
    expect(frRow.toLowerCase()).toContain('liability')
  })

  test('MTP-I18N-05 (P1): BLOCKED - no negative money figure exists in reachable data', async () => {
    test.info().annotations.push({
      type: 'BLOCKED',
      description:
        'No negative documents.total exists in tenant019fbe86-944a-7252-8a3b-8c341dfa9de9 ' +
        '(confirmed via direct DB query: zero rows with total<0, and zero credit-note-type ' +
        'documents). Legacy negative-return receipts and cash-variance figures live on the ' +
        'POS side, which requires device authoring per plan section 0.4/2 - out of scope for ' +
        'this web-only PERM/I18N/EMPTY/AUTH agent slot.',
    })
    test.skip(true, 'no negative money figure available to render')
  })

  test('MTP-I18N-06 (P1): zero balance renders as scale-3 "0,000", never bare "0" or blank', async ({ page }) => {
    await loginAsRole(page, 'owner')
    // A real customer with receivable_balance = 0.000 (confirmed via DB query).
    await page.goto('/sales/customers/019fbe95-6a3d-7189-9ff2-6ef7e00ad313?lang=fr')
    await expect(page.locator('body')).toContainText('0,000 TND', { timeout: 40_000 })
  })

  test('MTP-I18N-07 (P2): ar locale - RTL applies, amounts stay readable, untranslated bits fall back to English', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/finance/trial-balance?lang=ar')
    await expect(page.locator('tr').first()).toBeVisible({ timeout: 20_000 })
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')
    // The number itself (grouping/decimal) is untouched by RTL - still readable digits.
    await expect(page.locator('body')).toContainText(`3${FR_NBSP}200,000`, { timeout: 20_000 })
    // Untranslated fallback: this page's own title/columns are not in the ar bundle at
    // authoring time - confirm English fallback text still renders (not a blank/broken key).
    await expect(page.locator('body')).toContainText('Trial Balance')
  })

  test('MTP-I18N-08 (P1): MoneyInput - comma typed in fr locale reaches the API as a canonical dot-decimal string', async ({ page }) => {
    await loginAsRole(page, 'owner')
    await page.goto('/expenses/new?lang=fr')
    await expect(page.getByLabel(/montant \*/i).first()).toBeVisible({ timeout: 20_000 })

    let capturedBody: string | null = null
    page.on('request', (req) => {
      if (req.url().includes('/api/v1/expenses') && req.method() === 'POST') {
        capturedBody = req.postData()
      }
    })

    const amountField = page.getByLabel(/montant \*/i).first()
    await amountField.click()
    // pressSequentially (real keystrokes), not fill() - fill() on a native
    // <input type="number"> outright refuses a comma character (Playwright throws
    // "Cannot type text into input[type=number]"), which is not representative of
    // how a real user typing on a keyboard interacts with the field.
    await amountField.pressSequentially('1234,50')
    await expect(amountField).toHaveValue('1234.50')

    await page.getByLabel(/nom du fournisseur/i).fill('MTP-I18N-08 campaign probe')
    const dateField = page.locator('input[type="date"]').first()
    if (await dateField.isVisible().catch(() => false)) {
      await dateField.fill('2026-08-01')
    }
    await page.getByRole('button', { name: /enregistrer|créer/i }).first().click()
    await page.waitForTimeout(1500)

    expect(capturedBody).not.toBeNull()
    const payload = JSON.parse(capturedBody ?? '{}') as { total?: unknown }
    // MUST be a canonical dot-decimal STRING, never a float and never comma-formatted.
    expect(typeof payload.total).toBe('string')
    expect(payload.total).toBe('1234.50')
  })
})
