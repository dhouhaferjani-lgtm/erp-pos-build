I reviewed M1 (T1 / CX-4) at round 3. Round 3's fix is documentation-only (`72c0207b4`, `4c847a414`); the tool and tests are unchanged since round 2. Everything below was reproduced by running the tool and reading source.

## Independently reproduced

`cd apps/web && node tools/audit-listing-census.mjs` → **exit 0, 2.4s**, no network. 46 listings / 271 route records / 27 raw orphan candidates split 15 view / 4 parameterized / 8 action-form. Every distribution in the report's JSON block (`17-listing-census-recensus.md:33-70`) reproduces byte-identically from my own run (pagination 18/3/1/24, empty 17/2/26/1, filters 1/16/13/3/2/11, `ListPageLayout` 12, `DataTable` 35). `node --test` 12/12, `pnpm exec vitest run` 12/12, `eslint` clean on both files. `git diff --name-only 04c96be26..HEAD` = 2 tool files + 5 docs — **zero `apps/web/src`, zero `apps/api`**, nothing in the DO-NOT-TOUCH set. No wiring into `lint`, `preflight`, or `.github/`.

**Lenses.** `frontend-conventions`: only the tooling subset applies (exported-function shape, test co-location, no lint/CI chaining) — passes; tokens/RHF/i18n/`tenantScopedKey` are N/A since no production component changed. `general`: applies. Rule 19, tenancy/authz, constructor injection, migrations, Horizon queues, en+fr strings: **N/A** — no backend, no runtime UI, no user-facing strings.

## Round-2 findings — disposition (independently checked)

| R2 | Status | Evidence |
|---|---|---|
| #1 P2 five action/form rows misattributed | **Resolved** | All five re-attributed and each verified: `documentTypeToPath` at `DocumentListPage.tsx:46-54`, `basePath` at `:148`, `addPath` ternary at `:434`, navigated at `:432`/`:497`; mounts pass `documentType` explicitly (`routes/index.tsx:640,726,1197,1239`). `/income/:id/edit` ← `LinePanel.tsx:45` `provenanceLink()` rendered at `:92`. The wrong stated cause ("opaque callback values or server-originated links") and the "only unconstrained prefix templates remain excluded" claim are both gone |
| #2 P2 broken provenance pointer | **Resolved** | `:19` now cites `02-design-system-consistency.md:66`, which reads verbatim "every `.tsx` under `src/features/**` matching `*ListPage \| *ListView \| *QueuePage \| *IndexPage`. 46 files." |
| #3 P3 prop-union undisclosed | **Resolved** (with a new inaccuracy — finding 2) | `:27` now documents the union and its over-marking risk |
| #4 P3 handback fidelity | **Resolved for counts**, softened for timing | 12/12 verified both runners; timing → finding 4 |
| #5 P3 unreproducible 33 | **Not resolved** — see finding 1 |
| #6/#7 P3 red-first, ordinal | Carried, unchanged |

## Findings register

**1. P2 — CONFIRMED — `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md:25`**
The remediation for round-2 #5 asserts "A **conservative** secondary-surface **cross-check finds 33** additional pages" and then states a rule. I implemented that rule literally, twice — once taking every JSX tag in `src/routes/index.tsx`, once taking only tags inside `element={…}` — intersected with production `src/features/**` files containing `<DataTable`, minus the 46-file cohort, minus `*Detail*`/`*Form*`/`Create*`. **Both give 35, not 33** (the delta is `SupplierInvoiceCreatePage` and `WithholdingCertificatesList`; reading `Create*` as a substring gets 34, and additionally requiring a `Page` suffix gets 33 — neither qualifier is in the stated rule). The rule is under-specified at two points ("route-mounted component names" has no extraction method; `Create*` is prefix-or-substring ambiguous), and it is the only number in the report given without a runnable command.
*Failure scenario:* the report's premise is method-per-number, and this is the one sentence that newly claims reproducibility. A Wave-3 planner re-runs the rule, gets 35, and either under-scopes the secondary surface by two pages or discounts the whole census — the exact credibility loss the CX-4 re-census exists to repair. Non-blocking per the harness (P2 = close-before-merge), but it must be carried into the handback's open-items list, not dropped.

