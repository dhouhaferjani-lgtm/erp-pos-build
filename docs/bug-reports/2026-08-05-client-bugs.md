# Bug Report — Client-reported bugs (2026-08-05) — Handoff for fixing

> **Audience:** H.Rihane's Claude Code session. This file is self-contained: symptoms, evidence-backed root-cause analysis (file:line, verified on branch `dev` @ `6f14f8232`), proposed fixes, and acceptance criteria.
> **Reported by:** K.Ben Sabeur (client feedback) · **QA / analysis:** D.Ferjani · **Assignee:** H.Rihane
> **Environment:** https://erp.otospex.dev (Dokploy staging, deploys automatically from GitHub `otospexsolutions/erp` branch `dev`, ~3 min build)
> **Repo rules that apply (CLAUDE.md):** TDD (red → green), strict typing (no `any`/`mixed`), i18n via `t()` for all user-facing text, design tokens for Tailwind colors in touched code, `./scripts/preflight.sh` before completion, module boundaries via `Shared/Contracts`.
> ⚠️ Root causes below are **proposals from static code analysis** — confirm each with a failing test before fixing.

---

## Context: already fixed this week (do not re-fix, but relevant background)

| ID | What | Fix |
|---|---|---|
| BUG-001 | All uploads > 128 KB → bare nginx 500 (imports + product photos). Alpine nginx ships `/var/lib/nginx/tmp` owned `nginx:nginx` mode 700 while workers run as `www` (`apps/api/docker/nginx/nginx.conf:5`) → EACCES spooling client bodies > `client_body_buffer_size 128k`. | `chown -R www:www /var/lib/nginx` in `apps/api/Dockerfile` — commit `8a60a863f`, branch `fix/api-nginx-client-body-perms`, deployed 2026-08-04. |
| BUG-002 | Windows-1252 CSV (default French Excel export) → 500 at import staging. | Per-value UTF-8 conversion `toUtf8()` in `apps/api/app/Modules/Import/Services/SpreadsheetParserService.php` — commits `230074b55..bca8dfbe6`, branch `fix/import-cp1252-encoding`, deployed 2026-08-04. Regression test: `tests/Feature/Import/MigrationWizardTest.php::test_api_parses_headers_from_cp1252_encoded_csv`. |

---

## BUG-005 — Product photo shows as broken after upload; onboarding page then returns 500

**Severity:** Major · **Proposed branch:** `fix/product-image-url` (+ `fix/onboarding-500` if split)

### Symptom
1. User uploads a photo on the product form → the preview/hero shows a broken-image icon.
2. After saving the product, the onboarding page (`/settings/setup`) shows a 500 error.

### Part A — broken photo: TWO independent, confirmed root causes

**A1 (HIGH confidence): `primary_image_url` is built with the POS URL builder → absolute, Bearer-auth-gated URL that `<img>` cannot authenticate → 401 → broken icon.**
- `apps/api/app/Modules/Catalog/Application/Queries/CatalogMediaQuery.php:47-52` maps attachments with `MediaUrlResolver::forPosSync($a, 'sm')`; lines 56-62 pick the primary one as `primary_image_url`. (A comment there even admits the SPA should use `forAttachment()`.)
- `apps/api/app/Modules/Media/Application/Services/MediaUrlResolver.php:117-130` — `forPosSync()` → `route('products.images.download', …)` = **absolute** URL on the API host, behind `auth:sanctum` + `can:products.view` (`apps/api/app/Modules/Product/routes.php:44,144,160-161`). An `<img>` tag can't send the Bearer header (`apps/web/src/lib/api.ts:139-142`), and the SPA talks same-origin through the web proxy (`baseURL: '/api/v1'`, `apps/web/src/lib/api.ts:124`) so no cookies exist for `api.erp.otospex.dev` either → 401.
- By contrast the gallery works: it uses `forAttachment()` (`MediaUrlResolver.php:52-88`) — a **relative HMAC-signed** URL to `media.serve` (60-min TTL), consumed by `apps/web/src/features/products/components/ProductImageGallery.tsx:104-105`.
- Frontend surfaces using the broken URL: `apps/web/src/features/products/editor/components/ProductEditHero.tsx:68,84`, `ProductHero.tsx:24`, variant appended in `apps/web/src/features/products/productHeroImage.ts:1-5` (⚠️ appending `?variant=md` to an already-signed URL would invalidate the HMAC → pass the variant into the resolver instead).
- Aggravating: **no `trustProxies()` / `URL::forceScheme()`** anywhere in `apps/api/bootstrap/app.php:48-113` → absolute URLs are minted `http://` → mixed content blocked on an HTTPS page (second independent breakage of the same `<img>`).

