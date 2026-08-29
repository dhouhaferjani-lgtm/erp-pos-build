# Codex dispatch — Session H (B-18 party/contact program), **Phase 2: `party_kind`** (2026-08-29) — DRAFT

> **DRAFT — not dispatched.** Revision **r3**, after gate r1 (F-1..F-18) and gate r2
> (`docs/superpowers/reviews/2026-08-29-session-h-phase2-brief-gate-r2.md`: 12 resolved, 6 PARTIAL,
> N-1..N-10). All dispositions applied and re-verified against code at `a33b01354`. **The r3 re-gate runs
> against the post-Phase-1-merge `dev` tip; `base_sha` and both migration timestamps are pinned then.**
> Do not dispatch from a HEAD lacking the Phase-1 merge.
> **Path warning:** r1 cited module paths that do not exist here (`app/Modules/CRM/…`,
> `apps/web/src/lib/pos/offline/…`). The real paths — used throughout — are `app/Modules/Partner/…`,
> `apps/pos/src/lib/…` and `apps/web/src/features/partners/PartnerForm.tsx`.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF via
> `scripts/adversarial-review.sh` (it calls `claude -p --model opus`), read its register, and loop
> scoped fix rounds until ACCEPT — then move on.
> - **State file: `docs/handoff/progress/session-h-phase2.progress.yaml`** — the parent creates it at
>   dispatch with the pinned `base_sha`. Read it first; update it after every milestone (status, commit
>   SHA, verdict path, fix_rounds). It is your resume point. If it does not exist — **STOP**.
> - **Reviewer fallback:** on two consecutive exit-3 (tool error) runs for one milestone, write your OWN
>   register (same lenses, numbered findings, `VERDICT:` line) to the register path, set
>   `reviewer_model: codex-fallback` on that milestone, and continue; the parent re-gates it at merge.
> - Per-milestone registers go to `docs/handoff/reviews/session-h-phase2/`.
> - **Branch NOT merged into dev, NOT pushed.** The parent orchestrator (Session H) merges after gates.
> - **STOP and escalate only at the harness STOP conditions:** fix rounds exhausted (max 4), an owner
>   gate (§5 — anything that changes SEALED BYTES, a payload KEY SET, or the device schema), or an
>   architecture contradiction. Set the YAML `status` + `blockers` and end.

**Model/effort (owner directive):** Codex SOL/Luna, HIGH effort. Workhorse mode: TDD red-first,
milestone by milestone, self-gated.

**Entry gate — Phase 1 MUST be merged first.** Phase 2 rewrites the same partner form, list page,
inline modal and `ImportType::Parties` block Phase 1
(`docs/handoff/CODEX-DISPATCH-session-H-phase1-2026-08-29.md`) touches, and *repoints* Phase-1 M2's
"Nature" select from `customer_category` to `party_kind`. If the Phase-1 lanes are not on local `dev`
when you start — **STOP** (F-15).

**Citation convention:** every path is repo-root-relative from `/Users/houssamr/Projects/syneriva/apps/erp`.
Line anchors were verified against **`a33b01354`** (local `dev` tip, 2026-08-29, *before* the Phase-1
merge). Phase 1 moves anchors in `PartnerForm.tsx`, `PartnerListPage.tsx`, `AddPartnerModal.tsx`,
`routes/index.tsx`, `ImportType.php`, `VehicleForm.tsx` **and — added in r3 (N-9) —
`apps/web/src/features/partners/components/B2BFieldsSection.tsx` and `.../CreditLimitWarning.tsx`**
(Phase-1 brief §2 M2). M3.2 depends on `B2BFieldsSection`'s internal layout, so **re-verify its split
tax/credit anchors after the merge before you touch it**. **Re-verify every anchor with `grep -n` —
do not trust a line number blindly.**

| Lane | Worktree | Branch | Milestones |
|---|---|---|---|
| **h2-party-kind** | `.worktrees/h2-party-kind` | `feat/h2-party-kind` | M1 → M2 → M3 → M4, in order |

One lane, sequential. Do not create the worktree yourself; the parent creates it dependency-ready
(vendor copied + `composer dump-autoload`, `.env` copied, `pnpm install --offline`). Do not `git stash`
(repo-global stash — forbidden in worktree lanes); never combine `--force` with `git push`.

---

## §0 — Read before writing code, in this order

1. `docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` — **§1.5, §5.3, §5.4,
   §7.2–7.5, §8.1, §8.3, §8.4 (Phase 2 row), §8.5**. §8.1 + §8.3 are the authoritative scope.
2. `docs/handoff/LEDGER.md` row **D-H0-1** — the owner's rulings OQ1–OQ9 + acks 1–5. **BINDING; they
   override the spec where they differ, and ONLY where they differ.** Load-bearing: OQ1 (four nullable
   person columns on `partners`, gated on `party_kind = person`; CIN never required) · OQ6 (nature field
   labelled **Nature**, options *Individual / Company* · *Particulier / Société* · *فرد / شركة*; role field
   keeps **Type**) · OQ7 (sole trader with a matricule = **organization** + `legal_form='personne_physique'`;
   a `person` **never** carries a tax id — **amends tax identity only, not credit eligibility**, F-13) ·
   ack 1 (backfill heuristic) · ack 2 (AR i18n in the same commits).
3. `docs/handoff/ASSESSMENT-crm-seam-party-model-2026-08-29.md` **§4 R-A..R-D** — all four in scope. §5
   lists what stays unbuilt: no consent, channel-identity, tag/segment, campaign or transport surface.
4. `docs/handoff/CODEX-DISPATCH-session-H-phase1-2026-08-29.md` — extend Phase 1, do not redo or revert it
   (especially §2 M2: the Nature select, the B2B NULL heuristic, the decimal-safe `CreditLimitWarning`).
5. The two gate registers, `…-brief-gate-r1.md` and `…-brief-gate-r2.md` under
   `docs/superpowers/reviews/` — read them so you understand *why* each instruction is shaped as it is.
6. `apps/erp/CLAUDE.md` rules **3** (no `mixed`, DTOs at boundaries), **7**, **8**, **9**, **12**, **13**,
   **19**; `docs/conventions/01`, `03`, `04`, `06`. 7. `docs/handoff/SELF-REVIEW-HARNESS.md`.

**Auto-deploy rule, load-bearing for both migrations.** A migration pushed to `origin/dev` auto-deploys
`tenants:migrate` on staging across every tenant DB, so both must be **self-guarding** and **idempotent**.
The r1 gate is right that `apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php`
is **not** a guarded template — its `addColumn` (`:20-24`) and `ADD CONSTRAINT` (`:33`) are unguarded and
drop-and-re-add is not a no-op (F-17). Cite it only for its **backfill shape** (`:26-30` — nullable column,
then a null-based `UPDATE`) and `COMMENT ON COLUMN` (`:34`); use explicit PG catalog probes instead (M1.2 step 0).

---

## §0.1 — Browser-level verification is a GATE for every milestone (owner requirement)

