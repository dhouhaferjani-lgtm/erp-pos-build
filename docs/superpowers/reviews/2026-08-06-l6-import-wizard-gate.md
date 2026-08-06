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