**2. P3 — CONFIRMED — `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md:27`**
"Literal `basePath` props are also unioned by JSX component name… **That bounded approximation found seven real links** at this base." It found four. The three partner rows (`/purchases/suppliers/new`, `/purchases/suppliers/:id/edit`, `/sales/customers/:id/edit`) come from a different mechanism — a local `ConditionalExpression` binding resolved at `audit-listing-census.mjs:609-618` (`PartnerListPage.tsx:86`, `PartnerDetailPage.tsx:160`), not from `collectStaticJsxPropBindings` (`:668-706`), which only reads JSX `basePath` attributes. The orphan-section sentence at `:142` states it correctly ("conditional partner base paths **and** literal `basePath` props"); the Method paragraph collapses both into the prop-union pass.

**3. P3 — CONFIRMED — `17-listing-census-recensus.md:60-70` and `:170`**
The five-row correction was taken report-level only (permitted — round 2 named it as the minimum), so the tool still emits `action_or_form: 8 / total: 27` and no test pins the five now-known links. The gap is disclosed at `:27`, but it is not durable: anyone re-running the deliverable in T9 or Wave 3 gets 27/8 back with no in-band signal that five are known false. The manual check is also asymmetric — the report says "all raw **action/form** candidates receive the manual call-flow check", and never states whether the 15 view / 4 parameterized rows got one. I ran that check myself and the substance holds (see bypasses), but the disclosure does not say so.

**4. P3 — CONFIRMED — `17-listing-census-recensus.md:15`, `HANDBACK-ui-wave0-2026-08-11.md:132`**
"completed in **1–5 seconds** across recorded executor and bridge runs." Recorded measurements are 1.06s (round 1), 1.16s (round 2), 2.4s (mine). The range's upper bound is the original disputed "roughly five seconds" point-claim, for which no run is cited. Widening a claim to contain an unsupported figure is not the same as reconciling it with the measurements.

**5. P3 — carried, unchanged — `751255324`**
Greenfield red-first remains degenerate: the tool and its 12 tests land in one commit; the only recorded red is `ERR_MODULE_NOT_FOUND`. Structurally hard to avoid for a new file, and both fix rounds did add genuinely non-vacuous regression tests (each fails against the prior implementation by construction). Recorded, not charged.

**6. P3 — carried, parent's call — report path**
The `17-` ordinal still collides with the owner's `17-research-open-question-best-practices.md`, which I confirmed exists in the main checkout at `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/`. The path was mandated by the brief; the parent renumbers at merge. (Note `docs/sessions/` is `.gitignore:58` — the report is tracked by force-add, consistent with the other tracked files in that tree.)

## Bypasses attempted that FAILED to find a defect

- Tried to falsify each of the five "confirmed reachable" re-attributions — all five hold against source, including the subtlety that `addPath` keys off the `documentType` **prop** while `basePath` keys off `effectiveType`; every mount passes `documentType` explicitly, so the credit-note branch really does yield `/create`.
- Tried to show the three remaining action/form rows are actually reachable — no inbound production reference exists for `/expenses/:id/edit`, `/inventory/replenishment/new`, or `/sales/credit-notes/new`. `LinePanel.tsx:44` builds `/expenses/:id/**view**`, a different route.
- Tried to break the UI-34 seven-view claim via non-literal path construction (object maps, local functions, fully-dynamic templates): grep for each of the seven segments returns zero production hits, and only two fully-dynamic first-segment templates exist in the whole app (`ReturnNoteDetailPage.tsx:167`, `Breadcrumb.tsx:49`), neither of which can reach them. Same result for the four M3 deletion targets, `/growth`, `/growth/modules`, `/inventory/delivery-notes/consolidate`, `/finance/lane-separation`, the three `/channels/:id/*` rows, and the four compliance rows.
- Tried to make `/inventory/return-notes/:id` reachable — `entityRoutes.ts:92` builds `/sales/return-notes/${id}`, a separately mounted route (`routes/index.tsx:784` vs `:1255`).
- Tried to find lint/CI/preflight wiring, a non-zero exit path, a `package.json` change, or scope creep into `apps/web/src` or `apps/api` — none; `main()` sets `process.exitCode = 0` even on scan failure (`audit-listing-census.mjs:828-834`).
- Could not run the full `apps/web` suite (owner prohibition on full-suite runs). The handback's full-run figures are recorded as **unverified, not disputed**.

No P1. Both round-2 P2 blockers are genuinely closed, verified against source rather than against the handback. One new P2 (finding 1) is a close-before-merge evidence defect that does not change any decision the report drives; per the harness (`SELF-REVIEW-HARNESS.md:72-73`) it does not block the milestone, and it must be carried in the handback's open items for the parent's terminal audit.

VERDICT: ACCEPT