**A2 (HIGH confidence): freshly uploaded images are status `UPLOADED`, every read path requires `READY`, and the 201 response returns a URL that 404s until the queue worker runs.**
- `apps/api/app/Modules/Media/Application/Services/MediaUploadService.php:143-144` creates assets as `MediaStatus::Uploaded`; only the queued `GenerateRenditions` job (queue **`images`**, `apps/api/app/Modules/Media/Application/Jobs/GenerateRenditions.php:87-89`) promotes to `READY`.
- `apps/api/app/Modules/Catalog/Presentation/Controllers/ProductMediaController.php:143,350` — `store()` returns 201 **with a resolved URL** anyway.
- `SignedMediaController.php:177` — non-`READY` → **404**. List endpoint + catalog query also hide non-READY rows (`ProductMediaController.php:329`, `EloquentMediaAttachmentRepository.php:33-37`).
- Frontend invalidates the query **once, immediately** (`ProductImageUpload.tsx:31-35`) — no refetch after the worker finishes.
- ⚠️ **If the Horizon `images` supervisor is not consuming on dev** (`apps/api/config/horizon.php:209`, worker defined in `docker-compose.staging.yml:205`), images stay `UPLOADED` forever → permanently broken. This matches the "worker problems" already reported by the other QA. **Check this first.**

**Proposed fix (A):**
1. In `CatalogMediaQuery`, resolve `primary_image_url` with `forAttachment($primary, 'md')` (relative signed URL, like the gallery). Keep `forPosSync()` strictly for the POS sync payload (its Tauri cache key is frozen — do not touch it).
2. Remove the `?variant=` string surgery in `productHeroImage.ts`; pass the variant to the resolver.
3. Add `->trustProxies(at: '*')` in `bootstrap/app.php` so minted absolute URLs are `https`.
4. Status machine: either mark image assets `READY` on upload (serve already falls back to the original — `MediaStorageAdapter.php:65-78`) and let `GenerateRenditions` only add renditions — **smaller change, removes the "worker down = no images ever" failure mode** — or keep the state machine and have the FE poll (`refetchInterval`) while any attachment is non-READY.
5. Ops: verify the Horizon `images` supervisor is running on dev.

**Acceptance criteria (A):** upload a > 200 KB JPEG on the product form → preview renders immediately (no broken icon); `primary_image_url` in the product payload is a **relative** URL; feature test asserting the product payload's `primary_image_url` targets `media.serve` (not `products.images.download`); existing POS sync payload unchanged (regression test).

### Part B — onboarding 500

**What the page is:** `/settings/setup` → `apps/web/src/features/settings/pages/SetupChecklistPage.tsx` → single API call `GET /api/v1/onboarding/status` (`apps/web/src/features/settings/api/onboardingApi.ts:11-12`). Backend: `apps/api/app/Modules/Tenant/routes.php:34-37` → `OnboardingController.php:29-37` → `OnboardingChecklistService.php:22-49`.

**B1 (MEDIUM-HIGH): zero fault isolation.** `OnboardingChecklistService::getStatus()` (`apps/api/app/Modules/Tenant/Application/Services/OnboardingChecklistService.php:26-49`) runs 7 uncaught DB checks across 5 modules (Company :51-68, Treasury :70-85, POS :87-92, Product :94-99, Catalog `product_attributes` :101-111). Any single failure — missing table on a tenant whose migration lane is behind (matches the reported failed migrations), transient PG error — 500s the entire page.

**B2 (MEDIUM): non-`User` principals are a guaranteed 500.** `CompanyContextMiddleware.php:44-56` skips context for principals that aren't `App\Modules\Identity\Domain\User` (e.g. super-admin guard); `OnboardingController::status()` then calls `CompanyContext::requireCompanyId()` which throws `\RuntimeException` (`app/Modules/Company/Services/CompanyContext.php:46-52`) → unhandled 500.

