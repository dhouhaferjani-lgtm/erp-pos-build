# Adversarial merge gate r1 — teammate PR #213 (`fix/crm-validation-hardening`)

- **Date:** 2026-09-05
- **PR:** #213 — `fix(crm): validation hardening — birthdate, email case, contact linking, i18n keys`
- **Author:** dhouhaferjani-lgtm (co-authored Claude Opus 4.8) · base `dev`
- **Head commits:** `8cb122c5d` (i18n keys, DEV-QA-053), `d25a21fdb` (birthdate, DEV-QA-054), `362f7c00e` (e2e spec)
- **Reviewed tree:** merged worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-213`, branch `gate/pr-213` = local dev `d56d62535` + PR head, merge commit `f9aae5bd9`
- **Diff:** 9 files, +312/-4 (`git diff dev HEAD`)
- **Reviewer:** Fable (orchestrator gate), read-only — no code modified, nothing merged.

## VERDICT: **MERGE**

Both confirmed fixes are real, minimal, correct on both layers, and covered by falsifiable tests that pass on
sqlite AND PostgreSQL. Both "false positive" claims are verified in code. All findings below are MINOR or
INFO — none blocks the merge; F3/F7 are follow-ups the orchestrator should book, not fix rounds.

**Merge to local dev: YES.**

---

## 1. Birthdate (DEV-QA-054)

**Rule shape — verified.**
- `apps/api/app/Modules/Contact/Presentation/Requests/CreateContactRequest.php:40`
  `'date_of_birth' => ['nullable', 'date', 'before_or_equal:today']`
- `apps/api/app/Modules/Contact/Presentation/Requests/UpdateContactRequest.php:29`
  `'date_of_birth' => ['sometimes', 'nullable', 'date', 'before_or_equal:today']`

Nullable handling is preserved on both (create `nullable`, update `sometimes|nullable`) — an absent or null DOB
is still accepted. `before_or_equal` (not `before`) is the semantically correct boundary for a date of birth:
a person born today is valid.

**FE mirror — same boundary, same layer semantics.**
- `apps/web/src/features/crm/pages/ContactFormPage.tsx:26` `const todayIso = () => new Date().toISOString().slice(0, 10)`
- `:37-40` zod `.refine((val) => val === '' || val <= todayIso(), 'crm:contacts.validation.dateOfBirthFuture')`
- `:249` native `max={todayIso()}` on the `type="date"` input (placed before `{...register(...)}`, which spreads
  only `name/onChange/onBlur/ref` — no override, verified against `Input` at
  `apps/web/src/components/atoms/Input/Input.tsx:33`)
- `:244` `error={errors.date_of_birth?.message ? t(errors.date_of_birth.message) : undefined}` and `:251`
  `error={!!errors.date_of_birth}` — `FormField.error?: string` and `Input.error?: boolean` are the correct
  prop types (`FormField.tsx:19`, `Input.tsx:7`). Matches the existing `first_name` / `email` fields exactly
  (`ContactFormPage.tsx:206`, `:230`), so no new pattern is introduced (docs/conventions/06-FORMS.md).

The lexicographic `<=` on two `YYYY-MM-DD` strings is a valid date comparison; `<input type="date">` only ever
emits `YYYY-MM-DD` or `''`, and the edit-mode `reset` feeds it a server `Y-m-d` string
(`ContactData.php:61` `date_of_birth: $contact->date_of_birth?->format('Y-m-d')`), so no format drift.

**Timezone — consistent, with a named residual (see F1).** `config/app.php:68` sets `'timezone' => 'UTC'`, so
Laravel's `today()` and the FE's `toISOString()` resolve the same calendar day. The layers agree with each
other; both can disagree with a UTC+1/+2 operator's local "today" (F1).

**422 envelope — verified.** `tests/Traits/AssertsApiValidation.php:33-57` asserts `assertUnprocessable()` plus
`$json['error']['errors'][<key>]` — i.e. the `{error:{errors}}` envelope, matching CLAUDE.md rule 14 /
docs/conventions/01-API-RESPONSES.md. Both new backend tests use it.

## 2. Email case (DEV-QA-051 — claimed false positive: **CONFIRMED false positive**)

The question "is lowercasing applied consistently on create AND update" has a prior answer: **no normalisation
of any kind exists, and none was added.** Neither `CreateContactRequest` nor `UpdateContactRequest` declares
`prepareForValidation()` (full files read; the diff touches one line each).

- **No unique check to make case-insensitive.** `contacts`:
  `apps/api/database/migrations/tenant/2026_03_10_100001_create_contacts_table.php:38` — the only email key is a
  plain **index** `['tenant_id', 'email']`; the table has **no unique constraint at all**, no `citext`, no
  `lower()` expression index.
- `partners`: `apps/api/database/migrations/tenant/2025_11_30_052119_create_partners_table.php:33-34` — uniques
  are `(tenant_id, code)` and `(tenant_id, vat_number)` only; email is not keyed.
- Partner FormRequests: `CreatePartnerRequest.php:85` / `UpdatePartnerRequest.php:103` —
  `['nullable','email','max:255']`, no `unique`.
- No `customers` table exists (`ls database/migrations/tenant | grep -i customer` returns only
  `add_customer_category_to_partners`, `customer_history_searches`, `pos_customer_aliases`,
  `backfill_customer_and_supplier_advance_accounts`).

Consequences for the gate questions:
- **Existing mixed-case rows cannot collide** — there is nothing to collide against, and no value is rewritten.
- **Rule 22 / ratchet:** the diff adds no unique key and no migration, so there is no new ratchet exposure.
  `contacts` is **not** in `CATALOGUE_TABLES`; the only contact-family entry in
  `tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:129` is `party_contacts`, in
  `EXCLUDED_TABLES` with the reason *"Contact links and the primary marker are scoped by their company-owned
  party."* Contacts are **company-scoped by column** (`company_id` NOT NULL, FK to `companies`,
  migration `:19`), not tenant-global.
- **No API contract change.** The public request/response shape is unchanged; only an added refusal on an
  already-existing field. `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` lives in the **parent** repo and governs
  the platform↔ERP published contract; `/api/v1/contacts` is an ERP-internal tenant endpoint, so the root
  CLAUDE.md rule 9 does not apply. **Could not verify** whether `erp-mobile` posts contacts with a DOB (that
  repo is not in this checkout) — if it does, a future-dated DOB now 422s there too, which is the intent.

The ticket's *underlying* operator complaint (duplicate customers by email slipping through) is therefore
**unimplemented rather than fixed** — see F7.

## 3. Contact linking (DEV-QA-055 — claimed false positive: **CONFIRMED false positive**)

- **FK validated:** `party_id` → `partners` (the `Partner` module owns the `Party` concept; table name is
  historical — docs/glossary.md:26).
- **Company-scoped, `ScopedExists`-style:** `CreateContactRequest.php:44-48` and
  `app/Modules/Contact/Presentation/Controllers/ContactController.php:250-259` both use
  `['required'|'nullable', 'uuid', ScopedExists::tenantAndCompany('partners', $tenantId, $companyId)]`.
  `app/Shared/Presentation/Validation/ScopedExists.php:28-36` adds `->where('tenant_id')->where('company_id')`,
  so a cross-company / cross-tenant id fails the exists rule → **422, not 500**.
- **PG 22P02 guard present:** the `'uuid'` rule precedes the exists rule, so a non-UUID string is rejected by
  the validator before any `where('id', <text>)` reaches PostgreSQL — no `invalid input syntax for type uuid`
  500. (The `Str::isUuid()` memory pitfall is covered here by the `uuid` validation rule.)
- **`party_kind` — not applicable today.** `grep -rn "party_kind" app/ database/migrations` returns **nothing**:
  the Session H Phase 2 nature column (docs/glossary.md:26, owner-ruled 2026-08-29) has not landed. So a
  person-party can be linked as a contact's organization and nothing can detect it. This is a pre-existing
  platform gap, not introduced here; it becomes an enforcement point when Phase 2 lands.
- **The refutation evidence is the integration test, not the e2e spec.**
  `tests/Feature/Contact/PartyContactLinkingTest.php` — 4/4 green on both engines here (link, unlink, primary
  constraint, duplicate prevention). It does **not** cover a cross-company `party_id` (untested branch of
  `ScopedExists` on this route) — noted, not a blocker.

## 4. i18n

- New keys added to **en** (`apps/web/src/locales/en/crm.json:35-39`) and **fr**
  (`apps/web/src/locales/fr/crm.json:35-39`): `contacts.validation.{firstNameRequired,invalidEmail,dateOfBirthFuture}`.
- **`ar` is covered by aliasing — claim verified.** `apps/web/src/lib/i18n.ts:429` the `ar` resource block reads
  `crm: enCrm` (the same object as `en`, line 194), so `ar` serves the new keys in English immediately. No
  `ar/crm.json` exists. The shrink-only completeness gate is green with **no new findings** (verbatim below);
  `crm` is one of the 21 English-aliased `ar` namespaces it reports.
- **All user-facing text via `t()`** — the messages live in the bundles; the zod schema carries key *literals*
  (`'crm:contacts.validation.*'`) resolved through `t(...)` at render (`ContactFormPage.tsx:206,230,244`),
  which is the file's pre-existing convention. `eslint` (incl. `no-untranslated-literal`) is clean on the
  touched files.

## 5. Tests

**Backend — falsifiable.** `tests/Feature/Contact/ContactCrudTest.php:348-374` adds
`test_create_rejects_future_date_of_birth` and `test_update_rejects_future_date_of_birth`, both posting
`date_of_birth: '2999-01-01'` and asserting `assertApiValidationErrors($response, ['date_of_birth'])`. Reasoning
from the diff: on `dev` the rule is `['nullable','date']`, `2999-01-01` validates, the request succeeds (201/200),
`assertUnprocessable()` fails first — RED before, GREEN after. The helper asserts the *specific key* is present,
so a 422 for an unrelated reason would not satisfy it. The update test seeds a real `Contact::create` with the
tenant/company UUIDs from the fixture (rule 17 — real UUIDs, no string literals).
Single-company fixture; per §2, no second-of-everything trio is mandated for `contacts`
(docs/conventions/09-SECOND-OF-EVERYTHING.md — not a catalogue table, no unique key touched).

**Frontend.** `ContactFormPage.test.tsx:176-206` — a future date blocks submit (`mockMutate` not called) and a
past date submits (`toHaveBeenCalledTimes(1)`). The pair is falsifiable: without the zod refine the first test
would see the mutation fire. `contactValidationI18n.test.ts` asserts bundle shape rather than i18next
resolution — see F4.

**E2E spec is invisible to CI — verified three ways:**
- `apps/web/playwright.config.ts` (`testDir: './e2e'`, no `testIgnore`) is **never invoked by any workflow**:
  `.github/workflows/smoke-test.yml:53` runs `--config=playwright.smoke.config.ts`
  (`testDir: './e2e/smoke'`, `testMatch: /.*\.smoke\.ts$/`) and
  `.github/workflows/onboarding-campaign.yml` runs `playwright.campaign.config.ts`
  (`testDir: './e2e/campaign'`, `testMatch: /.*\.campaign\.ts$/`). `crm-contact.spec.ts` matches neither.
- `pnpm test` (vitest) cannot pick it up: `apps/web/vitest.config.ts:12`
  `include: ['src/**/*.{test,spec}.{ts,tsx}', 'tools/**/*.{test,spec}.{ts,mjs}']`.
- ESLint ignores it: `apps/web/eslint.config.js:43-45` `ignores: ['dist', 'e2e/*', '!e2e/campaign', …]`.
It runs only on a manual `pnpm test:e2e`. Locale robustness: every locator regex carries an fr alternative
(`/link company|lier une entreprise/i`, `/search for a company|rechercher une entreprise/i`) or uses ids
(`#first_name`, `#date_of_birth`), so it does not assert hardcoded English. No strict-mode hazard on the
duplicated "Link Company" label: `ContactDetailPage.tsx:181` renders the trigger only `{!showLinkForm && …}`
and `:221` the submit only `{showLinkForm && …}` — exactly one matches at a time.