**Read Phase 1 §0.1 and reuse it verbatim** — main-checkout stack (:8010/:5173/PG :5433, demo tenant
**PharmaBio Tunisie**, `owner@pharmabio.tn` / `password`), worktree web `pnpm dev --port 5174 --strictPort`,
worktree API `php artisan serve --host=127.0.0.1 --port=8011`, specs in
`apps/web/e2e/session-h/<milestone>-*.spec.ts` with `test.use({ baseURL: process.env.E2E_BASE_URL ??
'http://localhost:5174' })`, screenshots → `.playwright-mcp/session-h/<milestone>/` cited in the register,
API assertions via the Playwright `request` fixture (pattern
`apps/web/e2e/smoke/treasury-phase5a-outbound.smoke.ts:17-27,113-118`), the POS gated by Vitest plus
server-side assertions, demo credentials only, never staging.

**⚠️ F-16 — port isolation is BROKEN today; M1 task 0 fixes it first.** `apps/web/vite.config.ts:21` and
`:25` **hard-code** `target: 'http://localhost:8010'` for `/api` and `/sanctum`, so the worktree web on
:5174 still sends every browser request to the MAIN checkout's API — a Phase-2 gate would silently test
unmodified code and pass.
- **M1 task 0:** make both proxy targets read `process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8010'`
  (default unchanged, so the main checkout and CI are unaffected). Commit it as the first commit of the lane.
- Launch the worktree web as `VITE_API_PROXY_TARGET=http://127.0.0.1:8011 pnpm dev --port 5174 --strictPort`.
- **Every browser gate must assert a marker proving :8011 served the request** — assert on a field that
  only the worktree API can return (from M1 onward, `party_kind` present on the `/partners` payload; at
  M1 that is itself the marker). Record the marker used in each milestone register. A gate with no marker
  assertion is not a gate.

---

## §1 — Standing constraints for every milestone

- **No sealed-payload KEY changes anywhere.** The one deliberate *value* consequence is
  `customer.customer_category` becoming a derived non-NULL value on **future** events (M2.3) — the
  spec's design (§1.5), to be proven byte-safe, never assumed. Anything else touching canonical bytes
  is a STOP.
- **No POS device migration.** Device stays at **v67** (`apps/pos/src/lib/db/migrations.ts:2163`); the
  device `customers.customer_category` column is already `TEXT` nullable (`migrations.ts:1177`) and its
  value set is unchanged. A contrary finding is a STOP (spec §1.5/§8.5).
- **Rule 3 at every boundary:** no `array<string, mixed>` in a new public signature — derivation and
  identity take and return typed DTOs (M1.1, F-10, N-1).
- **Rule 7:** never hand-edit `packages/shared/types/`; after changing `PartnerData` run
  `php artisan typescript:transform` (`CACHE_STORE=array`) and commit
  `packages/shared/types/generated.d.ts` (`PartnerData` `:1832`). `packages/shared` is **types-only** — it
  cannot host the TS normalizer runtime (M1.3).
- **Frontend:** `t()` for every string, **EN + FR + AR keys in the same commit** (ack 2); design tokens;
  `tenantScopedKey` on every tenant-data query key; zod + RHF. **Backend:** constructor injection only;
  PHPStan level 8 + Pint clean on touched files.
- **Tests:** TDD red-first. Backend by PATH only (`./vendor/bin/phpunit <file>`), **never the full suite**;
  web/POS `pnpm vitest run <dir>`, then `pkill -f 'node (vitest'`. **Schema/CHECK/parity tests run on
  PostgreSQL** — `tests/Architecture/EnumCheckParityTest.php:200` self-skips elsewhere, so a green sqlite
  run proves nothing (F-12).
- **At every commit:** `cd apps/api && php tools/feature-lane-manifest-check.php`. Commit prefix
  `h2 M<m>.<s>:` — small commits, one concern each.

---

## §2 — Milestones

### M1 — schema (additive only), enum, derivation, normalizer, DTO

**M1.0 — the vite proxy fix of §0.1.** First commit. Nothing else in the lane is verifiable without it.

**M1.1 `PartyKind`, `LegalForm`, `PartyGender`, and the typed derivation (rule 9, F-10, F-12).**
- `Domain/Enums/PartyKind.php`: `Person = 'person'`, `Organization = 'organization'`, `values()` shaped
  like `Domain/Enums/CustomerCategory.php:15-18`, plus `toCustomerCategory(): CustomerCategory`
  (`Person → Individual`, `Organization → Business`) — **the one and only kind→category mapping** (F-4).
- `Domain/Enums/LegalForm.php` — a **closed** enum (F-12): `personne_physique`, `sarl`, `suarl`, `sa`,
  `association`, `other`. A closed UI list over a free `varchar` is what rule 9 forbids; `other` is the valve.
- `Domain/Enums/PartyGender.php` — `male|female|other`, matching
  `app/Modules/Contact/Domain/Enums/Gender.php:9-11`. **Contact's `Gender` has no `values()` method**
  (verified — bare cases at `:7-12`), so the parity test compares `array_column(Gender::cases(), 'value')`
  with `array_column(PartyGender::cases(), 'value')` (F-12). Importing Contact's enum crosses a module
  boundary (rule 6); if the reviewer prefers promoting one to `App\Shared\Domain\Enums`, take that call.

- **Typed derivation (F-10, N-1, N-7).** `PartyKindDerivationInput` — a readonly DTO carrying
  **`requestedKind: ?PartyKind`** and **`kindProvided: bool`** (N-1: an explicit organization→person
  request must be distinguishable from a sparse update — `false` + `null` is "absent", and the pair also
  models an explicit `null`), `existingKind: ?PartyKind`, and the evidence fields **split into persisted
  vs incoming**: `type`, `customer_category`, `vat_number`, `company_legal_name`,
  `business_registration_number`, `credit_limit`, `payment_terms`, **`legal_form`** (N-7).
  `PartyKindDerivation` = `readonly PartyKind $kind` + `readonly string $reason`, a **stable per-arm code**:
  `requested_kind`, `existing_kind_preserved`, `category_business`, `has_b2b_datum`, `has_legal_form`,
  `role_supplier_or_both_on_create`, `default_person`. One `PartyKindDeriver` (Partner `Domain/`) owns the
  ladder; `PartyKind::deriveFrom(PartyKindDerivationInput): PartyKind` delegates and discards the reason.
  M4's warning reads `reason` — it must never re-derive.
- **The ladder, in strict precedence order (N-1 — the ladder text and the tests MUST agree):**
  1. **`kindProvided === true` → `requestedKind`**, reason `requested_kind`. An explicit kind always wins,
     and it is what authorizes the field-clearing contract of M2.1/N-2.
  2. **`existingKind !== null` and no NEW kind-changing evidence in the incoming row → keep it**, reason
     `existing_kind_preserved`. "New" = evidence in the **incoming** payload not already true of the
     persisted row. **A role merge alone NEVER reclassifies** — customer→both on a person stays person.
  3. Evidence ladder over the **merged** state: `customer_category === 'business'` → org
     (`category_business`); any of `vat_number` / `company_legal_name` / `business_registration_number` /
     `credit_limit` / `payment_terms` non-null → org (`has_b2b_datum`); **non-null `legal_form` → org**
     (`has_legal_form`, N-7 — else a bare customer row with `legal_form=sarl` derives person and is then
     rejected by the identity policy); **`type IN ('supplier','both')` → org ONLY on CREATE** (no existing
     row), `role_supplier_or_both_on_create`; else person (`default_person`).
