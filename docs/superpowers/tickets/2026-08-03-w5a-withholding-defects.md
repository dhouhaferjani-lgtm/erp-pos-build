# W-5a withholding defects (money campaign, 2026-08-03)

**Source:** wave W-5a of the pre-launch full-E2E money campaign
(`docs/qa/2026-08-02-full-e2e-campaign-plan.md` §B.5 row 73, brief
`.superpowers/sdd/2026-08-02-full-e2e-campaign-plan/wave-5a-brief.md`;
case definitions `docs/qa/2026-08-01-money-test-plan.md` §E.7 `MTP-WHT-01..05`).
**Discovered by:** live runs against the local stack (web :5173 / api :8010, tenant
`demo-pharmacy-tn`). **No product code was changed.** Every item below is TRIPWIRED by a
GREEN spec assertion in `apps/web/e2e/money-campaign/withholding.spec.ts` that pins TODAY'S
defective behaviour (campaign convention — the fix flips the tripwire red and forces a
deliberate spec update; the expected post-fix values are stated in each tripwire's comment),
except the two minors explicitly marked "no tripwire". *(Fix round 1, 2026-08-04: the three
tripwires originally asserted the expected behaviour and were permanently red; flipped to
the green convention per orchestrator ruling.)*

Consolidated per the wave's hard rule 3 (one ticket file for the wave).

---

## #1 — P1: a **zero** withholding rate still manufactures a certificate for `0.000`

**Where:** `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:916-940`
→ `apps/api/app/Modules/Taxation/Application/Services/WithholdingCertificateService.php:145-203`
→ `apps/api/app/Modules/Taxation/Domain/ValueObjects/WithholdingCalculation.php:48-65`.

**Tripwire:** `MTP-WHT-04`.

### What happens

`PaymentController::store()` branches on `withholding_enabled` **alone**:

```php
if (($validated['withholding_enabled'] ?? false) && ! empty($adjustedAllocations)) {
    … createFromPayment($payment, $document, $this->normalizeWithholdingRate($validated['withholding_rate'] ?? null), …)
```

`withholding_rate: "0"` is a perfectly valid value for the FormRequest rule
(`nullable|numeric|min:0|max:1|regex:/^\d+(\.\d{1,4})?$/`, `PaymentController.php:406`), so it
arrives as the non-null string `"0"`, takes the `$overrideRate !== null` branch of
`createFromPayment()`, and `WithholdingCalculation::calculate()` has **no zero-rate guard** —
`bcmul($gross, '0', 3)` is `0.000`, `bcsub` gives back the full gross.

Observed live (payment of `1000.000` allocated in full, `withholding_enabled: true`,
`withholding_rate: "0"`):

```
WHT-2026-0015 | gross 1000.000 | rate 0.0000 | withheld 0.000 | net 1000.000 | draft
```

`MTP-WHT-04` (`docs/qa/2026-08-01-money-test-plan.md:902`) specifies **"No certificate
created; payment settles at full gross."** The second half holds (payment `completed`, amount
`1000.000`, `unallocated_amount 0.000`, invoice `balance_due 0.000`); the first half does not.

### Why it matters

A withholding certificate is a **fiscal document** — it is TEJ-exportable
(`downloadTEJXML` / `downloadBatchTEJXML`), PDF-printable, and `issue()` writes it into the
withholding hash chain. A stream of `0.000` certificates is a stream of fictitious tax
documents that a Tunisian filing would have to explain. It also inflates
`generateCertificateNumber()`'s per-year sequence, so the real certificates carry
non-contiguous numbers.

### Suggested fix