## 6. Hygiene

| Gate | Result |
|---|---|
| PHPStan L8 (`app/Modules/Contact` + `tests/Feature/Contact`) | **[OK] No errors** |
| Pint `--test` (3 touched PHP files) | `{"result":"pass"}` |
| ESLint (3 touched TS/TSX files) | 0 errors (3 pre-existing warnings on untouched mock lines 40-42) |
| `tsc --noEmit` (apps/web) | exit 0, no output (swap free 1228 MB at run time) |
| `pnpm audit:design-system` | 810 acknowledged, **0 new** |
| `pnpm audit:keys` (tanstack tenant keys) | 0 findings, **0 new** |
| `pnpm audit:i18n:local` | completeness OK, **0 new**, baseline held |
| Design tokens on touched TSX lines | ✅ the added lines carry no color classes (`max=`, `error=` only) |
| Hand-rolled FE type beside a generated DTO | none added (see F9 for the pre-existing one) |
| Route middleware | `app/Modules/Contact/routes.php` untouched — `can:contacts.*` gates intact |
| Scope creep | none — 2 rule lines, 1 FE field, 2 locale blocks, 3 test files. No unrelated files. |

---

## Findings

### F1 — MINOR — "today" is UTC on both layers, not the operator's local day
`apps/web/src/features/crm/pages/ContactFormPage.tsx:26` (`toISOString()`) and Laravel's `today()` under
`apps/api/config/app.php:68` (`'timezone' => 'UTC'`). The two layers **agree with each other** (no FE/BE
mismatch), but for a Tunis/Paris operator between local midnight and 01:00/02:00, UTC "today" is still
yesterday, so a DOB of the local today is refused for a ~1–2 h window each night. Affects only a baby born
today. **Fix (optional, follow-up):** resolve the boundary from the company timezone
(`companies.timezone`) on the backend and from the browser's local date on the FE
(`new Date().toLocaleDateString('en-CA')`) — but only if both are changed together, since the current
agreement is itself the valuable property.