**B3 (HIGH, shapes the error UX): no catch-all JSON renderer.** `apps/api/bootstrap/app.php:114-320` renders only typed exceptions; anything else falls to Laravel's default `{"message":"Server Error"}` — and the SPA interceptor dereferences `data.error.message` unconditionally (`apps/web/src/lib/api.ts:61-73,186,205-206`) → `TypeError` inside the interceptor, garbage error text, poisoned Sentry.

**Proposed fix (B):**
1. Wrap each checklist step in `try/catch (\Throwable)` → log (step key + company id), degrade that step to `completed: false` + `degraded: true` (~15 lines). One broken module must never blank the page.
2. In `OnboardingController::status()`, return the 403 `NO_COMPANY_ACCESS` envelope when `CompanyContext` has no company instead of throwing.
3. Add a catch-all `$exceptions->render()` for `api/*` returning `{"error":{"code":"INTERNAL_ERROR","message":…,"request_id":…}}`; harden `getErrorMessage()` to fall back `data?.error?.message ?? data?.message ?? error.message`.
4. Diagnosis shortcut: pull the Sentry event for the client's request (API tags `company_id`/`tenant_id`/`url` — `bootstrap/app.php:119-149`) to confirm which hypothesis fired.

**Acceptance criteria (B):** feature test — checklist still returns 200 with a degraded step when one module's table is missing; super-admin token on `/onboarding/status` → 403 JSON, not 500; unknown exception on an API route → JSON error envelope.

---

## BUG-006 — Partner created as "Client" also appears in the Fournisseurs list

**Severity:** Major · **Proposed branch:** `fix/partner-list-type-filter`

### Symptom
Create a partner, pick **Client** → it shows up in the **Fournisseurs** list too. Expected: Client → customer only; Fournisseur → supplier only; Both → both.

### Root cause (HIGH confidence): **displayed wrong, NOT stored wrong**
- The data model is a single enum `type: customer|supplier|both` (there are no `is_customer`/`is_supplier` booleans anywhere). The create payload is correct: `PartnerForm.tsx:84,186-190,204,394-434` sends `{ type: "customer" }`; backend stores it verbatim (`CreatePartnerRequest.php:44` enum rule, `PartnerController.php:199-203`, no default/mutator on `Partner.php:91-95`). Backend list scoping is also correct (`PartnerController.php:53-65`: `customer → whereIn(type,[customer,both])` etc.).
- The defect is in the shared list page: **Clients and Fournisseurs are the same component** (`apps/web/src/routes/index.tsx:520` `partnerType="customer"`, `:767` `partnerType="supplier"` — same lazy `PartnerListPage`). React-router v7 renders route elements **without keys**, and the two routes produce structurally identical trees → React **reconciles instead of remounting**. `useTableState` consumes `defaultFilters` **only in the `useState` initializer** (`apps/web/src/hooks/useTableState.ts:69-81,114`), so the filter stays frozen at `{ type: 'customer' }`; and the guard at `apps/web/src/features/partners/PartnerListPage.tsx:149-153` only fills a **missing** `type`, never corrects a **stale** one.
- Net effect: navigating Clients → Fournisseurs, the Fournisseurs page issues `GET /partners?type=customer` and renders customers (symmetric in the other direction; the URL-sync effect then writes `?type=customer` onto `/purchases/suppliers`, making it sticky/bookmarkable).
- Same staleness freezes the create form's type select when navigating between "Nouveau client" and "Nouveau fournisseur" (`PartnerForm.tsx:204`) — second manifestation, same fix.

### Proposed fix
1. `PartnerListPage.tsx:149-153`: make the route filter authoritative — `if (partnerType) queryParams['type'] = partnerType` — and include `partnerType` in the react-query key (remember: tenant-data keys must use `tenantScopedKey([...])`).
2. Belt-and-braces: `key={partnerType}` on the route elements (`routes/index.tsx:520/530/550/767/777/797`) to force a real remount — this also fixes the frozen form select.
3. Longer term: `useTableState` should re-seed `filters` when `defaultFilters` changes.

### Acceptance criteria
Regression test: navigating Clients → Fournisseurs issues `GET /partners?…type=supplier`; a partner with `type=customer` never renders in the suppliers view; the create form's type select follows the route context.

---

## BUG-007 — No delete button for clients / fournisseurs

**Severity:** Minor (UX) · **Proposed branch:** `feat/partner-delete-button`

