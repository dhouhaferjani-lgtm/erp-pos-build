# RETRO — why the partner/party/contact drift happened, and why we kept building on it

**Date:** 2026-08-23
**Scope:** read-only causal retrospective. Companion to `docs/handoff/AUDIT-parties-partners-disambiguation-2026-08-23.md` (which establishes WHAT is broken). This document establishes WHY, from the commit and document record.
**Checkout:** `/Users/houssamr/Projects/syneriva/apps/erp`, local `dev` tip `a3b2f9ec9`. **Nothing was modified.**
**Trigger:** owner escalation of B-4 (`docs/handoff/OWNER-SHEET-2026-08-21-first-client-session.md:22`) — *"why did this stuff happen in the first place, and why did we keep building on top of it."*

---

## 0. Headline — the causal answer in five sentences

The three waves did not share a cause. **Wave 1 (2025-11-30)** shipped a correct, TDD'd `Partner` module and, sixteen hours later, buried a hand-rolled frontend `Partner` interface — carrying a field the backend has never once emitted — inside a 201-file mega-commit; that field has rendered blank for 267 days. **Wave 2 (2026-03-09..11)** coined "party" as new vocabulary for `partners` inside a 365-file commit whose subject line lists five unrelated features, in a repository that at that moment had no specs directory, no ADR directory, no glossary, no design-review step and no reviewer agents — the entire written record of the vocabulary change is one checked checkbox in a POS roadmap. **Wave 3 (2026-07-03) is the uncomfortable one: it had everything.** A 290-line spec, a 16-task TDD plan, four rounds of adversarial review — and the reviewer *asked the exact right question* ("should the existing `partners` type be extended and relabeled?", `docs/superpowers/audits/2026-07-03-unified-imports-spec-adversarial-review.md:222`), got the answer "keep both", and closed it **Resolved**.

So the answer to "why did we keep building on it" is not *nobody looked*. It is: **every subsequent look was scoped to the artifact in front of it, never to the term underneath it.** Five separate documents saw part of this and each converted it into a smaller, correctly-sized, closeable ticket (§3). Rigor scaled downstream of an undefined word, and an undefined word is the one thing rigor cannot ratchet.

---

## 1. Commit archaeology of the three waves

| | Wave 1 | Wave 2 | Wave 3 |
|---|---|---|---|
| Commits | `d6d1ee90d` 2025-11-30 06:25 · `2937b07d3` 2025-11-30 22:30 | `c82083fe9` 2026-03-09 20:18 (+ `40563ed81`, `b8813020f`) | `5e6e86aec` 2026-07-03 14:46 |
| Size | 18 files / +2,003 · **201 files / +27,830** | **365 files / +33,535 −19,065** (satellite `b8813020f`: 298 files) | **5 files / +520** |
| Shape | one clean module + one mega-commit | mixed mega-commit, 5 concerns in the subject | small, single-purpose, TDD |
| Design doc | none | **none for contacts** (8 `.md` added, all POS) | **spec + plan + 4 review rounds** |
| Review gate in place at that date | CI only | CI only | CI + spec/plan discipline; **imports-reviewer landed 31h later** |
| Vocabulary written down | n/a | **no** | **yes — and the wrong branch was chosen deliberately** |

### 1.1 Wave 1 — the drift is *older than the vocabulary*

`d6d1ee90d` ("feat(partner): implement partners module with full CRUD") is exemplary: 18 files, TDD, "53 tests, 145 assertions… PHPStan level 8 clean". It is not the problem.

`2937b07d3`, the same day at 22:30, is: **201 files, +27,830 lines**, subject *"feat: Add Dashboard API, fix permission middleware, fix document validation"* — a subject that does not mention the frontend at all. Inside it:

* `apps/web/src/features/partners/PartnerListPage.tsx:27` — a hand-rolled `interface Partner { … tax_id: string | null … }`.
* **`tax_id` has never existed on the backend.** `git log -S'tax_id' -- apps/api/app/Modules/Partner/Application/DTOs/PartnerData.php` → **empty**. `git log -S'tax_id' -- packages/shared/types/generated.d.ts` → **empty**. `grep -c tax_id packages/shared/types/generated.d.ts` → **0**. The API has always emitted `vat_number` (`PartnerData.php:37`).
* Therefore the "Tax ID" column on the customers and suppliers lists (`PartnerListPage.tsx:392`) has rendered `-` for **every row, in every tenant, since 2025-11-30** — 267 days.

The causal sting: **CLAUDE.md rule 7 ("Types Flow from Backend") existed at the initial commit.** `git show b01166923:CLAUDE.md` line 46: *"### 7. Types Flow from Backend / Never manually edit TypeScript interfaces for Domain Entities."* The rule and its first violation are **one day apart**, and the violation shipped inside a commit too large to review line-by-line. Rule 7 was never machine-enforced for this direction (§2.3).

### 1.2 Wave 2 — a vocabulary change with a zero-line paper trail

`c82083fe9`, subject: *"feat: product/service separation, POS enhancements, contacts, platform integration, and automotive metadata"* — **five unrelated concerns; "contacts" is the third item in a list.** 365 files, +33,535/−19,065 (the deletions are a mobile-app removal). Inside one commit:

* 13 migrations, including `create_contacts_table`, **`create_party_contacts_table`** (the origin of `party_id → partners.id`), `add_customer_category_to_partners`, `make_loyalty_polymorphic`.
* the whole `apps/web/src/features/crm/` surface
* the Tauri POS scaffold, PlatformIntegration module, automotive metadata, product/service separation

**Design documents accompanying it: none for contacts.** It added 8 `.md` files — `docs/pos/ROADMAP.md`, `docs/tauri-pos/00-…06-ROADMAP.md` — all POS. None designs, names, or defines a Contact or a Party.