### F2 — MINOR — no plausible lower bound on the birthdate
`CreateContactRequest.php:40` / `UpdateContactRequest.php:29` accept `0001-01-01` or `1500-01-01`; the FE refine
(`ContactFormPage.tsx:37-40`) and `max=` guard likewise only cap the upper end. The gate question asked for it
explicitly. **Fix:** add `'after_or_equal:1900-01-01'` on both requests and a matching `min={'1900-01-01'}` +
refine on the input. Low value, low risk — book it, do not hold the merge.

### F3 — MINOR — the DOB bug class is not closed: loyalty members diverge and have no FE guard
`app/Modules/Loyalty/Presentation/Requests/CreateMemberRequest.php:50` and `UpdateMemberRequest.php:52` already
use `['nullable','date','before:today']` — a **different boundary** (a person born today is a valid contact but
an invalid loyalty member), and `apps/web/src/features/loyalty/pages/MemberFormPage.tsx:24`
(`z.string().optional().nullable()`) has **no** future guard at all, so the FE/BE mismatch DEV-QA-054 described
still exists on the loyalty surface. Fixing it here would be scope creep (CLAUDE.md rule 4), so this PR is
right to leave it — but the ticket should not be closed as "DOB hardened". **Fix (separate lane):** align both
loyalty requests to `before_or_equal:today` and mirror the zod refine + `max=` on `MemberFormPage`.