- Unit tests, one per arm and per reason code, plus the N-1/F-6 regressions: a sparse update omitting VAT
  does not flip an existing organization to person; **customer→both on an existing person keeps person**;
  `type=supplier` on CREATE gives organization; an explicit `requestedKind=Person` on an organization row
  wins over every evidence arm; a bare row with `legal_form=sarl` derives organization.

**⚠️ OQ10 — OWNER RULING PENDING (F-3, dispute 1).** Arm 1 is not inert.
`2026_03_09_100001_add_customer_category_to_partners.php:26-30` set `customer_category = 'business'` for
**every** `type IN ('customer','both')` partner existing then, recording no provenance and no timestamp, so
in tenants provisioned before 2026-03-09 that arm reclassifies every legacy customer — walk-ins included —
as an organization. The r1 gate is right that **no date guard is binding**: D-H0-1 (`LEDGER.md:208`) records
the heuristic with no date amendment, and `partners.created_at`
(`2025_11_30_052119_create_partners_table.php:27`) records partner creation, not migration time. Owner to rule:
- **(a) ack-1 heuristic verbatim** — pre-2026-03-09 tenants get their `'business'` customers bulk-set to
  organization, operator-correctable through the M3 nature filter.
- **(b) date guard**, cutoff `2026-03-09` UTC compared per row against `created_at`. False positives:
  partners created between that file's date and that tenant's actual deploy, which keep their
  manufactured `'business'` and are still classified organization. False negatives: none.
**Orchestrator recommends (b). If OQ10 is unruled at dispatch, implement (a)** and record the deviation
in `owes_parent`. Either way, report the affected row count per local tenant in the M1 register.

**M1.2 Migration A — additive only** (`apps/api/database/migrations/tenant/<next free timestamp>_add_party_kind_to_partners.php`;
choose the timestamp at dispatch and collision-check with `ls apps/api/database/migrations/tenant | tail`, F-15).
**This migration adds NO `NOT NULL` and NO CHECK — both land in Migration B at the end of M2** (F-2).

0. **Guards (F-17).** Every step is preceded by an explicit PG catalog probe, never a drop-and-re-add:
   columns via `information_schema.columns` (name, `data_type`, `is_nullable`, `column_default`),
   constraints via `pg_constraint`, indexes via `pg_indexes` / `CREATE INDEX IF NOT EXISTS`. Branch on
   `DB::connection()->getDriverName()` for the non-pgsql path.
1. **`party_kind varchar(20)` NULLABLE, NO default** (F-1), `after('customer_category')`. A default makes
   PostgreSQL fill every existing row with `'person'`, leaving the null-based backfill nothing to classify
   — that was r1's P0 defect.
2. Backfill with **SQL only** (a migration that boots domain classes is fragile under `tenants:migrate`):
   the organization arms first as
   `DB::table('partners')->whereNull('party_kind')->where(...)->update(['party_kind' => 'organization'])`
   in ladder order, then a final `whereNull('party_kind')->update(['party_kind' => 'person'])`. Arm 1 is
   shaped by the OQ10 ruling.
3. **Recompute `customer_category` from the final kind, in the same migration** (F-4): person→`individual`,
   organization→`business`. This is a **data correction** of a column the March-9 migration manufactured.
   State in the docblock and the M1 register that it changes the **mirror wire value** for reclassified rows
   and **never** the sealed bytes of any existing event (stored events are immutable and unread here).