**A design-doc mechanism did not exist yet.** `docs/superpowers/` (specs + plans) begins at `c30859ef8` / `5cc441153`, **2026-03-20** — *eleven days after* the wave. `docs/adr/` holds exactly two files, the earliest `2026-04-19-typescript-types-pipeline.md` (committed `b66033043`, 2026-04-25) — six weeks later; `grep -i 'partner|party|contact' docs/adr/` → zero matches.

**Was "party" ever written down?** No.
* No glossary exists in `apps/erp` at all (the only `glossary.md` in the monorepo is `../../claude/glossary.md`, platform-scoped, 57 lines, and it never mentions Partner/Party/Contact).
* `CLAUDE.md` at `c82083fe9` and at HEAD: zero occurrences of "party"/"parties".
* `.claude/context/architecture.md` — the file agents are told to read for module structure — **never names Partner, Contact or Party**.
* `docs/modules/` documents imports, treasury, workshop, POS. There is no Partner or Contact module doc, for the entity 24 tables point at.
* The only written trace of the March wave is a **checkbox**: `docs/pos/ROADMAP.md:126` (added by `b8813020f`, 2026-03-11) — `- [x] Add contact role fields to party_contacts:` — sitting in a checklist whose sibling bullets all concern the **`partners`** table (`company_legal_name`, `credit_limit`, `payment_terms`). One checklist alternates between two names for one entity without a word about why the prefix changes.
* The earliest use of "party" in the repo predates the module and already conflates all three terms: `docs/TAX_IMPLEMENTATION_ANALYSIS.md:311` (`9ee3132ab`, 2026-01-08) — heading `## 3. Party/Customer Tax Status`, whose body opens `### Partner Model Tax Fields`, and `:524` `### Party Tables (Tax-Relevant)` listing exactly one table: `partners`.

**The satellites confirm the hygiene of the period.** `b8813020f` (2026-03-11, *"loyalty point adjustment, transaction history, POS orders, B2B partners, parts catalog"*) is 298 files and commits build artifacts — seven Playwright `test-results/**/error-context.md` files and a `parts-catalog-vin-tab.png`. That is what the review surface looked like when `customer_category` and the B2B field block landed.

### 1.3 Wave 3 — full process, wrong premise

`5e6e86aec` is 5 files, +520, TDD, and it is **the best-governed of the three**. It had:

* spec `docs/superpowers/specs/2026-07-02-unified-imports-design.md` (`bbc6fc625` → `e5d40fc0f`, *"FINAL v3 — owner decisions + industry research + 4-round Codex adversarial review (verdict READY)"*)
* plan `docs/superpowers/plans/2026-07-03-unified-imports-phase0-2.md` (`ef9a3e978`), 16 TDD tasks
* audit `docs/superpowers/audits/2026-07-03-unified-imports-spec-adversarial-review.md`

**And the duplication was not an oversight — it was a signed-off decision.** Spec §3 heading: *"## 3. Dashboard, permissions & legacy handling (DECIDED: keep legacy, hide for parapharmacy)"*. Line 163:

> `ImportType::Parties` is a **new external API value** `parties`; the old `partners` type remains functional and unpromoted.

Line 160 places `partners` (old) in the same "legacy tiles" bucket as the opening-balance batch wizard, the old `opening_balances` CSV, `stock_levels`, `product_images`, `composite_items` — *"Routes and backends stay; nothing is deleted."* Plan Task 10 (`:517`) tasks that demotion explicitly. Risk register `:275`: `| B3 parties API value ripple | Compatibility matrix added (§3); partners stays functional |`.

**The decision's justification does not apply to the artifact it protected.** The spec's research appendix (`:237`) argues, correctly, that the *segmented* flow — journal-level opening balances, aged AR/AP detail, FEC — is the accounting-led migration pattern FR/UK/IT accountants demand, so it must be kept. That reasoning is sound for the opening-balances CSV and the batch wizard. It is **inverted** for `ImportType::Partners`, which has no opening-balance capability at all: `finalizeImport` (`apps/api/app/Modules/Import/Services/ImportService.php:452-459`) routes `Parties`/`Products`/`OpeningBalances` to their phases and drops `Partners` into `default => []`. Keeping `partners` "for the accountant" is precisely what costs the accountant every AR/AP opening, silently.

**A correct ruling made at the family level was applied to a member that did not fit, and never re-checked at the member level.** That is Wave 3's whole story.

### 1.4 When did each gate actually exist?

| Date | Gate |
|---|---|
| 2025-11-29 `b01166923` | CLAUDE.md rules 1–15 (incl. rule 7) — prose only |
| 2025-11-29 | `.github/workflows/ci.yml`: Pint, PHPStan 8, PHPUnit, ESLint, tsc. **No architecture, ratchet, manifest or drift job.** |
| 2026-03-20 | `docs/superpowers/` specs + plans begin — **11 days after Wave 2** |
| 2026-04-25 `b66033043` | ADR mechanism + `types-drift` CI job |
| 2026-06-24 `475f266bc` | `docs/handoff/` begins |
| 2026-06-27 `f026287dd` | **first adversarial reviewer agent** (treasury) |
| **2026-07-04 `020c47199`** | **imports / fiscal-pos / inventory-costing / tenancy-authz reviewers — 31 hours after `5e6e86aec`** |
| 2026-07-11 `e61c5cfe8` | frontend-conventions-reviewer |
| 2026-08-08 `bfafb3510` | `ImportType` deprecation machinery (`deprecationMessage`/`isDeprecated`/`selectable`) — for `StockLevels` |
| 2026-08-11 `c744c19cc` | `scripts/adversarial-review.sh`, `docs/handoff/reviews/` per-milestone gate rounds |
| 2026-08-18 `a933f5e00` | `scripts/adversarial-review-final.sh` (receipt-driven parent-only final gate) |
| 2026-08-19 `8ceb36ac0`, `a8fa2e9af` | `docs/conventions/08-DETECTOR-LIVENESS.md`; feature-lane manifest checker |

