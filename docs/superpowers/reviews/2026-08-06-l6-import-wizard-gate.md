# L6 gate — BUG-004 import-wizard error masking (import-pipeline lens)

- **Commit under review:** `a8c2c9ff8` — "fix(import): BUG-004 — stop reporting every upload failure as 'invalid file'"
- **Branch:** `fix/client-bugs-partners-fe` (worktree `/Users/houssamr/Projects/syneriva/apps/erp.fix-l6-partners`), 4 commits on `origin/dev @ fe0df479e`
- **Scope of THIS review:** the `parseHeaders` error path only. Frontend conventions gated separately.
- **VERDICT: spec ✅ (taxonomy is correct as far as it goes) + quality CHANGES-REQUESTED** (3 required items, all small)

---

## What I verified (not taken on trust)

| Claim | Verified? | Evidence |
|---|---|---|
| Bare `catch {}` mapped everything to `parseError` | YES | pre-fix `ImportWizardPage.tsx` @378-380 (`git show a8c2c9ff8^`) |
| 422 is the sole parse-failure status from the controller | YES | `apps/api/app/Modules/Import/Presentation/Controllers/MigrationWizardController.php:55-58` |
| ...but the controller ALSO emits a 500 | YES | `MigrationWizardController.php:42-44` — `'Failed to store file'`, 500. Correctly lands in `serverError` now. |
| Laravel mime/max validation also 422s | YES | `MigrationWizardController.php:34-36` (`mimes:csv,txt,xlsx,xls`, `max:10240`) → `bootstrap/app.php:204-215` |
| Red→green on 5 new cases | PARTIALLY — see F6 | Reverted `ImportWizardPage.tsx` to `a8c2c9ff8^`, re-ran: **3 failed** (500, 413, network), 2 passed. Restored, `git status` clean, byte-identical. |
| Tests pass on HEAD | YES | `npx vitest run src/features/import/__tests__/ImportWizardPage.uploadErrors.test.tsx` → 5/5 pass, 1.98s |
| ESLint clean on the touched files | YES | 0 errors, 21 warnings — all pre-existing (e.g. `429:54` is `handleMappingComplete`, untouched) |
| ar/import.json has no `wizard` block | YES | parsed all three locales; `en`/`fr` both carry `parseError` + `serverError` + `networkError` with distinct copy; `ar` has no `wizard` key at all |
| Blast radius = 1 catch site | YES | `ImportWizardPage.tsx` contains exactly one `catch` / one `toast.error` (line 406 / 414). Diff = 4 files, no staging/mapping/commit surface touched. |

---

## Rulings on the three questions posed

### Q1 — should 413 get its own key? **YES — REQUIRED.**

Reachability is genuinely low from the in-repo stack:
- client-side cap 10 MB — `ImportWizardPage.tsx:559-562` (`maxSize={10 * 1024 * 1024}`) + `FileUpload.tsx:33-37`
- nginx cap 64 M — `apps/api/docker/nginx/nginx.conf:48`, `apps/web/docker/entrypoint.sh:106`
- and BUG-001 was never a 413: its root cause was `client_body_buffer_size 128k` spooling into a mode-700 `/var/lib/nginx/tmp` → **nginx 500** (`docs/bug-reports/2026-08-05-client-bugs.md:15`). The new taxonomy already handles that correctly.

I still rule REQUIRED, for one reason that is about the fix's own thesis rather than about 413 frequency: the `serverError` copy asserts a **cause the frontend does not know** —

> "The server rejected the upload (HTTP {{status}}). **Your file is probably fine** — try again, and contact your administrator if it keeps failing." (`apps/web/src/locales/en/import.json:120`)

For a 413 that sentence is affirmatively false: the file is exactly the problem, and "try again" is guaranteed to fail. Re-asserting a wrong cause is the defect class BUG-004 exists to eliminate — swapping one wrong story for another is a partial fix. Cost is ~3 lines + 2 strings. And the layer that produces 413s (Traefik/Dokploy/Cloudflare in front of prod) is **not in this repo** and is being provisioned right now by the production-environment workstream, so the "unreachable" argument is unreachable-today, not unreachable-by-construction.