Skip certificate creation when the effective rate is zero — guard on
`bccomp($rate, '0', 4) === 0` at `PaymentController.php:917` (or inside
`createFromPayment()` before `certificateRepository->create()`), returning the payment
unchanged. Note the certificate is **not deletable afterwards** (see #6), so the guard must
be preventive.

---

## #2 — P2: sales-withholding **tracking** and withholding **certificates** are disjoint surfaces

**Where:** `apps/api/app/Modules/Taxation/Application/Services/SalesWithholdingTrackingService.php:28-52`
(writes `sales_withholding_tracking` only) vs
`apps/api/app/Modules/Taxation/Application/Services/WithholdingCertificateService.php`
(the only writer of `withholding_certificates`, and its `createFromPayment()` hard-codes
`WithholdingDirection::PURCHASE`, `:191`).

**Tripwire:** `MTP-WHT-03` — pins today's `0` certificates for a partner with three recorded
sales-withholding rows; goes red when the two are joined.

### What happens

`POST /documents/{id}/record-withholding` creates a tracking row and nothing else. Nothing in
the codebase creates a `direction = sales` certificate: the payment path always writes
`PURCHASE`, and the direct-create path (`POST /withholding/certificates`) is a manual admin
action that the tracking flow never calls.

`MTP-WHT-03` (`docs/qa/2026-08-01-money-test-plan.md:901`) reads "Σ withheld matches the sum
of the individual **certificates** exactly" — literally unsatisfiable today, because the
tracking screen has no certificates behind it. The spec therefore asserts the invariant that
IS meaningful on this surface (Σ over the tracking rows is the exact sum of the parts, and
Σ expected-receivable == Σ gross − Σ withheld — verified exact at scale 3) and tripwires the
disjointness separately.

### Why it matters

Two independent ledgers of "tax withheld from us" that can never be reconciled against each
other. `certificateNumber` on a tracking row is free text typed by a human in the
"mark certificate received" modal — it is not a foreign key to anything.

---

## #3 — P1: withholding-certificate routes carry **no authorization middleware at all** *(and the sibling withholding **rules** group is ungated too)*

**Where:** `apps/api/app/Modules/Taxation/routes.php:48-59` (certificates group), **plus**
`routes.php:38-45` (withholding **rules** group — see scope note below). Compare the
neighbouring `sales-withholding` group (`:62-69`), which correctly carries
`can:invoices.view` / `can:invoices.update`, and the `taxation/configurations` group
(`:20-32`).

**Tripwire:** `MTP-WHT-05`.

### What happens

Every route in the `withholding/certificates` prefix — `index`, `show`, `store`, `issue`,
`void`, `submitTEJ`, `downloadPDF`, `downloadTEJXML`, `downloadBatchTEJXML`, `destroy` — is
protected only by `auth:sanctum` + tenant scoping. The FormRequests all `return true` from
`authorize()` (`CreateWithholdingCertificateRequest.php:22-25`,
`VoidCertificateRequest.php:11-14`), and there is no policy. The `withholding.view` /
`.create` / `.update` / `.delete` permissions exist
(`database/seeders/RolesAndPermissionsSeeder.php:365-368`) and the **frontend** gates on them
(`apps/web/src/routes/index.tsx:1862-1890`, `RequirePermission permission="withholding.view"`),
so the gate is FE-only. This is a rule-12 both-layers violation.

Observed live as `cashier@pharmabio.tn` (permissions contain **no** `withholding.*`):

| probe | expected | observed |
|---|---|---|
| `GET /withholding/certificates` | 403 | **200** (full list: partner names, amounts, GL codes, hash-chain fields) |
| `POST /withholding/certificates` | 403 | **201** — created `WHT-2026-00xx`, gross `1000.000`, withheld `15.000` |
| `POST /withholding/certificates/{id}/void` | 403 | **422** (`reason` min-length) — i.e. the request reached FormRequest **validation**, proving nothing refused it on authorization |

The void probe deliberately submits an invalid (too short) reason: a `can:` middleware would
have refused at 403 **before** validation ran, so 422 is the positive proof of absence — and
unlike a valid void it mutates nothing, leaving the probe certificate deletable.

### Why it matters

- `issue()` writes the certificate into the **withholding fiscal hash chain**
  (`WithholdingHashChainService`) under the acting user's identity.
- `submitTEJ()` records a tax-authority submission reference.
- `downloadPDF` / `downloadTEJXML` / `downloadBatchTEJXML` stream partner identity, VAT
  numbers, amounts and chain entries to anyone with a tenant login.
- `destroy()` hard-deletes drafts.

A POS cashier can fabricate, sign and "file" a tax document. Within-tenant privilege
escalation, not cross-tenant — tenant scoping itself is correct
(`requireTenantScopedCertificate()`).

### Scope widening (task review I2, 2026-08-04)

The sibling **withholding rules** group (`routes.php:38-45`) is also ungated and MUST be
covered by the same fix lane, or it stays open after a certificates-only fix:

- `deactivate` (`routes.php:43`) and `destroy` (`routes.php:44`) have **no authorization at
  all** — bare `string $id` parameters, company scoping only
  (`WithholdingTaxRuleController.php:117`, `:132`). Any authenticated tenant user can
  deactivate or delete a withholding **tax rate rule**.
- `store` / `update` are saved only by FormRequest `authorize()`
  (`CreateWithholdingRuleRequest.php:16`, `UpdateWithholdingRuleRequest.php:16` →
  `taxation.withholding_rules.manage`) — no route-level gate, i.e. one accidental
  `return true` away from the same hole.

### Suggested fix

Certificates group: add `can:withholding.view` to the read routes, `can:withholding.create`
to `store`, `can:withholding.update` to `issue` / `void` / `submitTEJ`,
`can:withholding.delete` to `destroy` — mirroring the `sales-withholding` group two lines
below. Rules group: add route-level `can:` middleware (matching the
`taxation.withholding_rules.manage` permission the FormRequests already reference) to ALL of
`:38-45`, including `deactivate` and `destroy`.

---

## #4 — P1: the withholding-certificates **list page is permanently empty** (double-unwrap)

**Where:** `apps/web/src/features/withholding/api/withholdingApi.ts:29-49`
(`fetchWithholdingCertificates`) + `apps/web/src/features/withholding/WithholdingCertificatesList.tsx:80`.

**Tripwire:** `MTP-WHT-01` (settled 200 list response + empty state visible + zero rows —
flips red when the unwrap fix makes the row render).

### What happens

```ts
// withholdingApi.ts — declared return type is a lie
export async function fetchWithholdingCertificates(…): Promise<{
  data: WithholdingCertificate[]; meta: …; links: …;
}> {
  …
  return apiGet(url);            // apiGet ALREADY returns response.data.data
}
```

`apiGet` unwraps one level (`apps/web/src/lib/api.ts:225-228`,
`return response.data.data`), so the promise resolves to the certificate **array**, not the
`{data, meta, links}` envelope. The component then does:

```tsx
const certificates = data?.data ?? []   // array.data === undefined -> []
```

so the table renders `EmptyState` — "No withholding certificates found" — regardless of how
many certificates exist. Verified live: the same request the page makes returns 15 rows via
the API, and the page renders its empty state (page snapshot captured in the failing run).

This is exactly the pattern `docs/conventions/01-API-RESPONSES.md` / CLAUDE.md rule 14 warns
about. Note the endpoint is genuinely cursor-paginated (`meta` + `links`), so the fix is the
documented one for paginated endpoints: use `api.get` and return `response.data` (keeping
`meta`/`links`), not `apiGet`.

### Why it matters

`/treasury/withholding-certificates` is the ONLY screen for the withholding certificates the
payment flow creates. Nobody can see, issue, void, print or TEJ-export a single certificate
from the UI. Detail (`/treasury/withholding-certificates/:id`) works — but the only in-app
link to it is the list row that never renders. `MTP-WHT-02` had to navigate by URL.

---

## #5 — P3 (minor, **no tripwire**): `formatPercent` trims the trailing zero on the rate

`WithholdingCertificateResource` emits `rate_percentage` as the exact 2dp string `"1.50"`
(`WithholdingCertificate::getRateAsPercentage()` = `bcmul($rate,'100',2)`), but
`formatPercent()` → `roundDecimalString()` (`apps/web/src/lib/format.ts:249-290`) drops
insignificant trailing zeros, so both the list and the detail screen read **`1.5%`**, not
`1.50%`.

Arithmetically identical, and percentages are explicitly NOT currency-scaled (precision
contract rule 19), so this is cosmetic — recorded, not tripwired. `MTP-WHT-02` accepts either
rendering (`/Rate \(1\.50?%\)/`) rather than pinning a today-value that a padding fix would
turn red. The money on the same card (`1,000.000` / `15.000` / `985.000`) is exact at scale 3.

---

## #6 — P2: `DELETE` on a payment-linked certificate 500s and leaks the SQL + tenant DB name

**Where:** `apps/api/app/Modules/Taxation/Presentation/Controllers/WithholdingCertificateController.php:324-344`
+ `EloquentWithholdingCertificateRepository::delete():149-159`.

`destroy()` catches only `\DomainException` (the "only drafts can be deleted" guard). Any
certificate created by the payment flow is referenced by
`payments.withholding_certificate_id`, so `$certificate->delete()` raises a
`QueryException` that escapes uncaught:

```
HTTP 500
SQLSTATE[23503]: Foreign key violation: … violates foreign key constraint
"payments_withholding_certificate_id_foreign" on table "payments" …
(Connection: tenant, Host: 127.0.0.1, Port: 5433, Database: tenant019fbe86-…, SQL: delete from …)
```

Two problems: (a) the 500 payload leaks the connection name, host, port, tenant database name
and the raw SQL — **qualification (task review I3, 2026-08-04): the leak only occurs with
`APP_DEBUG=true` (`config/app.php:42`; the local stack has it true, staging/production should
have it false — verify).** The unconditional parts are the raw 500 itself and the
undeletability; the disclosure half is debug-gated, so severity on staging/prod is lower than
the treasury-gate "leaky 422" this originally compared itself to. (b) a payment-linked draft
certificate is **undeletable through the API at all**, which is why `MTP-WHT-04`'s cleanup
retires its junk certificate by **voiding** it instead. Should be a 409/422 with a code such
as `CERTIFICATE_LINKED_TO_PAYMENT`.

---

## #7 — P3 (minor, **no tripwire**): `common:actions` resolves to an object — **10 sites, 6 features**

`WithholdingCertificatesList.tsx:185` renders `{t('common:actions')}` as the screen-reader
label of the actions column, but `apps/web/src/locales/*/common.json` defines `actions` as an
**object** (`{save, cancel, delete, edit, …}`), not a string. i18next emits the literal
fallback, so the accessible name of the column header is:

```
key 'actions (en-US)' returned an object instead of string.
```

(Captured verbatim in the page snapshot.) The correct key is `common:actionsLabel` or a leaf
such as `common:actions.edit` — whichever the sibling list screens use.

**Scope widening (task review M5, 2026-08-04):** the identical misuse exists at **10 sites
across 5 feature directories** — `WithholdingCertificatesList.tsx:185`,
`SalesWithholdingTrackingPage.tsx:145`, `WithholdingRulesPage.tsx:117`,
`CouponListPage.tsx:165`, `PromotionListPage.tsx:147`, `VoucherListPage.tsx:206`, plus 4
parapharmacy screens. Fix once, globally, not per-screen.