Waves 1 and 2 ran under the first two rows. Wave 3 ran under everything above `2026-07-04` — **minus the imports reviewer, by 31 hours.**

---

## 2. The continuation mechanism — why later work built on the dead artifacts

Six mechanisms, each verified. They are ordered by how much of the "we kept building on it" they explain.

### 2.1 The dead module is *fully covered* by CI — that is why nothing failed

The audit's framing ("nothing exercises it, so nothing fails") is **wrong in a way that matters**. The Contact module is comprehensively tested and formally laned:

* `apps/api/tests/Feature/Contact/` — `ContactCrudTest.php`, `ContactTenantIsolationTest.php`, `PartyContactLinkingTest.php`; plus `tests/Feature/Partner/PartnerContactsTest.php`.
* `apps/api/tests/feature-lane-manifest.json:350-357` maps `tests/Feature/Contact/` to lane `feature-lane-documents/Contact`, whole-directory selector.

The tests are green because **every one of them creates its own rows through the module's own API**: `ContactCrudTest.php:80` `test_create_contact_with_minimum_fields`, `:136` `test_create_contact_with_party_linking`, `:159` `assertDatabaseHas('party_contacts', …)`. `PartyContactLinkingTest.php` covers link, unlink, primary constraint, duplicate prevention.

**Every test proves the artifact is internally consistent. No test asks whether anything outside the artifact can reach it.** No test asserts a Contact can appear on a document, be paid, be found from a Partner detail page, or be created by any seeder. So a module with 0 rows in all 8 local tenant DBs, 0 seeders, 0 verticals and 0 billable paths reports as a healthy, laned, passing module on every CI run. Green CI was not silence — it was a **confident false positive**, which is worse, because it is what a later contributor consults.

### 2.2 `customer_category` is NULL by construction, not by neglect — and db-per-tenant guaranteed it

The migration did *not* forget to populate the column. `2026_03_09_100001_add_customer_category_to_partners.php:27-30`:

```php
DB::table('partners')
    ->whereIn('type', ['customer', 'both'])
    ->whereNull('customer_category')
    ->update(['customer_category' => 'business']);
```

It backfills existing customers. **Under database-per-tenant (T6 flip, 2026-05-28), every tenant DB is created empty and then migrated** — `config/tenancy.php:195-199` runs `tenants:migrate` over `database/migrations/tenant`. The backfill therefore executes against a **zero-row `partners` table** for every tenant provisioned after the flip. It is a structural no-op, not an accident.

And nothing else ever writes the column:

* `grep -rn customer_category apps/api/database/` → **only the migration itself**. **Zero seeders.**
* `PartnerService::upsertWithTypeMerge` — the import writer — does not carry it, so neither importer can set it.
* `PosPendingCustomerController.php:82-94` — the POS customer creator does not set it.
* `CreatePartnerRequest.php:45` / `UpdatePartnerRequest.php:84` — `nullable`, never required.
* The DB check constraint explicitly permits NULL (`…:33`).

So a nullable column with a documented intent, a doc-comment, an enum, a DTO field and a check constraint — and exactly one live writer, an optional unlabelled dropdown — became the gate for the entire B2B affordance set (`PartnerForm.tsx:667-668`, `{customerCategory === 'business' && (`). Its backend twin is already dead too: `Partner::isBusiness()` (`Partner.php:231`) has **zero callers** in `apps/api/app` or `apps/api/tests`. The frontend half of the gate is live; the backend half is dead. Nothing could trip on this, because nothing was ever asked to.

### 2.3 Rule 7 has a *generation* guard, never a *consumption* guard

The `types-drift` CI job (`.github/workflows/ci.yml:2423-2474`, landed `b66033043` 2026-04-25, in `all-checks-pass` `needs` at `:2545`) runs `php artisan typescript:transform` and fails if `packages/shared/types/generated.d.ts` differs. That proves the generated file is **fresh**. It says nothing about whether any `.tsx` imports it.

Result, at HEAD:

* `packages/shared/types/generated.d.ts:1796` exports `PartnerData`.
* **Exactly one** web file consumes it: `apps/web/src/features/partners/PartnerDetailPage.tsx:62`.
* **Seven** files hand-roll a competing `Partner`: `PartnerListPage.tsx:27`, `PartnerForm.tsx:31`, `AddPartnerModal.tsx:21`, `features/vehicles/VehicleForm.tsx:15`, `features/documents/components/TaxExemptionNotice.tsx:11`, `features/treasury/PaymentForm.tsx:54`, and a vehicles test.
* Not confined to Partner: 6 hand-rolled `interface Product`, 5 `Payment`, 3 `Vehicle`, 3 `Document`.

The guard's blast radius is a single generated file, so the seven shadows predate it and postdate it untouched. **This is the general shape of the failure: the guard proves the source of truth is current, not that anyone reads it.** It is why `tax_id` survived 267 days and why `AddPartnerModal` can post `tax_id`/`address` that `CreatePartnerRequest` (`:43-104`) does not declare and `PartnerController::store` (`:208`) silently drops.

### 2.4 The deprecation mechanism existed, generic, fifteen days before the audit — and was applied to one case only

`apps/api/app/Modules/Import/Domain/Enums/ImportType.php` has complete, generic retirement machinery: `deprecationMessage(): ?string` (`:50`), `isDeprecated()` (`:65`), `selectable()` (`:75`), enforced in `ImportController.php:115,473`, `MigrationWizardController.php:149`, `ImportService.php:414`, `MigrationWizardService.php:31`. Its docblock (`:27-38`) even explains why the *case* must survive the deprecation (`import_jobs.type` is a plain string cast by `from()`; dropping the case would `ValueError` on every historical read).