### F4 — MINOR — the i18n test asserts bundle shape, not resolution, and duplicates the key list by hand
`apps/web/src/features/crm/__tests__/contactValidationI18n.test.ts:12` hardcodes
`REQUIRED_VALIDATION_KEYS = ['firstNameRequired','invalidEmail','dateOfBirthFuture']` and reads the raw JSON
objects. Three consequences: (a) a fourth zod message key added to `ContactFormPage` later is **not** caught —
the list is a manual copy of the schema, not derived from it; (b) nothing asserts i18next *resolution*
(`i18n.t('crm:contacts.validation.x') !== the key`), which is the actual DEV-QA-053 symptom; (c) `ar` is
asserted nowhere — the aliasing at `lib/i18n.ts:429` is true today, but adding an `ar/crm.json` would silently
drop these keys with no test failing. `ContactFormPage.test.tsx` likewise never asserts the *rendered* message
is not a raw key (its `t` mock returns the key). **Fix:** assert through the real i18next instance for
`en`/`fr`/`ar`, and derive the key list from the schema (or from a `grep` of `crm:contacts.validation.` in the
page) rather than restating it.

### F5 — MINOR — the e2e spec overclaims DEV-QA-055 and its link-party mock is never exercised
`apps/web/e2e/crm-contact.spec.ts:186-192` registers a 201 mock for `**/api/v1/contacts/contact-9/link-party`,
then the test never clicks submit — no POST is ever made. It proves only that the *form opens*
(`:196-205`); the last assertion (`a button named /link company/` is visible) is non-discriminating, since the
trigger button carries the same label as the submit button (`ContactDetailPage.tsx:184` vs `:221`). Being
mock-only, it could not refute a server-side bug in any case. The claim in the PR body ("refutes DEV-QA-055")
is carried by the **integration** test `PartyContactLinkingTest` (4/4 green here), not by this spec.
**Fix:** either click the link action and assert the POST fired plus the party row rendered, or downgrade the
PR/ticket wording to "the attach flow is reachable" and cite `PartyContactLinkingTest` as the refutation.