### Symptom
No way to delete a partner from the UI — neither in the list nor on the detail page.

### Root cause (HIGH confidence): **missing frontend wiring — backend is complete**
- Backend: `DELETE /partners/{partner}` exists — `apps/api/app/Modules/Partner/routes.php:51-53`, permission `partners.delete` (admin-only: `permissionsMap.generated.ts:142`), soft delete (`PartnerController.php:323-355`, `Partner.php:88`), emits `PartnerDeleted`, returns 204, covered by `tests/Feature/Partner/DeletePartnerTest.php`.
- Frontend: **zero** delete references in `apps/web/src/features/partners/` — no button (`PartnerListPage.tsx:380-406` row actions = quote/invoice/view only; `PartnerDetailPage.tsx:348-354` ends at Edit), no mutation, no API method (pages call `api.get()` inline; there is no partnersApi module). Nothing is permission-hidden — the control simply doesn't exist.
- Also missing: no `is_active` toggle in `PartnerForm.tsx` (the "soft" alternative), though `UpdatePartnerRequest.php:103` accepts it.
- Secondary backend gap: `destroy` has **no business guard** — an admin can delete a partner that has invoices/open balance with no warning.

### Proposed fix (follow the existing pattern exactly)
1. Copy the `ProductDetailPage.tsx` pattern (delete mutation :126-138, handlers :139-147, Trash2 button :309-312, `ConfirmDialog` :383-392 with `variant="danger"`): add a Delete button on `PartnerDetailPage.tsx` next to Edit, `useMutation` → `api.delete('/partners/' + id)`, on success invalidate via `partnersInvalidationPredicate(tenantId, companyId)` (`apps/web/src/features/partners/_invalidation.ts:9-23`) and `navigate(basePath)` (returns to the correct clients-vs-fournisseurs list).
2. Gate with `usePermissions().hasPermission('partners.delete')` so non-admins never see a control that would 403.
3. Optionally mirror a Trash2 icon in the list row-action cell (`PartnerListPage.tsx:381-406`).
4. Add i18n keys (partners namespace) — no hardcoded strings.
5. Recommended: backend guard returning 409 `PARTNER_HAS_DOCUMENTS` when related documents/treasury rows exist + test in `DeletePartnerTest.php`; expose the `is_active` toggle in `PartnerForm.tsx`.

### Acceptance criteria
Admin sees Delete on the partner detail page, confirm dialog, 204 → redirected to the right list, partner gone from list; non-admin sees no button; (if guard added) partner with documents → 409 with a translated toast.

---

## Carried-over FE follow-ups from the 2026-08-04 campaign (also assigned)

### BUG-003 — Product form "Enregistrer" silently blocked (Major/UX) — proposed branch `fix/product-form-scroll-to-error`
Clicking Save fires zero network requests, no toast, no scroll. Cause (proven via Playwright repro): client-side validation blocks on the **required "Product Category *"** field in the *Parapharmacy* section, below the fold, surfaced only as inline text. Fix: on blocked submit → error toast + scroll-to-first-invalid-field; reconsider whether the field should be required for all verticals.

### BUG-004 — Import wizard masks all server errors as "invalid file" (Minor/diagnostic) — proposed branch `fix/import-wizard-error-messages`
`apps/web/src/features/import/pages/ImportWizardPage.tsx:~378`: bare `catch {}` around `importApi.parseHeaders(file)` maps **every** error (500/413/network/CSRF) to `wizard.upload.parseError`. Fix: distinguish HTTP/network failure (show status + retry/contact-admin message) from real parse errors. This masking cost us days on BUG-001/002.

---

## Process notes for the fixing session

- Work TDD: write the failing test that reproduces the root cause **first** (red), then fix (green). PHPUnit runs on in-memory SQLite (`phpunit.xml`).
- Branch off `origin/dev`; land with a clean fast-forward `git push origin <branch>:dev` (a `dev-push-guard` hook checks the LOCAL `dev` ref — run `git fetch origin dev:dev` as a **separate** command first).
- Run `./scripts/preflight.sh` (PHPStan L8, Pint, PHPUnit, tsc, ESLint) before calling anything done.
- Verify end-to-end on dev after deploy (~3 min after push): photo upload > 200 KB, `/settings/setup`, Clients↔Fournisseurs navigation, partner delete.