### Q2 — is status-only mapping a half-fix, given the body carries a discriminator? **A discriminator EXISTS, but status-only is ACCEPTABLE for merge.** Follow-up, not a blocker.

The two 422 flavours are distinguishable:
- validation 422 → `{"error":{"code":"VALIDATION_ERROR","message":…,"errors":{…}}}` — `bootstrap/app.php:204-215`
- parse-failure 422 → `{"error":"Could not parse file. Please upload a valid CSV or Excel file."}` — `MigrationWizardController.php:56-58`, a **bare string**, which violates the `{error:{code,message}}` envelope every other handler in `bootstrap/app.php` uses.

Why it is nonetheless acceptable today: the only divergent 422 is `VALIDATION_ERROR`, and both of its triggers are blocked client-side before the request — wrong extension via `FileUpload.tsx:26-31`, oversize via `FileUpload.tsx:33-37`. I checked the boundary: client `10 * 1024 * 1024` = 10,485,760 B and Laravel `max:10240` KB = 10,485,760 B — **exactly equal, no gap band**. And if a `mimes` 422 ever did slip through, "upload a valid CSV or Excel file" is correct guidance anyway.

The follow-up (F4) matters because the equality above is undocumented and unpinned: raise the server's `max:` or lower the client's `maxSize` and the gap band opens, at which point an oversize file gets "Could not read the file. Please upload a valid CSV or Excel file." — BUG-004 reinstated for the most common large-import case.

### Q3 — where do CSRF/419 and auth/401 land? **In generic `serverError`, and that is a bad operator experience. Fixing 401/419 is REQUIRED — it outranks 413 on reachability.**