It has **one arm**:

```php
return match ($this) {
    self::StockLevels => 'The stock levels import is no longer supported. …',
    default => null,
};
```

Landed `bfafb3510`, **2026-08-08**, *"fix(dpa-v6): retire stock_levels import type + upsertStockLevel (D4)"* — fifteen days before this audit, in the document-per-action lane. Retiring `Partners` is **one `match` arm**. Nothing in the repo asks whether any other case deserves one; `grep -rln 'isDeprecated|deprecationMessage|selectable()' apps/api/app/Modules` returns only the three Import files. The lesson was learned in one lane and not generalized.

Meanwhile the FE demotion made the duplicate *look* handled: `ImportDashboardPage.tsx` has `PRIMARY_IMPORT_TYPES` (`:34-45`) and `ADVANCED_IMPORT_TYPES` (`:47-73`, containing `partners`), plus `ENTITY_TO_IMPORT_TYPE` (`:82-91`) keeping `partners: 'partners'` as a live entry point. A **UI demotion reads as a retirement and enforces nothing.**

### 2.5 Vertical registration is unenforced *and* the two vocabularies have diverged

Contact appears in zero of the 12 verticals in `config/verticals.php`, and its routes carry no `module:` middleware (`app/Modules/Contact/routes.php:20` — `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]`), so its endpoints are unconditionally live on every tenant, gated only by the `contacts.view` permission.

But a naive "modules absent from verticals.php" check is not the guard: **35 of 48 module directories are absent**, including `POS`, `Product`, `Import`, `Document`, `Fiscal`. And `default_modules` names six things that are not module directories at all — `Sales`, `Tables`, `CompositeItems`, `Parapharmacy`, `Merchandising`, `PurchaseBonus` — capability flags consumed by `RequireModule` (`bootstrap/app.php:114`; e.g. `Catalog/Presentation/routes.php:32` uses `module:CompositeItems`). **`verticals.php` names capabilities; `app/Modules/` names directories; nothing reconciles the two.** `.claude/context/new-feature-checklist.md:35-40` asks for the `module:` middleware only *"If the feature is vertical-exclusive"* — a self-assessed conditional, which a module intended for everyone answers "no" to, correctly, and then never appears anywhere.

### 2.6 No detector in `apps/web` can see an unimported component

* `ContactPersonsSubForm.tsx:39` exports a complete contact-persons editor; `grep -rn ContactPersonsSubForm apps/web/src apps/web/e2e` returns **only the three lines inside the file**.
* `usePartnerContacts.ts:21` — sole importer is `features/partners/__tests__/tenantScope.test.tsx:20`.

`apps/web/package.json` has no `knip`/`ts-prune`/`depcheck`/`madge`/`eslint-plugin-unused-imports`; `eslint.config.js` registers only the 3 `precision/*` and 3 `local/*` rules; `scripts/preflight.sh` has no such step. The nearest artifact is **backend** and hand-listed: `apps/api/tests/Architecture/OrphanedTypesCleanupTest.php` (2026-06-22), four `assertFileDoesNotExist` paths. So `b3` in the audit's plan — "wire the Contacts tab using the already-written `ContactPersonsSubForm`" — describes a finished component that has been sitting unreferenced since March, and no lane, lint, or ratchet has ever mentioned it.

---

## 3. Near-misses — six times this was visible, six narrowings

| # | Date | Where | What was visible | The narrowing |
|---|---|---|---|---|
| **N1** | 2026-03-09/11 | *nothing* | `party_id` enters the schema | No spec/ADR/glossary existed. Record = one checkbox, `docs/pos/ROADMAP.md:126` |
| **N2** | 2026-05-24 | `specs/2026-05-24-t11-b2b-b2c-separation.md:73-74` | The owner's 2026-08-23 ask, stated verbatim | Reframed as "add a role enum to a join table", sized ~3 PD |
| **N3** | 2026-05-24 | `specs/2026-05-24-t11-impl-a-customer-model.md:56-72` | Two overlapping partner↔person join tables | *"The two coexist. Reconciling them… is explicitly out of scope"* — on procedural authority |
| **N4** | 2026-07-02/03 | `audits/2026-07-03-unified-imports-spec-adversarial-review.md:33,222` | **The exact right question, asked by a reviewer** | Answered "keep both", closed **Resolved** |
| **N5** | 2026-08-10 | UI audit `UI-02` | `/crm/companies` renders `null` and redirects to `/sales/customers` | **Re-rated P1 → P3**, deferred to "route dedup, UI-35, Wave 4" |
| **N6** | 2026-08-21 | `OWNER-SHEET…:56` (B-4) | Two import types, one table | *"Cheap; will implement the hide+banner"* |

### N4 is the one that matters — the reviewer got it right and was closed out

`docs/superpowers/audits/2026-07-03-unified-imports-spec-adversarial-review.md`, finding B3, `:27-33`:

> **Why this is a blocker:** Adding `parties` is not a local enum tweak. It affects request validation, templates, smart mapping, generated/shared DTOs, frontend routing, history display, switch statements, and backwards compatibility with `partners`.
>
> **Suggested fix:** State the exact external API value. If `parties` is new, add a compatibility matrix… **If this is only a relabel of `partners`, keep the API value `partners` and define how balances are enabled without adding a second import type.**

Restated as an owner question, `:222`:

> 1. Is the external import type value definitely `parties`, or should the existing `partners` type be extended and relabeled?

Disposition, `:244`:

> | B3: `parties` API value ripple | **Resolved** | The spec now declares `parties` as a new external API value and lists affected touchpoints… |