### F6 — INFO — hardening a surface scheduled for retirement
`docs/glossary.md:29`: *"The standalone `/crm/contacts` surface is retired in Session H Phase 4; the only
surface becomes the organization party's Contacts tab."* The FormRequest hardening is permanent and correct;
the `ContactFormPage` zod refine, the `max=` guard and the whole e2e spec live on the retiring surface and will
need re-porting to the Contacts tab. Cheap now, worth booking so the guard is not lost in Phase 4.

### F7 — INFO — DEV-QA-051 is refuted as written, but the operator's problem is undecided
The report ("customer email uniqueness is case-sensitive") is factually wrong — §2 shows there is no email
uniqueness anywhere to be case-sensitive about. But the *complaint behind it* (duplicate customers by email)
is therefore **not implemented at all**, not "already fine". Closing the ticket as a false positive without an
owner ruling risks the tester re-filing it. **Fix:** book an owner question — should partner/contact email be
unique per `(company_id, lower(email))`, and what happens to the existing duplicates? That is a migration +
reconciliation lane, correctly out of this PR's scope.

### F8 — INFO — single-company backend fixture (no rule-22 obligation, but no coverage either)
The two new backend tests run in the file's existing one-tenant/one-company fixture. Per §2 no second-company
leg is *mandated* (contacts are not a catalogue table and no unique key is touched), and a
`before_or_equal:today` rule has no tenancy dimension — so this is correctly not a finding against the diff,
recorded only so a later reader does not re-derive it.

### F9 — INFO — pre-existing rule-11 item, untouched by this PR
`apps/web/src/features/crm/api/contactApi.ts:13` hand-rolls a `Contact` shape beside the generated
`ContactData` at `packages/shared/types/generated.d.ts:606`
(docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md / CLAUDE.md rule 7). The diff neither adds to nor worsens it.