- **401:** `bootstrap/app.php:151-159` emits the `{error:{code:'UNAUTHENTICATED'}}` envelope → `api.ts:175-183` → `handleUnauthorized` → `store.logout()` + `redirect('/login')` (`api.ts:107-111`). The AxiosError then propagates, so the wizard fires `serverError:401`: the operator is being yanked to the login screen while a toast tells them "your file is probably fine … contact your administrator". Session expiry mid-wizard is routine (`parseHeaders` alone carries a 120 s timeout, `importApi.ts:118`). This is the single most reachable wrong-cause case in the new taxonomy.
- **419:** there is **no** render handler for `TokenMismatchException` anywhere in `bootstrap/app.php` (I read all handlers: Authentication / AccessDenied / ModelNotFound / Validation / Voucher+Refund domain / DomainException), so the body is Laravel's default `{"message":"CSRF token mismatch."}` — no `error` key. `isApiError` (`api.ts:50-56`) requires `response.data.error !== undefined`, so it returns **false**, and the interceptor's 419 auto-retry at `api.ts:191-202` **never runs**. The commit message lists "CSRF bounce" as a handled case; in reality a 419 is neither retried nor explained — the operator just sees `HTTP 419`. (The dead retry branch is pre-existing and out of this diff's scope; the wizard-side mapping is not.)

---

## Findings

### [Important] F1 — 401/419 render as a generic infra error with wrong guidance
`apps/web/src/features/import/pages/ImportWizardPage.tsx:65-67` — every non-422 status collapses into `serverError`. A session expiry (401, with an in-flight redirect to `/login` from `api.ts:107-111`) or a CSRF bounce (419, unretried — see Q3) tells the operator "Your file is probably fine — try again, and contact your administrator". Why it matters: the correct action is "sign in again and re-upload"; "contact your administrator" sends a routine session timeout into the support queue — the exact cost BUG-004 was filed to stop. Fix: `if (status === 401 || status === 419) return t('wizard.upload.sessionExpired')` + `wizard.upload.sessionExpired` in `en`/`fr` ("Your session expired. Sign in again, then re-upload the file.").

### [Important] F2 — 413 inherits "Your file is probably fine", which is false for 413
`apps/web/src/locales/en/import.json:120` / `fr/import.json:120`, reached via `ImportWizardPage.tsx:66`. Why it matters: see the Q1 ruling — the fix replaces one wrong cause with another for the one status where the file *is* the cause, and prod's fronting proxy (outside this repo) is the layer that emits it. Fix: `if (status === 413) return t('wizard.upload.tooLarge')` + a key naming the 10 MB limit and pointing at splitting the export / asking the admin about upload limits.

### [Important] F3 — the `networkError` branch is correct only by accident; a natural cleanup of `isApiError` silently reinstates BUG-004
`ImportWizardPage.tsx:60-62` relies on receiving a real `AxiosError` with `response === undefined`. It does — but only because `isApiError` (`apps/web/src/lib/api.ts:50-56`) demands `response.data.error !== undefined` and therefore returns `false` for a no-response error, which makes the interceptor's

```ts
if (!response) { return Promise.reject(new Error('Network error')) }   // api.ts:167-168
```

**dead code**, letting the AxiosError through untouched at `api.ts:210`. Repair `isApiError` into a plain axios guard — an obvious, tempting cleanup — and line 168 activates, every network failure becomes a bare `Error`, `axios.isAxiosError` returns false, and the wizard silently falls back to `parseError`. All 5 new tests stay green, because they mock `../api/importApi` and never traverse the interceptor. Why it matters: a one-line change in a shared file re-opens the exact bug, with a green suite. Fix (either is acceptable): delete the unreachable `api.ts:167-168` so the contract "the interceptor never substitutes the AxiosError" is explicit, **or** have `describeUploadFailure` also recognise the sentinel; plus one test that drives the real `api` client through an axios adapter stub rather than mocking `importApi`.

### [Important] F4 — 422 discriminator exists but is unusable, and the parse-failure body breaks the API envelope
`MigrationWizardController.php:43` and `:56-58` return `{'error': '<string>'}` while every handler in `bootstrap/app.php` returns `{'error': {'code', 'message'}}`. Consequences: (a) the FE cannot key off `error.code` for the parse case because there isn't one — it can only infer from the *absence* of a code, which is fragile; (b) `getErrorMessage()` (`api.ts:61-73`) returns `undefined` for these responses since `data.error.message` doesn't exist on a string, while `isApiError` still asserts the `AxiosError<ApiError>` type — a live type lie for any future caller. Fix (follow-up ticket, backend): emit `{'error':{'code':'PARSE_FAILED','message':…}}` (and `STORAGE_FAILED` for the 500), then have the wizard branch on `error.code` instead of status. Also pin the client/server size equality noted in Q2 with a shared constant or a test.

### [Minor] F5 — the new "try again" guidance is not actionable through the file picker
`ImportWizardPage.tsx:415-416` clears the page's `selectedFile`, but `FileUpload` keeps its own copy (`FileUpload.tsx:22`, chip rendered at `:133-141`) and its `<input type="file">` (`FileUpload.tsx:125-131`) never resets `value` — neither in `handleInputChange` (`:88-95`) nor in `clearFile` (`:96-99`). Re-selecting the *same* file therefore fires no `change` event, so the retry the new copy instructs is a no-op unless the operator drag-drops or reloads. Pre-existing, but the fix's own message now depends on it. Fix: `e.target.value = ''` after `handleFile(file)` in `handleInputChange`.

### [Minor] F6 — "5 new tests red-first" is true for 3 of the 5; the other 2 are regression guards
Verified empirically by reverting `ImportWizardPage.tsx` to `a8c2c9ff8^`: the 500 / 413 / network cases fail, the two `parseError` cases pass before and after. That is the correct design (they pin the *unchanged* behaviour), but the commit message's "5 new cases; the 500 / 413 / network paths all asserted `parseError` before the fix" reads as if all five were red. Cosmetic; no code change needed.

### [Minor] F7 — mocked `t` means a typo'd or missing i18n key cannot fail the suite
`ImportWizardPage.uploadErrors.test.tsx:24-28` stubs `react-i18next` to echo the key, so the tests assert *keys*, never rendered strings, and there is no i18n key-parity guard in the repo (`apps/web/tools/` has design-system / tanstack / quantity / pos-cache audits — none for locales). `wizard.upload.severError` would pass green and ship a raw key to the operator. I confirmed manually that `en` and `fr` both carry all three keys with three distinct strings (`ar` has no `wizard` block at all — pre-existing, inherits `en`, matches the commit message). Fix: assert key existence against the real `en/import.json`, or resolve through the real i18n instance and assert `new Set([...]).size === 3`.

---

## Things I checked that are FINE (recording so they aren't re-litigated)

- **Mocking convention:** all mocks are component-level (`importApi`, the query hooks, child components, `sonner`, `react-i18next`) — permitted. No fabricated axios *success* payloads.
- **Rejection-shape fidelity:** the hand-built `{isAxiosError: true, response: {...}}` objects DO match what the real client rejects with today, for all three cases. I traced it: an nginx HTML 500 body makes `isApiError` false (`api.ts:50-56`), so the interceptor skips its whole block and re-rejects the AxiosError unchanged at `api.ts:210` (`AxiosError instanceof Error` → true). Same for the no-response case. No test/production divergence *today* — see F3 for why that is fragile.
- **No interceptor crash on an nginx HTML body:** I suspected `response.data.error.message` at `api.ts:206` would throw a TypeError on a string body and swallow the AxiosError. It cannot — `isApiError` gates that block and requires `data.error !== undefined`. False alarm, retracted.
- **`console.error` of the raw error** (`ImportWizardPage.tsx:413`) — no `no-console` rule fires; genuinely useful for support.
- **Blast radius:** zero. One catch site, one helper, two locale keys. Staging/mapping/commit error surfaces untouched.
- **Import-pipeline semantics** (opening balances, number normalization, TTC/HT/margin, opening stock, `imports.manage` gating): not touched by this commit — confirmed by the 4-file diff.

## One line to fix before merge
Add `sessionExpired` (401/419) and `tooLarge` (413) keys to `describeUploadFailure`, and pin the `networkError` branch against the dead `api.ts:167-168` reject so a future `isApiError` cleanup can't silently restore the masking.

---

# Round 2 — re-verification of `34a920e13`

- **Commit:** `34a920e13` "fix(import): import gate F1/F2/F3 — stop the taxonomy asserting causes it cannot know"
- **Scope re-checked:** the three required items, the `serverError` copy, the catalog tests, the boundary comment, blast radius. Nothing else re-litigated.
- **VERDICT: CLEAR TO MERGE (imports half).** All three required items land correctly. Four Minor residuals below, none blocking; two are already ticketed (T7/T8).

## Required items — status

### F1 (401/419 → sessionExpired) — LANDED, verified
`ImportWizardPage.tsx:88-90`. Copy: "Your session expired. Sign in again, then re-upload the file." (`en/import.json:122`, `fr:122`). Red-first confirmed empirically — see the table below.

**Answer to the redirect-race question, which splits by status:**
- **419 has no race.** `handleUnauthorized` is called only for `status === 401` (`api.ts:175-183`), so a 419 never redirects. The toast is the operator's *only* feedback, and it now correctly says "session expired" instead of `HTTP 419`. Fully visible, unambiguously better. The classification is also self-consistent with the interceptor: `isApiError` (`api.ts:50-56`) returns false for Laravel's bare `{"message":"CSRF token mismatch."}`, the whole interceptor block is skipped, and the raw AxiosError re-rejects at `api.ts:210` with `status === 419` intact.
- **401 does race, and the toast usually loses.** `redirect` is `window.location.assign(path)` (`api.ts:91-93`), invoked synchronously at `api.ts:111` *before* the rejection propagates — a full document navigation that tears down the SPA and the sonner `<Toaster>`. The wizard's `catch` then fires `toast.error` in a microtask, so on the first 401 in a document the `sessionExpired` toast will very likely never be read. This does **not** make the change wrong: (a) the classification is correct regardless, (b) the message the operator needed ("sign in again") is delivered by the redirect itself, and (c) `redirectedToLogin` is a module-level once-guard (`api.ts:88`, `:109-111`), so a *second* 401 in the same document does not redirect — and there the toast is the only feedback and the fix is load-bearing. Recorded as Minor R2-3, not a blocker; the redirect behaviour is `api.ts`-owned.

### F2 (413 → tooLarge) — LANDED, verified
`ImportWizardPage.tsx:91-93`. Copy: "The file is too large for the server to accept. Split it into smaller files, or ask your administrator to raise the upload limit." (`en:123`) — names the cause the FE *can* establish and gives an action that can succeed. The superseded `413 → serverError:413` case was correctly deleted rather than left contradicting the new one.

### F3 (network sentinel) — LANDED, and the deviation is CORRECT — **it is an improvement on my relayed wording, not a compromise**
`ImportWizardPage.tsx:46-56` (`INTERCEPTOR_NETWORK_ERROR` + the coupling doc-comment) and `:97-99` (the check).

Confirming explicitly, since I'm asked to: **yes, this is what my record meant.** Round 1 offered two acceptable forms — delete the unreachable `api.ts:167-168`, *or* "have `describeUploadFailure` also recognise the sentinel". This is the second, verbatim. The implementer's argument for narrowing to the exact sentinel rather than "any bare `Error`" is not merely defensible, it is *more* correct than the wording relayed to them: an `any-bare-Error → networkError` rule would classify a genuine client-side throw (the `TypeError('headers is not iterable')` case) as a network failure — which is itself asserting a cause the frontend cannot know, i.e. the exact defect class this whole gate exists to eliminate. That case still asserts `parseError` and still passes.

**Does it make the accident survivable? Yes.** I re-checked both ends of the coupling:
- `api.ts:168` constructs `new Error('Network error')`; the constant is `'network error'` and the comparison is `error.message.toLowerCase() === INTERCEPTOR_NETWORK_ERROR` (`ImportWizardPage.tsx:97`) — matches.
- The axios branch now returns exhaustively (a new `return t('wizard.upload.parseError')` at `:95` closes it), so the sentinel check is reached only by non-axios errors. No overlap, no reordering hazard.
- So if the media lane repairs `isApiError` into a plain axios guard and `api.ts:167-168` activates, the bare `Error` that then arrives classifies as `networkError` — behaviour preserved across the change, which is precisely what the accident needed.

## Red-first — verified empirically, claim is accurate

Reverted `ImportWizardPage.tsx` + `en/import.json` + `fr/import.json` to `34a920e13^`, keeping the new tests:

```
× 401 → sessionExpired          × 419 → sessionExpired
× 413 → tooLarge                × network sentinel → networkError
× catalog en (sessionExpired missing)   × catalog fr (sessionExpired missing)
× serverError not-"probably fine"
✓ 500 → serverError   ✓ network (no response)   ✓ 422 → parseError   ✓ TypeError → parseError
Tests  7 failed | 4 passed (11)
```

All seven new/changed assertions are genuinely red on the parent; the four carried-over cases stay green (correct — they pin unchanged behaviour). On HEAD: **11/11 pass**. Files restored, `git status` clean.

## Catalog tests — yes, this is the assertion I wanted

`ImportWizardPage.uploadErrors.test.tsx:216-247`. It resolves all five keys from the **real** `en`/`fr` `import.json` and asserts `new Set(messages).size === 5`. That is exactly the discrimination property from the retro principle, and it closes Round 1's F7 (a typo'd key could previously ship raw because `t` is stubbed to echo).