**"Resolved" meant "the ripple was enumerated."** The reviewer offered the correct fork — *relabel* vs *duplicate* — the spec took the duplicate branch, and the closure criterion was completeness of the `match`-arm list, not correctness of the branch. Plan Task 5 (`plans/…:344`) then measures the cost of the second name in exactly those terms: *"it has SIX type-keyed structures that ALL need a `parties` entry… `UnhandledMatchError` at runtime if any `match` arm is missed."* **The cost of a second name for one table was priced in match arms and paid without comment.**

The drift is legible inside a single spec bullet, `:159`: *"two primary cards — **Business partners** and **Products** — with the migration order hint (parties first)"* — the card is `Business partners`, the type is `parties`, the table is `partners`. Three names, one line, no note.

### N3 — the reconciliation was declined in favour of a table that never shipped

`specs/2026-05-24-t11-impl-a-customer-model.md:56-72` is the most explicit noted-and-narrowed record in the repo:

> **Alternative considered and rejected:** add a `role` enum column to the existing `party_contacts`. Rejected because (a) … bolting a fourth role axis on top mixes two concerns in one row and risks a flag-vs-enum source-of-truth split; **(b) the contract already decided.** `customer_contacts` is therefore the B2B account-relationship-role layer; `party_contacts` is untouched… **The two coexist. Reconciling them into one table is explicitly out of scope (§9).**

Reason (a) is real engineering judgement. **Reason (b) is pure procedure** — a row in `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:67` was elevated to a *"constitutional topology contract"* with an *"authority clause"*, and that authority was then sufficient grounds not to ask the modelling question.

**And `customer_contacts` was never built.** `ls apps/api/database/migrations/tenant/ | grep customer_contact` → nothing. `grep -rn customer_contacts apps/api/app apps/api/database` → nothing. So the consolidation of `party_contacts` was declined to protect a table that does not exist.

The same spec, `:48`, had already quarantined the Contact module rather than fix it:

> ⚠️ **Do NOT mirror** `…/Contact/Application/Services/ContactService.php` for DI/DTO style (round-1 Codex P1-1): **it predates the convention** — no constructor, `array<string,mixed>` inputs…

A known-substandard module was routed around, not flagged as dead.

### N2 — the owner's question, three months early

`specs/2026-05-24-t11-b2b-b2c-separation.md:73-74`:

> - ADD `customer_contacts` join table — `(partner_id, contact_id, role_enum)` extending existing `PartyContact` pattern with explicit role validation
> - Covers: **Business partner has multiple Person contacts AND a Person partner who is ALSO a contact at a Business**

That second bullet *is* the contact-vs-company disambiguation. It was scoped as a missing role enum, not as "we have no rule for what a Contact is versus a Partner", and its own framing quotes the owner calling the case *"edgy… from business perspective"* (`:27`) — which is exactly what let it stay small.

### N5 — a duplicated entity read as an authorization typo

`docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md:131` rates UI-02 **"OVERSTATED — re-rated P1→P3"**; the adversarial reviewer (`05-codex-adversarial-review.md:52`) agrees: *"The mounted component returns `null` and redirects to a valid Sales gate."* The Wave 0 dispatch (`CODEX-DISPATCH-ui-wave0-2026-08-11.md:282`) concludes *"Do not delete the route — route dedup is UI-35, Wave 4."* Each step is individually correct. Collectively they convert *"we ship a CRM page for an entity that is the same entity as Sales' customers"* into a route-dedup chore in a future wave.

`/crm/contacts` was never questioned at all: `10-verification-orphans-duplicates.md:186` lists it as reachable-and-fine, and `docs/sessions/2026-06-30-visual-test-results.md:206` ticks it off as *"render[ing] with correct empty states/filters"* — **it was verified as empty and passed.**

### N6 — and the ranked audit that produced B-4 was never committed

`OWNER-SHEET-2026-08-21-first-client-session.md:41` says *"Full audit evidence lives in the session transcript; gaps are ranked G1–G13."* `grep -rln 'G13.*parties'` across `docs/` matches only the owner sheet. **The G1–G13 onboarding audit does not exist as a file.** The earliest durable record of B-4 is `:56`, already narrowed:

> Session lean: hide `partners` from the dashboard (keep API for history), or add a deprecation banner steering to `parties`. **Cheap**; will implement the hide+banner unless you object.

There was no upstream document to widen; the framing had collapsed before anything was written down. **B-4 only became this audit because the owner hit the symptoms in the product and refused the cheap fix** (`:22`).

### One more, from the SOC audit — the coupling was measured and permitted

`docs/superpowers/audits/2026-06-24-hexagonal-soc-audit/05-platform-identity.md:8`, finding F2, severity **HIGH**:

> `Contact/Domain/PartyContact.php:7`, `Contact.php:7,9` … Domain entities import other modules' Domain models (**Contact↔Partner↔Company↔Identity↔Tenant mutually coupled**). Relations behind contracts/IDs, **or document an accepted "core platform aggregate" exception.**

Rated HIGH — as a *dependency-direction* finding. "Are these the same thing?" was never the question, and the offered escape hatch ("document an accepted exception") is the door the question walked out of.

---

## 4. The drift signature, and where else it lives today

**The signature.** A term is introduced in product or code without a schema, a definition, or a retirement of the term it replaces; the new artifact ships *beside* its predecessor rather than in place of it, because "nothing is deleted" is always the cheaper review answer; the difference between them is carried by a **nullable column or an optional flag**, so no seeder, test, or migration can ever trip on it; and the dead half stays green because its tests instantiate their own data and never ask whether the outside world can reach it. Each step is individually defensible and locally reviewed. The defect is only visible from the seam between two lanes — which is exactly the view no reviewer, ratchet, or CI lane in this repo is scoped to have.

Four siblings that match it **today** (verified, not exhaustive):

### 4.1 `locations.pos_enabled` — a capability flag that gates nothing (highest confidence)