### F10 — INFO — a date of birth cannot be cleared through the form
`ContactFormPage.tsx:146` `if (data.date_of_birth) payload.date_of_birth = data.date_of_birth` — an emptied
date field is omitted from the PATCH, and `UpdateContactRequest` uses `sometimes`, so the stored value
survives. Pre-existing on every field in this `onSubmit`; noted because the DOB field is the one this PR
touches.

---

## Could not verify

1. **The DEV-QA registry is not in this repository.** `grep -rn "DEV-QA" --include="*.md"` over `apps/erp`
   returns only prior gate reviews (`2026-09-05-dhouha-pr-212-gate-r1/r2.md`, `…-pr-214-gate-r1/r2.md`). The
   ticket texts for DEV-QA-051/053/054/055 exist only in the PR body, so the *reported symptoms* were taken as
   quoted by the author and could not be checked against the tester's original wording, severity or repro
   steps. Ask Dhouha for the registry (this is a standing item from the 2026-09-04 audit).
2. **The e2e spec was not executed.** It needs a live Vite dev server on :5173 and a browser; laptop swap was
   at 11.0/12.3 GB throughout the gate (one heavy process at a time). It is not on any CI path (§5), so this
   does not affect the merge decision — the author's "3/3 green" claim is unverified here.
3. **`erp-mobile` is not in this checkout**, so whether the mobile client posts a contact `date_of_birth` (and
   would now receive a 422 on a future date) could not be checked.
4. **Falsifiability of the two backend tests was reasoned from the diff**, not demonstrated by a red run —
   reverting the rule would mean modifying the tree, which this gate does not do. The reasoning is
   deterministic (see §5) and the helper asserts the specific key.

---

## Verbatim outputs

### Backend — sqlite (`./vendor/bin/phpunit <file>`)

```
$ cd .worktrees/pr-213/apps/api && ./vendor/bin/phpunit tests/Feature/Contact/ContactCrudTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-213/apps/api/phpunit.xml

................                                                  16 / 16 (100%)

Time: 00:17.385, Memory: 167.00 MB

OK (16 tests, 91 assertions)
```

```
$ ./vendor/bin/phpunit tests/Feature/Contact/PartyContactLinkingTest.php
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-213/apps/api/phpunit.xml

....                                                                4 / 4 (100%)

Time: 00:06.993, Memory: 163.00 MB

OK (4 tests, 12 assertions)
```

### Backend — PostgreSQL (private DB `autoerp_test_g213` on 127.0.0.1:5433, created and dropped by this gate)

```
$ DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp_test_g213 DB_CENTRAL_DATABASE=autoerp_test_g213 \
    php artisan test -c phpunit-pgsql.xml tests/Feature/Contact/ContactCrudTest.php

   PASS  Tests\Feature\Contact\ContactCrudTest
  ✓ create contact with minimum fields                                  29.47s
  ✓ create contact with all fields                                       3.99s
  ✓ create contact with party linking                                    3.65s
  ✓ update contact                                                       2.88s
  ✓ delete contact                                                       2.42s
  ✓ list contacts                                                        2.34s
  ✓ list contacts with search filter                                     3.12s
  ✓ list contacts with party id filter                                   4.14s
  ✓ show contact                                                         3.90s
  ✓ validation errors missing first name                                 2.71s
  ✓ validation errors invalid email                                      3.10s
  ✓ validation errors invalid gender                                     3.87s
  ✓ create rejects future date of birth                                  3.06s
  ✓ update rejects future date of birth                                  2.93s
  ✓ permission denied without create permission                          3.77s
  ✓ not found for nonexistent contact                                    3.99s

  Tests:    16 passed (91 assertions)
  Duration: 79.43s
```