Worth stating why the composition is sound rather than just the Set assertion: the component tests pin **branch → key** (with negative assertions that the *other* keys were not used), and the catalog tests pin **key → distinct real string**. Together those compose to "each branch produces a distinct operator-visible message" without needing a full i18n-instance render. The extra `serverError` guard (`:238-247`) — `not.toMatch(/probably fine/i)` plus `toContain('{{status}}')` — is a good touch: it pins both the removal of the false claim and the retention of the diagnostic code.

## Minor residuals (none blocking)

### [Minor] R2-1 — the sentinel coupling is one-directional and can break silently
`ImportWizardPage.tsx:56` matches a string literal owned by `api.ts:168`. If the media lane edits that message text (e.g. to "Network request failed"), the coupling breaks with **no test failure anywhere** — the wizard test constructs its own `new Error('Network error')`, so it keeps passing while production silently degrades. The degradation is to `parseError`, i.e. today's behaviour rather than something worse, which is why this is Minor. Mitigation: export the sentinel from `api.ts` and import it (2 lines, but touches a file this lane must not), or note the coupling in the media lane's ticket. See R2-2.

### [Minor] R2-2 — the `api.ts` interceptor root cause has no artifact in the repo
The coordinator states the 419 auto-retry defect is assigned to the media lane that owns `api.ts`. I could not verify that assignment: grepping `docs/` finds `isApiError` mentioned only in passing inside T7 (`2026-08-06-l6-partners-followups.md:85`, about the `AxiosError<ApiError>` type lie), and nothing anywhere records (a) that the 419 auto-retry at `api.ts:191-202` is unreachable, or (b) the sentinel coupling from R2-1. A verbal lane assignment with no written ticket is how this batch's originating bug survived for days. Suggest a one-paragraph ticket before merge or immediately after.