* Declared `2025_11_30_105000_create_locations_table.php:52` — `boolean('pos_enabled')->default(false)`.
* Model fillable + cast: `Company/Domain/Location.php:93,113`.
* **Written** non-default by five paths: `CoffeeShopSeeder.php:355`, `ParapharmacySeeder.php:604`, `TunisianParapharmacySeeder.php:204`, `DemoPharmacySeeder.php:308`, `LocationController.php:205`.
* **Read behaviourally: nowhere.** `grep -rn "where('pos_enabled|pos_enabled', true|posEnabled" apps/api/app apps/api/routes` → no matches. Remaining hits are write-defaults, validation rules, and serialization (`LocationResource.php:40`, `TenantProvisioningService.php:169`, `AuthController.php:436`, `CompanyController.php:127`).
* FE reads it cosmetically only: `LocationsPage.tsx:321` renders a success badge. In `apps/pos` it exists solely as a type field, `stores/terminalStore.ts:38`, never read.
* The only filtering use is e2e helper code (`apps/web/e2e/money-campaign/statement-support.ts:432`).

A boolean that names a capability, is set by every seeder, is surfaced through the API, is rendered as a badge — and gates nothing. Exactly `customer_category`'s shape, one axis over.

### 4.2 `loyalty_programs.target_type` — a stored lie, exported as a public type

* `2026_03_10_100004_make_loyalty_polymorphic.php:35` — `string('target_type',20)->default('contact')`, from the **same March wave**.
* `LoyaltyTargetType.php` — 2 cases, no methods.
* Whole-repo references: three, all in one file — an import, a `@property` docblock, and the cast (`LoyaltyProgram.php:7,32,81`; plus `:68` fillable). **Zero behavioural consumers.**
* It is nonetheless exported to the frontend: `packages/shared/types/generated.d.ts:1372` — `export type LoyaltyTargetType = 'contact' | 'partner'`.

A program declared to target contacts enrols partners. The column defaults to `'contact'` — the *dead* side of the entity split — which is how the March wave's intent got frozen into a value that nothing honours.

### 4.3 Four enum classes with zero references — CLAUDE.md rule 9 satisfied in form, not in fact

| Enum | Backing column |
|---|---|
| `Treasury/Domain/Enums/AllocationType.php` | **none** — no `allocation_type` column anywhere |
| `Treasury/Domain/Enums/ReconciliationStatus.php` | column exists as a **raw DB enum**: `2025_12_14_150000_create_bank_reconciliations_table.php:24` — `$table->enum('status', ['draft','completed','cancelled'])` |
| `POS/Domain/Enums/SyncStatus.php` | `sync_status` handled as a raw string: `VoucherLedgerSyncRequest.php:66` — `'entries.*.sync_status' => ['nullable','string']` |
| `PlatformIntegration/Domain/Enums/PlatformLookupStatus.php` | **none** |

`ReconciliationStatus` and `SyncStatus` are the sharpest: the enum was written *because* rule 9 demands it, the column shipped, and the column is validated by string literals instead. The enum is a stored lie about how its own column works. `AllocationType.php:21-22` even carries `// Extensibility: Phase 2 // case CASH_DISCOUNT = 'cash_discount';` for a concept with no column. The partial guard that exists — `apps/api/tests/Architecture/EnumBackedStatusLiteralTest.php` — is a hand-listed 6-entry data provider, not a scanner, and cannot see any of these.

### 4.4 `Coupon` — the closest twin to `Contact`

17 PHP files; exactly **one** reference from outside its own directory; two tables (`2026_03_02_200003_create_coupons_table.php`, `…_200004_create_coupon_usages_table.php`); **zero seeder rows** (`RolesAndPermissionsSeeder.php:107` grants permissions only); a live FE feature at `apps/web/src/features/coupons/`; routes with **no `module:` gate**; absent from `config/verticals.php`. Same cohort, weaker evidence: `Notification`, `PurchaseHub`, `Replenishment`, `SmartPrompts`, `Progression`, `DocumentIngestion` — each with routes registered, no module gate, and no seeder rows. *(Row counts not measured for these; inferred from 0 seeders + 0 factories.)*

### 4.5 Two more, named honestly

* **`products` appears in both import grids** and it is *known*: `ImportDashboardPage.tsx:111-113` — `// 'products' appears in both grids, so resolve the request against the primary grid first and only fall through to the advanced one`. A documented workaround for a duplicate entry point is the same acceptance that kept `partners` alive.
* **`ReportGenerationService.php:514`** — `@deprecated This is a takings-only server-authoring surface with no shipped client.` A textbook born-dead artifact with a **prose-only** retirement. `@deprecated` in `app/Modules/*/Domain/Enums/` appears in exactly one file; every other `@deprecated` in the codebase (`Vehicle/Domain/Vehicle.php:131,195`, `VehicleWithCurrentOwnerData.php:54`, two Workshop repository interfaces) is unenforced prose.

---

## 5. Prevention guardrails — six, each with a hook point in this repo's idioms

The repo has two established guard idioms and an explicit convention governing both (`docs/conventions/08-DETECTOR-LIVENESS.md`, 2026-08-19): **(i)** `apps/web/tools/audit-*.mjs` + JSON baseline + `pnpm audit:*` + a discrete `frontend-lint` CI step + a `tools/__tests__` liveness test; **(ii)** `apps/api/tests/Architecture/*RatchetTest.php` with a baseline. Every proposal below hooks into one of them, and every one ships with the tamper test that convention 08 requires.

### G1 — `ImportType` invariant test: no two selectable types may write the same table (**S**, highest value)

Add `apps/api/tests/Architecture/ImportTypeSingleWriterTest.php`, run in `backend-architecture`. Assert: **(a)** every `ImportType::selectable()` case has a distinct terminal writer — derived from the `match` arms in `ImportService::importRow()` (`:404-421`) and `finalizeImport()` (`:452-459`), not a hand-list; **(b)** every selectable type appears in **exactly one** of `PRIMARY_IMPORT_TYPES` / `ADVANCED_IMPORT_TYPES`; **(c)** every selectable type that reaches a partner/product writer has a non-`default` arm in `finalizeImport`, or declares in the enum that it deliberately posts nothing.