```
$ … php artisan test -c phpunit-pgsql.xml tests/Feature/Contact/PartyContactLinkingTest.php

   PASS  Tests\Feature\Contact\PartyContactLinkingTest
  ✓ link contact to party                                               43.65s
  ✓ unlink contact from party                                            3.00s
  ✓ primary contact constraint                                           2.90s
  ✓ duplicate link prevention                                            3.18s

  Tests:    4 passed (12 assertions)
  Duration: 52.79s
```

```
$ psql -h 127.0.0.1 -p 5433 -U autoerp -d postgres -c "DROP DATABASE IF EXISTS autoerp_test_g213;"
DROP DATABASE
```

### Frontend — vitest (by file)

```
$ cd .worktrees/pr-213/apps/web && pnpm vitest run \
    src/features/crm/pages/__tests__/ContactFormPage.test.tsx \
    src/features/crm/__tests__/contactValidationI18n.test.ts

 RUN  v3.2.4 /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-213/apps/web

 ✓ src/features/crm/__tests__/contactValidationI18n.test.ts (2 tests) 1ms
 ✓ src/features/crm/pages/__tests__/ContactFormPage.test.tsx (9 tests) 403ms

 Test Files  2 passed (2)
      Tests  11 passed (11)
   Duration  3.64s
```

### i18n completeness gate (shrink-only)

```
$ pnpm -s audit:i18n:local
i18n completeness OK — 55 namespaces, authored keys: en=9504, fr=9521, ar=5146 authored (1924 behind aliases); 2816 known gap(s) held at the baseline.
  English-aliased namespaces — ar: 21 ns / 1924 keys served in English
EXIT=0
```

### Static analysis / style / lint

```
$ ./vendor/bin/pint --test app/Modules/Contact/Presentation/Requests/CreateContactRequest.php \
    app/Modules/Contact/Presentation/Requests/UpdateContactRequest.php tests/Feature/Contact/ContactCrudTest.php
{"result":"pass"}
```

```
$ ./vendor/bin/phpstan analyse app/Modules/Contact tests/Feature/Contact --memory-limit=2G
Note: Using configuration file .../phpstan.neon.
 14/14 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

```
$ pnpm exec eslint src/features/crm/pages/ContactFormPage.tsx \
    src/features/crm/__tests__/contactValidationI18n.test.ts \
    src/features/crm/pages/__tests__/ContactFormPage.test.tsx

.../ContactFormPage.test.tsx
  40:41  warning  Unsafe return of a value of type `any`  @typescript-eslint/no-unsafe-return
  41:42  warning  Unsafe return of a value of type `any`  @typescript-eslint/no-unsafe-return
  42:42  warning  Unsafe return of a value of type `any`  @typescript-eslint/no-unsafe-return

✖ 3 problems (0 errors, 3 warnings)
EXIT=0
```
(lines 40-42 are the pre-existing `vi.mock` block, untouched by the diff — the additions start at line 176.)

```
$ pnpm exec tsc --noEmit
tsc exit=0   (no output; swap free = 1228.88M at run time)
```

```
$ pnpm -s audit:design-system
[sweep-progress] Design-system audit C1-C6 violations: 810
[gate-summary] Design-system baseline: 810 acknowledged, 0 new, 0 stale baseline entries

$ pnpm -s audit:keys
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
```

---

## Merge to local dev: **YES**

Merge `gate/pr-213` into **local** `dev` only (CLAUDE.md rule 21 — promote to `origin/dev` later as a clean
fast-forward, in a verified batch). Nothing was merged by this gate.

**Book as follow-ups, not fix rounds:** F3 (loyalty DOB alignment — the bug class is still open on a second
surface), F7 (owner question on customer/contact email uniqueness), F4 (i18n test asserts resolution, not
bundle shape), F6 (re-port the DOB guard when Session H Phase 4 retires `/crm/contacts`), F2 (lower bound),
F5 (PR/ticket wording for DEV-QA-055). Ask Dhouha for the DEV-QA registry.