### [Minor] R2-3 — the 401 `sessionExpired` toast is usually destroyed by the redirect
See the F1 answer above. `api.ts`-owned; no change wanted in this lane. Noted so nobody later "fixes" the 401 branch believing the toast is what the operator reads.

### [Minor] R2-4 — the "probably fine" regression guard is `en`-only
`ImportWizardPage.uploadErrors.test.tsx:238-247` asserts only against `en`. The `fr` copy was correctly updated too (verified: `grep -c "probablement correct" fr/import.json` → 0), but nothing prevents a future `fr` retranslation from reintroducing the claim. One extra `it.each(['en','fr'])` with a per-locale forbidden-phrase list would close it.

### [Minor] R2-5 — one new lint warning, in the test file only
`ImportWizardPage.uploadErrors.test.tsx:224` — `@typescript-eslint/no-unsafe-type-assertion` on the dynamic JSON-import cast. `ImportWizardPage.tsx` is unchanged at 21 warnings, all pre-existing; 0 errors across both files. Acceptable for a dynamic `import()` of JSON in a test.

## Re-confirmed unchanged

- **Blast radius still nil.** 4 files; `api.ts` untouched (verified — the commit's file list is the wizard page, its test, and the two locale catalogs). No staging/mapping/commit error surface touched. No opening-balance / number-normalization / price-resolution / opening-stock / `imports.manage` code in the diff.
- **F4 → ticketed as T7** (`PARSE_FAILED` typed code). **F5 → ticketed as T8** (same-file re-select is a no-op). Both carried faithfully.
- **Boundary comment** at `ImportWizardPage.tsx:594-609` states the equality I verified in Round 1 (client `10*1024*1024` B == server `max:10240` KB == 10,485,760 B) and names the exact failure mode if it diverges. This is the right artifact — the risk was that the equality was undocumented, not that it was wrong.
- **F6/F7** (Round 1 Minors) — F7 is now closed by the catalog tests; F6 was cosmetic and the new commit message describes its red-first set accurately.

## One line
Nothing blocking — file a short ticket for the `api.ts` interceptor residuals (419 dead retry + the `Error('Network error')` sentinel coupling) so the media lane inherits them in writing rather than verbally.