Today `Parties` and `Partners` both terminate in `importPartner` (`ImportService.php:426-435` → `:485-490`) and `Partners` falls to `default => []`. Assertion (a) fails today; the fix is one `deprecationMessage()` arm (`ImportType.php:50-56`) — the machinery already exists and is already enforced at four call sites. Assertion (c) is the one that would have caught the *silent AR/AP loss* independently of the naming question. **Tamper test:** a fixture enum pair sharing a writer must fail.

### G2 — Canonical-entity registry with a declare-or-fail check (**M**, the actual root cause)

The repo already proves this pattern works twice — `apps/api/tests/feature-lane-manifest.json` + `apps/api/tools/feature-lane-manifest-check.php`, whose own note reads: *"A new directory has no entry here and FAILS the check — that is the whole point: before this file, a new Feature class in an unlisted directory ran nowhere, forever, and nothing said so."* And `App\Shared\Application\Partner\PartnerReferenceTable` + `PartnerReferenceSchemaSweepTest` (2026-08-08) is a working per-module registry for FK pointers.

Do the same for vocabulary. Add `apps/api/app/Shared/Domain/CanonicalEntity.php` (or a JSON manifest beside the feature-lane one) declaring, per canonical entity: the table, the module that owns it, its **permitted UI synonyms**, and its FE type name. Add `apps/api/tools/canonical-entity-manifest-check.php` to the `backend-architecture` job, asserting:

1. every table in `database/migrations/tenant/create_*` maps to a declared canonical entity (or is declared a child/pivot of one);
2. every new module directory declares its canonical entity;
3. **no two canonical entities share a table** — a `parties` entity over the `partners` table is either the same entity (declare the synonym) or a violation.

Then wire the synonym list into i18n: an `import.json` key saying "Business partners" for a type called `parties` writing `partners` is legal only if all three are declared aliases of one entity. This is the single measure that would have made Wave 2 impossible to ship silently, and it is the one the platform glossary (`../../claude/glossary.md`) already gestures at but never enforced — and never covered ERP entities at all.

### G3 — Dead-column detector: no nullable column may gate a live affordance (**M**)

Add `apps/web/tools/audit-gating-columns.mjs` + `gating-columns-baseline.json`, hooked into `pnpm lint` beside `audit:keys` / `audit:design-system` / `audit:quantity` (`ci.yml:2221-2227`), with a `tools/__tests__` liveness fixture. It scans `apps/web/src` for JSX conditionals keyed on a backend field (`{x === 'literal' && (`), resolves the field against `packages/shared/types/generated.d.ts`, and fails when the field is **optional/nullable in the DTO** and has **no non-form writer** on the backend. Pair it with a backend ratchet: `apps/api/tests/Architecture/GatingColumnWriterTest.php` asserting every column named in the manifest has at least one writer outside a FormRequest — which catches `customer_category` (only writer: an optional dropdown), `pos_enabled` (no reader), and `loyalty_programs.target_type` (no consumer).

Second, cheaper half, worth doing on its own: **forbid data backfills inside migrations that run at tenant provisioning.** Under db-per-tenant (`config/tenancy.php:195-199`) they are no-ops by construction (§2.2). A PHPStan rule in `apps/api/app/PHPStan/Rules/` — the repo has 8 already, each with a `tests/PHPStan/` fixture test — flagging `DB::table(...)->update(...)` in `database/migrations/tenant/` unless the migration carries an explicit `@backfill-for-existing-tenants-only` annotation, would have made the false comfort of that backfill visible on the day it was written.

### G4 — Extend rule 7 from generation to consumption (**S**)

`types-drift` (`ci.yml:2423-2474`) proves `generated.d.ts` is fresh. Add an ESLint rule `local/no-shadowed-generated-dto` in `apps/web/eslint-rules/` (six rules already live there, **each with a `.test.mjs` RuleTester** — the idiom is established and convention-08-compliant): fail on a locally-declared `interface`/`type` whose name matches a generated DTO stem (`PartnerData` → `Partner`, `ProductData` → `Product`, …), directing the author to `type Partner = App.Modules.Partner.Application.DTOs.PartnerData` as `PartnerDetailPage.tsx:62` already does. Baseline the 7 `Partner` + 6 `Product` + 5 `Payment` + 3 `Vehicle` + 3 `Document` shadows as shrink-only, matching the `lint-warning-baseline.json` ratchet idiom (`scripts/lint-ratchet.mjs`, 2026-05-14).

This is the guard that turns the 267-day `tax_id` blank into a CI failure on the day it is typed.

### G5 — Unused-export detector for `apps/web` (**S**)

There is none (§2.6). Add `knip` (or `ts-prune`) as `pnpm audit:orphans` in the `frontend-lint` chain with a shrink-only JSON baseline, scoped to `src/features/**/components` and `src/features/**/hooks`. It reports `ContactPersonsSubForm.tsx` and `usePartnerContacts.ts` on day one. The backend equivalent already exists in hand-listed form (`tests/Architecture/OrphanedTypesCleanupTest.php`) and should be replaced by the same shrink-only mechanism rather than extended by hand.

### G6 — Reconcile `verticals.php` capabilities with `app/Modules/` directories (**S**)

Not "every module must be in a vertical" — 35 of 48 aren't, correctly (§2.5). Instead add a checker to `backend-architecture` asserting: **(a)** every string in `verticals.php` `default_modules`/`extras` is either a `ModuleName` enum case or an explicitly declared capability flag (today six are neither); **(b)** every module whose `routes.php` carries `module:X` has `X` present in at least one vertical; **(c)** every module with a **customer-facing FE feature directory** (`apps/web/src/features/<x>/`) and its own tables is either in a vertical or carries an explicit `#[UngatedModule('reason')]` marker. (c) is what puts `Contact` and `Coupon` on a list a human must sign, rather than leaving them invisible in both directions.