4. **A "no NULLs remain" probe** on `party_kind` — assert, log the count, but do **not** `SET NOT NULL`.
4b. **`credit_account_enabled boolean NOT NULL DEFAULT false`** (N-3 — the persisted "credit account
   explicitly enabled" state, which no existing column provides). Backfill **`true` where
   `account_status = 'active'`**, so the POS mirror's `charge_account_enabled` wire value is **unchanged for
   every existing row**: today's predicate `is_active && account_status === Active`
   (`PosCustomerMirrorResource.php:30-32`, emitted `:50`) becomes
   `is_active && account_status === Active && credit_account_enabled`, and the backfill makes the third
   conjunct true exactly where the second already was. **A schema addition inside Phase 2's own lane, NOT an
   owner gate** — r2 was right that the derived predicate cannot express approval; the earlier "STOP rather
   than add a column" instruction is withdrawn.
5. New nullable columns: `legal_form varchar(50)`; `preferred_locale varchar(10)` (R-B; mirror
   `2025_11_30_214948_add_personal_info_to_tenants_table.php:27` but **nullable, no default** — NULL =
   "inherit the company default"); the four OQ1 person columns `date_of_birth date`, `gender varchar(10)`,
   `national_id varchar(50)`, `mobile varchar(50)`; and (R-C) `phone_normalized varchar(30)`,
   `email_normalized varchar(255)`.
6. Indexes via `CREATE INDEX IF NOT EXISTS` on pgsql: `(tenant_id, company_id, party_kind)`,
   `(tenant_id, phone_normalized)`, `(tenant_id, email_normalized)`.
7. **Backfill `email_normalized` in SQL** (`lower(trim(email))`) — pure SQL, safe. **Do NOT backfill
   `phone_normalized` here**; it needs the normalizer. Ship an idempotent chunked console command
   `partners:backfill-normalized-contact-points` and record it in `owes_parent` (§4, F-18).
- `down()` reverses in mirror order.
- Tests (red first, **on PostgreSQL**, F-17): each ladder arm lands where OQ10/§8.1 says, over
  representative legacy rows including the March-9 `'business'` population (F-1); `customer_category` is
  coherent with `party_kind` for every row afterwards (F-4); **the `PosCustomerMirrorResource` output for
  every demo-tenant partner is byte-identical before and after, pinned as a golden-JSON comparison** (N-3);
  a **normal second invocation** is a no-op;
  **recovery from each partial state** (column added but not backfilled; backfilled but index missing).

**M1.3 `ContactPointNormalizer` (R-C), three implementations, one fixture.**
- **No phone library exists in this repo** — verified: zero `libphonenumber` hits in
  `apps/api/composer.json`, `apps/pos/package.json`, `apps/web/package.json`; the only normalizer is
  Loyalty's digit-strip (`LoyaltyMember.php:112-118`). A dependency would put byte-identity at the mercy of
  two independently-versioned ports — **do not add one**; write a deterministic table-driven one.
- Server: `apps/api/app/Shared/Domain/Validation/ContactPointNormalizer.php`, beside and shaped like
  `CountryTaxNumberRules.php` (static, pure, never throws, `null` when it cannot normalize).
  `normalizePhone(?string $raw, ?string $defaultRegion): ?string` — keep a leading `+`, map leading `00` to
  `+`, strip every other non-digit, and with no `+` prefix the default region's dialling code after removing
  at most one trunk `0`. Region table at minimum TN/FR/IT/DE/SA/GB/MA/DZ. `normalizeEmail()` = trim + lowercase.
- Device `apps/pos/src/lib/customer/contactPointNormalizer.ts`, web `apps/web/src/lib/contactPointNormalizer.ts`
  — byte-identical output required.
- **Shared fixture:** `apps/api/tests/Fixtures/Party/contact-point-normalizer-cases.json`, read by the PHPUnit
  test and both Vitest suites; cross-app precedent
  `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.parity.test.ts:34`. Cover TN `20 123 456`,
  `+216 20 123 456`, `0021620123456`, FR `06 12 34 56 78`, an already-E.164 value, an unnormalizable short
  string, empty, and an email with whitespace and mixed case.
**Amendment A-1 to spec §5.4 — device UUID seeding stays in Phase 4 (N-8; orchestrator sequencing
decision, NOT an owner ruling; the parent records it in LEDGER at merge).**
Spec §5.4 (`2026-08-23-party-contact-target-model-research.md:330-342`) lists
`apps/pos/src/components/customers/customerAttachUtils.ts` as a normalizer site. Phase 2 ships the
byte-identical normalizer **on the device** and `customerAttachUtils` **uses it for DISPLAY and for
phone/email matching**, but **does NOT change the `hashCustomerUuid` seed** (`:26-39`, which content-hashes
`tenant|company|name|phone|email`). Reason: re-seeding the hash re-mints the `client_customer_uuid` of
every already-enqueued pending customer, which touches the **alias / idempotency contract**
(`PosPendingCustomerController.php:44,124,191`) that **Phase 4 owns** together with the dedup ladder and
`customer_aliases`. Splitting the hash change from the alias-migration work that must accompany it is how
you strand offline customers. Phase 4 lands the versioned-UUID path with alias/idempotency tests.
**Record under `owes_parent` as a LEDGER row** (§4).

**M1.4 Model + DTO.** `apps/api/app/Modules/Partner/Domain/Partner.php` — add the new columns to
`$fillable` (around `:102`); cast `party_kind` → `PartyKind`, `legal_form` → `LegalForm`, `gender` →
`PartyGender`, `date_of_birth` → `date` in `casts()` (`:147-151`). `PartnerData`
(`apps/api/app/Modules/Partner/Application/DTOs/PartnerData.php:20-60` constructor, `fromModel` from
`:63`) gains `party_kind`, `legal_form`, `preferred_locale`, `date_of_birth`, `gender`, `national_id`,
`mobile`. Then `php artisan typescript:transform` and commit the generated output.

**M1 browser gate** (`e2e/session-h/m1-*.spec.ts`, :5174 with `VITE_API_PROXY_TARGET` → :8011, and :8011
direct): (i) `GET /partners?per_page=5` and assert every **pre-existing** row carries a non-null
`party_kind` in the documented value set — **this is also the :8011 marker** (F-16); (ii) `POST /partners`
**omitting** `party_kind` **succeeds and the new row's `party_kind` is legitimately NULL** — say this
plainly: at M1 the column is nullable with no default and **no writer sets it yet**
(`CreatePartnerRequest.php:66-80` has no `party_kind`; `PartnerController.php:207-219` spreads only
validated fields), so demanding a coherent non-null pair here would be demanding M2's work. **The
"omitted kind derives to a coherent pair" assertion belongs to the M2-end gate** and is stated there
(F-2, r2 PARTIAL); (iii) screenshot the customers list rendering unchanged (M1 is invisible to the UI).

**M1 review lenses:** `tenancy-authz`, `imports`.

---

### M2 — write paths, identity policy, derived `customer_category`, events, then Migration B

**M2.1 `PartyIdentityPolicy` — the centralized final-state policy (F-5).** Field-level
`prohibited_unless` rules on a FormRequest validate only *submitted* keys; they cannot clear a persisted
VAT number when an existing organization is edited into a person, and they do not cover POS, imports,
seeders, Marketplace or Cart. Introduce
`apps/api/app/Modules/Partner/Domain/Policies/PartyIdentityPolicy.php`, applied **inside `PartnerService`
on create AND update**, operating on the **merged final state**, not the incoming diff:
- **`preferred_locale` — implement R-B as written: a region-tagged BCP-47 tag from a closed,
  country-seeded list** (N-4; the r2 bare-`en|fr|ar` contract is withdrawn — binding R-B
  (`ASSESSMENT-crm-seam-party-model-2026-08-29.md:33-40`, incorporated by D-H0-1 `LEDGER.md:208`) is not
  amendable by code evidence). **Verified source, found in r3:** the tenant `countries` table carries
  `default_locale varchar(10)` (`2025_12_01_192409_create_countries_table.php:22`), seeded per country in
  `database/seeders/CountriesSeeder.php` across 40 countries — TN `fr_TN` (`:27`), FR `fr_FR` (`:42`),
  DE `de_DE` (`:59`), IT `it_IT` (`:74`), GB `en_GB` (`:209`), SA `ar_SA` (`:286`), MA `fr_MA` (`:453`),
  DZ `fr_DZ` (`:468`).
  - **Two real gaps to handle, not hide.** (a) The seeder uses the **POSIX underscore** form (`fr_TN`), not
    BCP-47 hyphens — normalize `_` → `-` and store the hyphen form. (b) `countries` holds **one** locale per
    country, so **`ar-TN` has no row** though a Tunisian tenant needs it: build the closed list as
    *(normalized `countries.default_locale` for the active countries)* **∪ `{ar-TN}`** in one `PartyLocale`
    value object in the Partner domain, and state the `ar-TN` exception in the M2 register.
  - Nullable = **inherit the company default** (company `country_code` → `countries.default_locale`).
  - **UI-language fallback = the language subtag** — `fr-TN`/`fr-FR`/`fr-MA` → `fr`, `ar-TN`/`ar-SA` → `ar`,
    `en-GB` → `en` (`apps/web/src/lib/i18n.ts:154-158`, `LanguageCode` `:160`); a subtag outside `en|fr|ar`
    falls back to the company default, then `fr`.
- **OQ7** — `party_kind = person` ⇒ tax-identity fields (`vat_number`, tax status/exemption fields,
  `legal_form`) must be null in the final state. **On HTTP: reject** with a named validation error keyed
  to the offending field. **On import: clear the field and emit a row warning** (bulk migrations must not
  fail on it).
- **Kind transitions — ONE atomic contract, chosen (N-2; the r2 brief contradicted itself between M2 and
  M3).** **An EXPLICIT kind transition — `kindProvided === true` and `requestedKind !== existingKind` —
  AUTHORIZES the service to clear the incompatible persisted fields, atomically, in the same transaction.**
  org→person clears `vat_number`, `tax_id`, `tax_status`, the exemption fields (`exemption_reason`,
  `exemption_certificate_path`, `exemption_valid_until`), `legal_form`, `company_legal_name`,
  `business_registration_number`; person→org clears `date_of_birth`, `gender`, `national_id`. The response
  carries the **cleared field list in `meta`**, and the UI shows a confirm dialog **before** submitting
  ("Switching to Individual clears: …"). **A PATCH that keeps the same kind but sends an incompatible field
  still 422s** — clearing is authorized by the transition, never by a field's presence. M2 API tests and M3
  form/browser tests assert **both directions** against this one contract.
- **Every write path goes through it**: HTTP (`PartnerController`), POS pending-customer, the import
  upsert, seeders/factories, Marketplace and Cart.
- Tests: one per path, plus both transition directions, plus a raw-SQL-style negative proving the policy
  is the only gate that matters.

**M2.2 The five spec §8.3 enforcement points.**
1. **`CreatePartnerRequest`** (`apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php`):
   `party_kind` **required** `new Enum(PartyKind::class)` beside `'type'` at `:68`; the `required_without`
   phone/email pair on `:78-79` (spec §5.3; `PosPendingCustomerController.php:166-169` is the reference);
   accept `preferred_locale`, `legal_form` and the four person fields so M3's form submits are not
   silently dropped (`PartnerController.php:207` persists only validated keys, F-5). `customer_category`
   is **removed from the accepted input** — derived from now on.
2. **`UpdatePartnerRequest`**: `party_kind` `sometimes`; `name` `sometimes|required` (`:84` — today
   `'sometimes','string'` lets a caller blank it); the same accepted-field widening; drop
   `customer_category` (`:138`).
3. **`PosPendingCustomerController`** (`:82-94`): the create routes through `PartnerService` (M2.3) with
   `party_kind = Person` — the till only ever mints persons. The validation block at `:163-171` stays.
4. **`ImportType::Parties` + `PartiesRowMapper`** — deferred to **M4**; say so in the M2 register.
5. **`PartnerService::upsertWithTypeMerge`** (`PartnerService.php:54-107`): the `updateOrCreate` payload
   at `:88-104` gains `party_kind` from the **merged** state via `PartyKindDeriver` (F-6) and the derived
   `customer_category`. **Do not touch the matching ladder at `:67-69`** — dedup is Phase 4.

**M2.3 Every `Partner` insert routes through `PartnerService` (R-A, F-7).** Verified census
(`grep -rn "Partner::create\|Partner::query()->create\|Partner::updateOrCreate\|Partner::firstOrCreate" apps/api/app`):
`PartnerController.php:215`, `PartnerService.php:88`, `PosPendingCustomerController.php:82`,
`MarketplaceOrderService.php:222` and `:234`, `CartConversionService.php:87` — the last three bypass every
FormRequest and are rule-6 violations. Widen `App\Shared\Contracts\PartnerServiceInterface`
(`apps/api/app/Shared/Contracts/PartnerServiceInterface.php:12-38`) with typed create/update seams and move
all four non-service call sites onto them, making `PartnerService` the single place that (a) requires/derives
`party_kind`; (b) derives `customer_category`; (c) applies `PartyIdentityPolicy`; (d) **normalizes phone and
email at the write boundary** with the company `country_code` as default region; (e) dispatches the V2 events.
(d) is F-7: POS writes raw phone/email (`PosPendingCustomerController.php:87-88`), the import mapper passes
raw values (`PartiesRowMapper.php:21-22`) and the upsert persists them directly (`PartnerService.php:94-95`),
so request-level normalization covers nothing but HTTP. Lock it with a **grep-guard test** failing on any
`Partner::create(` / `Partner::query()->create(` outside `PartnerService` and the factory — precedent: the D16
no-live-lookup guard at `PosCoreReceiptProjection.php:87-92,361-366`. Normalization tests cover all five
paths: HTTP create, HTTP update, POS, import create, import update.

**M2.4 Derived `customer_category` — TWO wire boundaries, not one.** The spec (§1.5, §8.1) names only
`PosCustomerMirrorResource.php:41`; verified, there is a second and it is *directly* a sealed-payload input.
(a) `app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:42` —
`'customer_category' => $this->customer_category?->value` (device mirror, served via
`PosCustomerSyncController.php:59`); (b)
`app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:242` — the same read inside the
DEPOSIT_RECEIPT `customer` block this service seals **server-side**. Both read
`$partner->party_kind->toCustomerCategory()->value`. The stored column stays coherent via M2.3(b) and the
pair is constrained in Migration B (M2.7).

**Sealed-bytes proof the register MUST contain (fiscal-pos lens, F-9).** Deriving flips
`customer.customer_category` from `null` to `'individual'`/`'business'` on **future** events. Prove:
1. **No existing sealed event changes** — emission-time only; nothing rewrites stored bytes.
2. **Key sets untouched**, arrays pinned in the test — **labels corrected in r3 (N-10)**: server
   `FiscalPayloadConstraintValidator.php:701` (`validateDepositReceiptCustomer`, `:698`), `:1725`
   (**ACCOUNT_PAYMENT** — `validateAccountPaymentCustomer`, `:1722`, 8 keys) and `:1951`
   (**ACCOUNT_CHARGE** — `validateAccountChargeCustomer`, `:1948`, 9 keys incl. `account_identifier`);
   device `FiscalEventEngine.ts:2144,2185`. Retain both key-set assertions.
3. **The `'business'` literal is byte-identical** to what the coherence rule expects —
   `FiscalPayloadConstraintValidator.php:1941-1943` ↔ `FiscalEventEngine.ts:2095-2097`. The spec cites this
   rule at `:1725-1729`; at `a33b01354` `:1725` is a key set and the rule is at `:1941-1943` — cite the real line.
4. **PHP proof for DEPOSIT_RECEIPT** — seal for an organization partner; canonical bytes byte-identical to
   the same payload built with a literal `'business'` (no whitespace, casing or key-order drift).
5. **Device proofs for ACCOUNT_CHARGE and ACCOUNT_PAYMENT** — these seal in **`apps/pos`, not `apps/web`**
   (r1's `apps/web/src/lib/pos/offline/…` paths do not exist). Real files:
   `apps/pos/src/lib/accountCharge/accountChargeService.ts` (`buildAccountChargePayload` `:319`, customer
   block `:364`) and `apps/pos/src/lib/offline/accountPaymentService.ts` (`buildAccountPaymentPayload` `:204`,
   `buildCustomer` fed at `:235`, `customer_category` `:145`). Vitest canonical-bytes/hash tests comparing a
   mirrored organization against literal-`'business'` input.
6. **Pin the frozen fixtures** unchanged: the SALE_RECEIPT goldens and the ACCOUNT_PAYMENT `'retail'` literal
   at `apps/pos/src/lib/fiscal/payloads/AccountPaymentPayload.ts:116` (owner-routed under D-H0-1 ack 4 —
   **do not touch it**; r1's `:172` is wrong).
7. **Device stays at v67** (`apps/pos/src/lib/db/migrations.ts:1177,2163`).
Any failure among these — **STOP** (owner gate).

**M2.5 Events (R-A) — brief correction (F-8).** The r1 draft called these "Laravel events, NOT Spatie
event-sourcing". **Wrong:** `apps/api/app/Shared/Domain/Events/DomainEvent.php:7,16` shows
`abstract class DomainEvent extends Spatie\EventSourcing\StoredEvents\ShouldBeStored`, so every domain event
dispatched through Laravel is **also persisted to `stored_events`** by Spatie's wildcard subscriber. So:
- Rule 8 is stricter than assumed: `PartnerCreated` / `PartnerUpdated` / `PartnerDeleted`
  (`apps/api/app/Modules/Partner/Domain/Events/`) are frozen **and their stored payloads are history**.
- Add `PartnerCreatedV2` / `PartnerUpdatedV2` — V1 fields **plus** `partyKind`, `legalForm`,
  `preferredLocale`; names `partner.created.v2` / `partner.updated.v2`; same `DomainEvent` base;
  `getAuditPayload()` as `PartnerCreated.php:38-50` does.
- Dispatch from `PartnerService` via **typed create/update/delete APIs**, not the controller
  (`PartnerController.php:230,310,382`) — R-A's point: import-, POS-, marketplace- and cart-created parties
  emit nothing today. `PartnerDeleted` needs no V2 but its dispatch moves too, and its listener registration
  (`app/Providers/EventServiceProvider.php:108-109` → `CloseOwnershipsOnPartnerDeleted`) must keep working —
  prove it.
- Add `ContactCreated` / `ContactUpdated` / `ContactDeleted` under `app/Modules/Contact/Domain/Events/`,
  dispatched from `ContactService` (`ContactService.php:18-20,28`). **`ContactService` has no `delete`** —
  add one, move the controller's bare `$contact->delete()` (`ContactController.php:217`) behind it with the
  **reference-census guard** spec §8.3-10 asks for, modelled on `PartnerController.php:361-378`; preserve the
  existing transactional boundaries.
- **Tests assert both** Laravel dispatch **and** `stored_events` persistence (class + payload) per event.
  These events have **no listeners yet** — they are the CRM's future subscription seam, not a feature.

**M2.6 Seeders and factories — they go through the service too (N-5).** The r2 brief said "every path" and
then exempted seeders; R-A explicitly requires seeder-created parties to emit
(`ASSESSMENT-crm-seam-party-model-2026-08-29.md:37`), so the exemption is withdrawn.
- **Production seeders route through `PartnerService`** (or a thin `PartnerSeedingService` that applies
  `PartyIdentityPolicy`, derives the kind and emits the V2 events):
  `seeders/TunisianParapharmacySeeder.php:322,348,366,383`, `seeders/DemoPharmacySeeder.php:827,839,1624`,
  `seeders/DemoTenantSeeder.php:967`, `seeders/CoffeeShopSeeder.php:1085,1099`,
  `seeders/TaxRecoverabilityTestDataSeeder.php:131,146`.
- **`factories/PartnerFactory.php:33-64`** may keep constructing directly (it is the test seam), but its
  states must be **coherent**: default `Organization` (pairing with the existing `'type' => Both` at `:51`),
  an `organization()` alias, and a **`person()` state that also clears the tax identity** (`vat_number`,
  `legal_form`, `company_legal_name`, `business_registration_number` all null) so no factory row can violate
  the M2.7 composite CHECK or OQ7.
- **Expanded grep guard (N-5)** — the M2.3 guard covers **all** creation forms: `Partner::create(`,
  `Partner::query()->create(`, `Partner::firstOrCreate(`, `Partner::query()->firstOrCreate(`,
  `Partner::updateOrCreate(`, `Partner::query()->updateOrCreate(`, `new Partner(` and `Partner::factory(` —
  allowed only inside `PartnerService` / `PartnerSeedingService` and `PartnerFactory`. Verified r3 census of
  `Partner::factory()` consumers that stay legal as test factories: `factories/VehicleOwnershipFactory.php`,
  `factories/PaymentFactory.php`, `factories/DocumentFactory.php`,
  `factories/Scheduling/AppointmentFactory.php`, `factories/Workshop/WorkOrderFactory.php`;
  `seeders/ParapharmacySeeder.php:1229,1241,1256` and `seeders/DatabaseSeeder.php:375-405` are
  factory-mediated — confirm the factory default covers them rather than converting them.
- Give the demo tenants a realistic mix (walk-in persons + organizations) for M3's badge and filter.

**M2.7 Migration B — constraints, at the END of M2** (`…_constrain_party_kind_on_partners.php`; timestamp
chosen at dispatch). Only now that every writer supplies `party_kind` is it safe (F-2):
- **Rerun BOTH halves of Migration A first (N-6):** the `party_kind` classification arms **and** the
  `customer_category` recomputation (plus the `credit_account_enabled` backfill), for rows an old worker
  wrote between the two migrations — `PartnerController.php:215-219` can still insert with neither field
  after Migration A. Rerunning only the kind arms would set `party_kind='person'` while leaving
  `customer_category` NULL, and the composite CHECK would then fail on deploy.
- Then **assert zero NULL `party_kind` and zero incoherent `(party_kind, customer_category)` pairs**
  immediately before `SET NOT NULL` and before creating any CHECK.
- Exact CHECKs, guarded by `pg_constraint` probes (F-12, F-17): `party_kind IN ('person','organization')`;
  `gender IN ('male','female','other') OR gender IS NULL`; `legal_form` against `LegalForm::values()`;
  and the **composite pair CHECK** (F-4):
  `(party_kind='person' AND customer_category='individual') OR (party_kind='organization' AND customer_category='business')`.
  Plus `COMMENT ON COLUMN` for each new column.
- **`tests/Architecture/EnumCheckParityTest.php` fails on any new enum-backed column without an exact
  CHECK** (`:19-42`, baseline `tests/Architecture/baselines/enum-check-parity-baseline.json`, PG-only `:200`).
  Run it on PG; the baseline only shrinks — never add an acknowledgement for a column you land in this diff.
- Tests **on PostgreSQL**: NOT NULL rejects an omitted kind; each CHECK rejects an out-of-set value; the
  composite CHECK rejects an incoherent pair via raw SQL; second run is a no-op; and the **inter-migration
  legacy-write test** (N-6) — run Migration A, insert a row the pre-M2 way with neither `party_kind` nor
  `customer_category`, run Migration B, assert it succeeds and the row is coherent.

**M2 browser/API gate** (`e2e/session-h/m2-*.spec.ts`, :8011), run **after Migration B**: (0) **the
assertion moved here from M1 (F-2)** — a create reaching `PartnerService` without an explicit kind (the
import path, since HTTP now requires it) lands a **coherent non-null `(party_kind, customer_category)`
pair**; (i) `POST /partners` without `party_kind` → 422 naming `party_kind`; (ii) `POST /partners` with `party_kind=person` **and** a `vat_number` → 422
(OQ7); (iii) `PATCH` an existing organization that has a `vat_number` to `party_kind=person` → 422 naming
the persisted field (F-5); (iv) `POST /partners` with `party_kind=person` and neither phone nor email → 422;
(v) create an organization, then hit the POS customer-sync endpoint and assert the mirror row's
`customer_category = 'business'` while the request never sent one, and that `phone_normalized` was stored;
(vi) `POST` the POS pending-customer endpoint → the created partner is `party_kind = person` with a
normalized phone. Marker: the `party_kind` field on every partner response.

**M2 review lenses:** `fiscal-pos`, `imports`.

---

### M3 — UI: Nature, affordance gates, person panel, AR i18n

**M3.1 The Nature field (OQ6).** Phase 1 M2 already relabelled `PartnerForm.tsx`'s `customer_category`
select to "Nature" and made it required on create. **This milestone repoints that same control at
`party_kind`**: `sales:partners.nature.label` (EN/FR "Nature", AR "النوع"), options `…nature.person`
(Individual / Particulier / فرد) and `…nature.organization` (Company / Société / شركة). Values move from
`individual|business` to `person|organization`; `customer_category` leaves the form payload entirely. The
role select keeps its "Type" label (OQ6). `grep -n 'partners.nature' apps/web/src apps/web/src/locales`
first — reuse Phase-1's keys, do not mint a parallel block. Default `organization` on
`/purchases/suppliers/new`, no default on `/sales/customers/new`.

**M3.2 Affordance gates — §7.5 as amended by OQ7 ONLY (F-13, dispute 2).** The B2B block is **not** one
gate. Split it:
- **Tax identity sub-block — `organization` only.** VAT / Matricule fiscal (`PartnerForm.tsx:556-565`), the
  tax-status + exemption block (`:604-663`, `tax_status` register at `:611-616`), and `legal_form`. OQ7 is
  what makes this organization-exclusive.
- **Credit / commercial sub-block — `organization` OR a person with credit explicitly enabled.** Spec §7.5
  (`2026-08-23-party-contact-target-model-research.md:468,473`) keeps this branch and **OQ7 does not remove
  it**. Payment terms, credit limit, discount %, invoice consolidation and bank accounts live in
  `apps/web/src/features/partners/components/**B2BFieldsSection.tsx**` (note the `components/` segment — the
  Phase-1 brief's `features/partners/B2BFieldsSection.tsx` does not exist; its anchors move in the Phase-1
  merge — N-9). **The approval state is the new persisted `credit_account_enabled` column** of Migration A
  step 4b (N-3). The r2 claim that the existing derivation could express approval was wrong: today's
  predicate is only `is_active && account_status === Active` (`PosCustomerMirrorResource.php:30-32`,
  emitted `:50`) — it ignores `credit_limit` and any approval, so **every** active person already reads as
  charge-enabled. Render the credit sub-block when `party_kind === 'organization'` **or**
  `credit_account_enabled === true`, and expose an **"Enable credit account" toggle** — shown for persons,
  and shown pre-enabled for organizations (the backfill sets it `true` wherever `account_status='active'`).
- **Person panel — `person` only:** `date_of_birth`, `gender`, `national_id`, `mobile` (OQ1). CIN is
  **never required**. New i18n block `sales:partners.person.*`, EN/FR/AR.
- **`preferred_locale` select** — both kinds, nullable, options = the M2.1 country-seeded BCP-47 list
  (N-4), labelled in the user's language, with an explicit "inherit company default" empty option.
- **Kind transitions — the M2.1/N-2 contract, UI half.** RHF retains values for unmounted fields and
  `onSubmit` spreads the whole `data` object into the payload (`PartnerForm.tsx:397-420` — the `cleaned`
  spread), so switching Nature must `unregister`/reset the now-incompatible fields. But the *authority* to
  drop **persisted** values is the explicit kind change, not the omission: before submitting a transition
  the form shows a **confirm dialog listing the fields that will be cleared** ("Switching to Individual
  clears: VAT number, Tax status, Legal form…"), and after the response it reports the server's
  `meta.cleared_fields`. **Add both transition tests** (organization→person, person→organization), each
  asserting the dialog content, that the submitted payload carries no incompatible field, and that
  `meta.cleared_fields` matches what the dialog promised.
- **`CreditLimitWarning` (F-14):** Phase 1 converts it to the decimal-safe helpers (`parseFloat` at
  `apps/web/src/features/partners/components/CreditLimitWarning.tsx:22,23,61,65` today, rule 19). **Phase 2
  only consumes it** — if you find it still on `parseFloat` when you arrive, that is a Phase-1 gap: report
  it and do not wire it until it is fixed.

**M3.3 List page.** `apps/web/src/features/partners/PartnerListPage.tsx` (460 lines): a **Nature badge**
column (design tokens, no hardcoded colours) and a **nature filter** beside the existing status
`FilterTabs` (`:211-215,253`). Backend: add `party_kind` to `$filterConfig` in
`apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:69-110` — read the filter
trait's `applyFilters` and reuse whichever exact-match type it already supports (the block currently uses
`computed`, `boolean`, `text`, `range`); do not invent a type. Phase 1 replaces this file's hand-rolled
`Partner` interface with the generated `PartnerData`; build on that.

**M3.4 `AddPartnerModal` — ordering hazard.** `apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx`
posts to `/partners` at `:213`. Once M2 makes `party_kind` required, **every inline add 422s** unless this
modal sends it. It ships a Nature control in this wave — not optional polish.

**M3.5 Arabic i18n — the structural fix (ack 2), with one spec correction.**
- **`apps/web/src/locales/ar/crm.json` does not exist** (verified: 34 AR namespace files, no `crm.json`),
  and `apps/web/src/lib/i18n.ts:428` hardwires `crm: enCrm` in the **ar** resource block — every CRM screen
  renders English inside an RTL layout. Create the file (mirroring `en/crm.json`'s `companies`/`contacts`/`title`,
  minus whatever Phase 1 M3 deletes with the Companies surface) and wire it at `:428` as `frCrm` is at `:250`.
- **The spec's stronger claim is stale — do not act on it.** §7.2 says the shallow `sales` merge renders the
  B2B section as raw keys with no English fallback. At `a33b01354` the AR block spreads `...enSales.partners`
  *before* `...arSales.partners` (`i18n.ts:302-303`) and deep-merges
  `countLabels`/`empty`/`messages`/`types`/`validation` (`:305-322`), so the sub-blocks AR lacks (`b2b`,
  `bankAccounts`, `contacts`, `paymentTerms`, `taxInfo`) fall back to **English**, not raw keys, and a
  key-by-key diff shows AR complete for every sub-block it defines. The defect is "English inside RTL": do
  not restructure the merge; author the missing AR sub-blocks plus the new
  `nature`/`person`/`legalForm`/`preferredLocale` blocks in EN, FR and AR in the same commits.
- Machine-authored AR needs a native read (ack 2, B-7 caveat): list every AR string you author in the lane
  report under one reviewable heading.

**M3 browser gate** (`e2e/session-h/m3-*.spec.ts`, :5174 proxied to :8011): (i) `/sales/customers/new` with
Nature = *Individual* — person panel appears; tax-identity block absent; submit and `GET` the partner
asserting `party_kind=person`, `customer_category=individual`, `vat_number` null; (ii) Nature = *Company* —
tax identity, `legal_form` and the credit sub-block appear, person panel does not; (iii) **transition test in
the browser:** open an organization with a `vat_number`, switch Nature to Individual, submit → the request
body carries no `vat_number` (assert on the intercepted request, not just the response); (iv)
`/sales/customers` — the Nature badge renders for a seeded person and organization and the nature filter
narrows the list (assert the request carries the filter and the row count changes); (v) the inline
Add-partner modal submits with Nature = Individual → 201, not 422; (vi) switch the UI to Arabic and
screenshot a CRM screen plus the partner form showing Arabic labels, RTL, and no raw `sales:partners.…` key
text. Screenshots for all six. Marker: `party_kind` present on the intercepted `/partners` responses.

**M3 review lenses:** `frontend-conventions`, `tenancy-authz`.

---

### M4 — Parties import: optional `party_kind` + `legal_form` + derivation warning

- `apps/api/app/Modules/Import/Domain/Enums/ImportType.php`: add `'party_kind'` and `'legal_form'` to the
  `self::Parties` optional-column list (`:109-122`) and its validation rules (`:169-180`) —
  `'party_kind' => ['nullable','in:person,organization']`, `'legal_form' => ['nullable', Rule::in(LegalForm::values())]`.
  **Touch only the `Parties` arm.** `Parties` and `Partners` are distinct schemas (`:91` / `:110`, `:169` /
  `:182`) and `Partners` is Session G's retirement lane — leave it, and note anything you find for Session G.
- `apps/api/app/Modules/Import/Services/PartiesRowMapper.php:15-29` — `toPartnerData()` maps `party_kind` and
  `legal_form` through; when `party_kind` is absent or blank the row is derived by `PartyKindDeriver`
  (M1.1), which returns `{kind, reason}`. **The mapper never re-derives a reason** (F-10).
- **OQ7 on the import path is clear-with-warning, not reject** (M2.1): a person row carrying `tax_id` has it
  cleared and warned, so a bulk migration does not fail.
- **N-7 regression case:** a bare `name` + `type=customer` row carrying `legal_form=sarl` and no
  `party_kind` must derive **organization** (reason `has_legal_form`) and import cleanly — it must not
  derive person and then be rejected or silently stripped.
- **Warnings, non-blocking**, from `ImportService::importParty()` (`ImportService.php:438-447`) via
  `ImportService::addRowWarning($row, 'party_kind_derived', <detail naming the reason code>)` (`:93-99`) and
  `'party_person_tax_id_cleared'` for the OQ7 case. Mirror the message style of
  `PartiesBalancesPhase.php:243-262`.
- **Templates:** `getOptionalColumns()` feeds template/sample generation in
  `Import/Presentation/Controllers/ImportController.php` and `Import/Services/MigrationWizardService.php` —
  confirm both pick the new columns up; if either hand-lists the Parties columns, fix it. Session G owns the
  CSV+XLSX locale templates (R8) — on a collision record it in `owes_parent`, do not edit Session G's files.
- **Tests, with the verified response semantics (F-11):** upload returns **201 with row-level failures**, not
  422 (`ImportController.php:205-207`; the existing `apps/api/tests/Feature/Import/PartiesImportTypeTest.php`
  asserts Created and inspects the row error). Assert: explicit `party_kind` honoured verbatim; absent column
  derives per arm with the right reason code; an invalid value → **201 + a row-level `party_kind` error**;
  `legal_form` persisted; the OQ7 clear-with-warning case.
- **Warning verification goes through the result workbook, not the errors endpoint** (F-11): `errors` filters
  to `is_valid = false OR import_error IS NOT NULL` (`ImportController.php:324-329`), so a **valid** row with
  a warning is invisible there. The workbook carries a `warnings` column
  (`Import/Services/ResultWorkbookService.php:61,74,114-122`), served by the download action at
  `ImportController.php:609-627`. **Do not add a new endpoint.**

**M4 browser/API gate** (`e2e/session-h/m4-*.spec.ts`, :8011): upload a small Parties CSV through the real
import endpoints — `party_kind=organization`; blank kind + `tax_id` (→ organization); bare
`name`+`type=customer` (→ person); blank kind + `legal_form=sarl` (→ organization, N-7); `party_kind=person`
carrying a `tax_id` (imports with the id cleared + warned) — then `GET` the five partners and assert their
`party_kind`, and **download the result workbook** asserting `warnings` carries `party_kind_derived` on
exactly the three derived rows and `party_person_tax_id_cleared` on the last. If no e2e helper can drive the
upload, substitute a PHPUnit Feature test at the HTTP layer and say so — never skip the assertion.

**M4 review lenses:** `imports`.

---

**Lane done when:** M1–M4 ACCEPT; `pnpm typecheck && pnpm lint` clean in `apps/web`; `pnpm typecheck` +
`pnpm vitest run src/lib/customer src/lib/fiscal src/lib/accountCharge src/lib/offline` clean in `apps/pos`;
PHPUnit by path green **on PostgreSQL** (incl. `tests/Architecture/EnumCheckParityTest.php`); PHPStan 8 +
Pint clean on touched PHP; `php tools/feature-lane-manifest-check.php` and
`apps/web/tools/audit-tanstack-keys.mjs` green; `packages/shared/types/generated.d.ts` regenerated and
committed; YAML updated; **branch unmerged and unpushed**.

---

## §4 — Reporting

Per milestone: register in `docs/handoff/reviews/session-h-phase2/<M-id>-r<n>.md` (the script writes it),
YAML updated with status / commit SHA / verdict path / fix_rounds, each register naming the **:8011 marker**
it asserted (F-16). At the end of the lane append a ≤25-line report to
`docs/sessions/session-H-party-model-2026-08-29/LANE-REPORT-h2-party-kind.md`: files changed, tests run with
results, the AR strings you authored (flagged for native review), anything for Session G, and `owes_parent`
— at minimum the `partners:backfill-normalized-contact-points` staging run, **Amendment A-1** (the
`hashCustomerUuid`
normalization deferral, the OQ10 option actually implemented, and any i18n baseline re-pin.

**F-18 — parent obligation, not lane-optional.** `docs/handoff/LEDGER.md:1` declares itself the sole
authoritative open-obligation register. **The parent lane must add a LEDGER row at merge** for the staging
`partners:backfill-normalized-contact-points` run, with owner, status and closure evidence. Recording it only
in `owes_parent` is insufficient — say so explicitly in the lane report so the parent cannot miss it.

## §5 — STOP conditions specific to this wave

1. Any change to a sealed payload **key set**, on either side.
2. Any canonical-bytes **value** change beyond the intended `customer.customer_category` derivation of
   M2.4 — and even that stops if any of its seven proofs fails.
3. Any need for a **POS device migration** / schema bump past v67, or any change to the `SALE_RECEIPT` /
   `ACCOUNT_CHARGE` / `ACCOUNT_PAYMENT` / `DEPOSIT_RECEIPT` key sets, the `b2b_facture_draft_requested`
   coherence rule, or the frozen `AccountPaymentPayload.ts:116` `'retail'` literal (spec §1.5/§8.5 say none
   is needed — a contrary finding is an architecture contradiction).
4. **OQ10 unruled at dispatch** (F-3): implement option (a), record the deviation, and STOP only if you
   find a third population the two options do not cover.
5. *(withdrawn in r3)* — `credit_account_enabled` (N-3) and the region-tagged `preferred_locale` list
   (N-4) are now **in-lane work**, not owner gates. Adding a *further* partners column beyond those two,
   or a locale tag with no `countries` row other than the sanctioned `ar-TN`, is still a STOP.
6. Any need for an i18n baseline re-pin, or an `enum-check-parity` baseline acknowledgement for a column
   landed in this diff (parent-owned at promotion — record it in `owes_parent`).
7. Discovering that Phase 1 is **not** merged into the base you were given, or that
   `docs/handoff/progress/session-h-phase2.progress.yaml` is absent.
8. Any pull toward Phase 3 (facture escalation), Phase 4 (dedup ladder, `pos_receipt_party_links`, contacts
   tab, tombstone merge) or Phase 5 (device contacts). In particular do **not** touch `PartnerService`'s
   matching ladder (`PartnerService.php:67-69`) or the `hashCustomerUuid` **seed** (Amendment A-1, M1.3) —
   wiring the normalizer into that file's display/matching helpers is in scope; re-seeding the hash is not.
9. Any owner-gated question, or fix rounds exhausted (4) on any milestone.