### G7 — Mixed-mega-commit prevention: **already fixed; one residual gap**

This one is genuinely closed, and it should be said plainly. The lane/gate discipline that now operates — worktree-per-lane (CLAUDE.md rule 21, `dev-push-guard` hook), per-milestone `scripts/adversarial-review.sh` rounds recorded under `docs/handoff/reviews/`, `scripts/adversarial-review-final.sh` receipts, 10 domain reviewer agents in `.claude/agents/`, a 23-job `all-checks-pass` gate — makes a 365-file commit spanning five features procedurally impossible. Nothing further is needed there.

**The residual gap is scope, not size.** Every reviewer agent in `.claude/agents/` is scoped to a *subsystem* — imports, fiscal-pos, treasury, tenancy-authz, inventory-costing, frontend-conventions, stock-gl-interaction. Not one is scoped to the *domain model*. `imports-reviewer.md` is 60 lines of extremely sharp domain truth — sign quadrants, the `7.140` locale trap, default-lot batch behaviour, re-run idempotency — and it contains **no item asking whether a change introduces a second name or a second path to an existing entity**, which is why it would not have caught B-4 even if it had existed 31 hours earlier. Two concrete fixes: (i) add to every reviewer's standing checklist *"does this change introduce a second artifact writing an entity an existing artifact already writes? If yes, where is the predecessor's deprecation arm?"*; (ii) create a **domain-model reviewer** whose only remit is naming, entity identity, and the canonical-entity manifest of G2 — and make it mandatory for any diff touching `database/migrations/tenant/create_*`, a `Domain/Enums/*`, or `config/verticals.php`.

---

## 6. Honest limits — what a guardrail cannot catch, and how much is already fixed

### 6.1 What no guard here can catch

**G2 detects a second name; it cannot detect a wrong one.** If someone declares `parties` a canonical entity over the `partners` table with an honest declaration, the manifest passes and the product still speaks two dialects. Naming correctness is a human judgement; the manifest's real value is that it forces the judgement to be *made, written, and diffed* rather than absorbed into a `match` arm.

**No guard would have caught Wave 3.** The decision was written down, reviewed four times, and signed off. The failure was a *premise* — that `partners` belonged in the "keep the segmented legacy flow for accountants" bucket — and premises are not machine-checkable. The only mechanism that catches this class is the one G7(ii) proposes: a standing review question that asks *"is the reason we are keeping this true of **this** artifact, or only of the family it was filed under?"* That is a checklist item, not a ratchet, and it will work only as reliably as reviewers apply it.

**No guard reaches the strongest signal we had, which was data.** The audit's decisive facts came from counting rows: 0 contacts in 8 tenant DBs, `customer_category` NULL for 1,113 partners, 5 receipts with `partner_id IS NULL`. CI runs on synthetic data that each test creates for itself (§2.1). A module can be 100% covered and 100% unused, and only a query against a real tenant tells the difference. **The nearest tractable proxy is the seeder**: "0 seeder rows + 0 factories" identified `Contact` and `Coupon` correctly and costs nothing to check. Worth adding to G6(c) as the tie-breaker.

### 6.2 What the current discipline would already have caught

Applied to 2025-11 and 2026-03, the standing gates would have stopped most of this — not by being smarter, but by making the commits reviewable:

* **Wave 1's `tax_id`** — caught twice over: by G4, and by `frontend-conventions-reviewer` (2026-07-11), whose remit is exactly canonical components, tokens and generated types. A 201-file commit would not reach a reviewer today at all; it would be a worktree lane with milestone rounds.
* **Wave 2 entirely.** A five-concern, 365-file commit cannot pass the current lane discipline. `docs/superpowers/specs/` + `plans/` (from 2026-03-20) would have required a written design for a new module; `.claude/context/new-feature-checklist.md` §35-40 would have surfaced the vertical-gating question; `tenancy-authz-reviewer` would have flagged `Contact/routes.php:20` shipping ungated on every tenant; the feature-lane manifest would have forced a lane decision for `tests/Feature/Contact/`. The vocabulary question is the only part that still slips through — which is precisely the hole G2 and G7(ii) exist to fill.
* **`customer_category` gating a live UI while NULL everywhere** — G3, and independently the frontend reviewer, on `PartnerForm.tsx:667`.
* **The `ImportType::Partners` duplicate** — G1 mechanically. Note the machinery to fix it has existed since 2026-08-08 (`bfafb3510`) and is one `match` arm away; what is missing is not capability but a check that *asks*.

### 6.3 The honest scoreboard

Of the twelve-odd misbindings in the audit, roughly **eight** are artifacts of a review surface that no longer exists — mega-commits, no specs, no reviewers, no architecture jobs. Those are structurally fixed. **Three** — the hand-rolled DTO shadows, the dead gating column, the orphaned components — are live today and are the direct targets of G3/G4/G5; they persist because the repo's guards are excellent at *"is the source of truth current?"* and have no idiom for *"does anything consume it?"*. **One** — Wave 3's keep-both ruling — was produced by the good process working as designed, and is the only part of this that needs a change to *how we review* rather than a new detector.

The single sentence worth carrying forward: **this repo enforces freshness and shrinkage extremely well, and liveness not at all.** Every guard listed in §5's preamble answers "has this been regenerated / has this got worse". None answers "is anything on the other end of this". `customer_category`, `pos_enabled`, `target_type`, `ContactPersonsSubForm`, `PartnerData`, and the entire Contact module are all the same failure, seen six times: **an artifact that is correct, current, tested, and connected to nothing.**
