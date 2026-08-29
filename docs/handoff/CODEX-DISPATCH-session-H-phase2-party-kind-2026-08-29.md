# Codex dispatch — Session H (B-18 party/contact program), **Phase 2: `party_kind`** (2026-08-29) — DRAFT

> **DRAFT — not dispatched.** The Session H orchestrator reviews and gates this brief, re-pins `base_sha`,
> and creates the worktree before handing it to Codex Desktop.

> ## ⚙️ EXECUTION MODE — SELF-REVIEWING WAVE (read this before anything else)
> This wave runs under **`docs/handoff/SELF-REVIEW-HARNESS.md`**. You do NOT hand back to a human
> between milestones. At the end of every milestone you run the adversarial review YOURSELF via
> `scripts/adversarial-review.sh` (it calls `claude -p --model opus`), read its register, and loop
> scoped fix rounds until ACCEPT — then move on.
> - **State file: `docs/handoff/progress/session-h-phase2.progress.yaml`** — read it first, update it
>   after every milestone (status, commit SHA, verdict path, fix_rounds). It is your resume point.
> - **Reviewer fallback:** if `adversarial-review.sh` exits **3** (tool error) twice in a row for one
>   milestone, write your OWN adversarial register (same lenses, numbered findings, `VERDICT:` line) to
>   the register path, set `reviewer_model: codex-fallback` on that milestone, and continue. The parent
>   re-gates every codex-fallback milestone with the Claude reviewer agents at merge.
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
when you start — **STOP**.

**Citation convention:** every path is repo-root-relative from `/Users/houssamr/Projects/syneriva/apps/erp`.
Line anchors below were verified against **`a33b01354`** (local `dev` tip on 2026-08-29, *before* the
Phase-1 merge). **Base = the `dev` tip AFTER the Phase-1 merge — the parent re-pins `base_sha` at
dispatch.** Phase 1 moves anchors in `PartnerForm.tsx`, `PartnerListPage.tsx`, `AddPartnerModal.tsx`,
`routes/index.tsx`, `ImportType.php` (Parties `code` rule) and `VehicleForm.tsx`. **Re-verify every
anchor with `grep -n` before editing — do not trust a line number blindly.**

| Lane | Worktree | Branch | Milestones |
|---|---|---|---|
| **h2-party-kind** | `.worktrees/h2-party-kind` | `feat/h2-party-kind` | M1 → M2 → M3 → M4, in order |

One lane, sequential — M2 depends on M1's enum + column, M3 on M2's request contract, M4 on M1's
derivation helper. Do not create the worktree yourself; the parent creates it dependency-ready
(vendor copied + `composer dump-autoload`, `.env` copied, `pnpm install --offline`). Do not `git stash`
(repo-global stash — forbidden in worktree lanes); never combine `--force` with `git push`.

---

## §0 — Read before writing code, in this order

1. `docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md` — **§1.5, §5.3, §5.4,
   §7.2–7.5, §8.1, §8.3, §8.4 (Phase 2 row), §8.5**. §8.1 + §8.3 are the authoritative scope.
2. `docs/handoff/LEDGER.md` row **D-H0-1** — the owner's rulings OQ1–OQ9 + acks 1–5. **BINDING; they
   override the spec where they differ.** Load-bearing here: OQ1 (four nullable person columns on
   `partners`, gated on `party_kind = person`; CIN never required) · OQ6 (nature field labelled
   **Nature**, options *Individual / Company* · *Particulier / Société* · *فرد / شركة*; the role field
   keeps **Type**) · OQ7 (a sole trader holding a matricule is an **organization** with
   `legal_form = 'personne_physique'`; a `person` **never** carries a tax id) · ack 1 (backfill
   heuristic) · ack 2 (AR i18n ships in the same commits).
3. `docs/handoff/ASSESSMENT-crm-seam-party-model-2026-08-29.md` **§4 R-A..R-D** — all four reservations
   are **in scope**. §5 lists what stays unbuilt: no consent table, no channel-identity table, no
   tags/segments, no campaign/message tables, no transport. Add no marketing surface.
4. `docs/handoff/CODEX-DISPATCH-session-H-phase1-2026-08-29.md` — what Phase 1 changed, so you extend it
   rather than re-do or revert it (especially §2 M2: the Nature select and the B2B NULL heuristic).
5. `apps/erp/CLAUDE.md` rules **3** (strict typing), **7** (types flow from backend), **8** (events
   immutable — V2, never edit), **9** (enums for status/type columns), **12** (route middleware),
   **13** (constructor injection only), **19** (precision); `docs/conventions/01`, `03`, `04`, `06`.
6. `docs/handoff/SELF-REVIEW-HARNESS.md`.

**Auto-deploy rule, load-bearing for M1.** A migration pushed to `origin/dev` auto-deploys
`tenants:migrate` on staging across every tenant DB. The M1 migration and its backfill must be
**self-guarding** (every step behind a `Schema::hasColumn` / `IF NOT EXISTS` / "already NOT NULL?" probe)
and **idempotent** (a re-run on a migrated tenant is a no-op, never an error). House template — guarded
PG `CHECK` + `COMMENT ON COLUMN` + backfill inside `up()`:
`apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:18-47`.

---

## §0.1 — Browser-level verification is a GATE for every milestone (owner requirement)

Same contract as Phase 1 §0.1 — **read it there and reuse it verbatim** (main-checkout stack on
:8010/:5173/PG :5433, demo tenant **PharmaBio Tunisie** `owner@pharmabio.tn` / `password`; worktree web
`pnpm dev --port 5174 --strictPort`, worktree API `php artisan serve --host=127.0.0.1 --port=8011`; specs
in `apps/web/e2e/session-h/<milestone>-*.spec.ts` with
`test.use({ baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5174' })`; screenshots →
`.playwright-mcp/session-h/<milestone>/`, cited by path in the register; API assertions via the Playwright
`request` fixture with a Bearer token from `POST /auth/login`, pattern
`apps/web/e2e/smoke/treasury-phase5a-outbound.smoke.ts:17-27,113-118`; the POS cannot be browser-driven,
so its gate is Vitest plus server-side mirror assertions; demo credentials only, never staging).

**One difference from Phase 1:** this phase changes the **API on every milestone**, so :8011 is mandatory
for M1–M4 and the worktree vite must proxy `/api` to :8011, not :8010. Verify the proxy before trusting a
green spec.

---

## §1 — Standing constraints for every milestone

- **No sealed-payload KEY changes anywhere.** The one deliberate *value* consequence in this phase is
  `customer.customer_category` becoming a derived non-NULL value on **future** events (§2 M2) — that is
  the spec's design (§1.5) and it must be proven byte-safe, not assumed. Everything else that touches
  canonical bytes is a STOP.
- **No POS device migration.** Device stays at **v67** (`apps/pos/src/lib/db/migrations.ts:2163`); the
  device `customers.customer_category` column is already `TEXT` nullable (`migrations.ts:1177`) and its
  value set is unchanged. If you find you need a device migration — **STOP** (spec §1.5 / §8.5 say none
  is needed; a contrary finding is an architecture contradiction).
- **Types flow from backend (rule 7):** never hand-edit `packages/shared/types/`. After changing
  `PartnerData`, run `php artisan typescript:transform` (with `CACHE_STORE=array` in the worktree) and
  commit the output (`packages/shared/types/generated.d.ts`, `PartnerData` at `:1832`).
- **`packages/shared` is types-only** — it cannot host the TS normalizer runtime (M1).
- **Frontend:** `t()` for every string, **EN + FR + AR keys in the same commit** (ack 2); design tokens
  for any colour class you touch; `tenantScopedKey` on every tenant-data query key; zod + RHF for forms.
- **Backend:** constructor injection only; PHPStan level 8 clean on touched files; Pint clean.
- **Tests:** TDD red-first. Backend tests by PATH only (`./vendor/bin/phpunit <file>`); **never the full
  suite**. Web/POS: `pnpm vitest run <dir>`; kill stray workers after (`pkill -f 'node (vitest'`).
- **At every commit:** `cd apps/api && php tools/feature-lane-manifest-check.php` — a new Feature test
  class needs a manifest ceiling raise.
- Commit prefix: `h2 M<m>.<s>:`. Small commits, one concern each.

---

## §2 — Milestones

### M1 — schema, enum, normalizer, DTO

**M1.1 `PartyKind` enum (rule 9).** New `apps/api/app/Modules/Partner/Domain/Enums/PartyKind.php`:
cases `Person = 'person'`, `Organization = 'organization'`, plus `values()` mirroring the shape of
`app/Modules/Partner/Domain/Enums/CustomerCategory.php:15-18`. It carries **two** helpers that are the
single source of truth for the rest of the phase — nobody else re-implements either:
- `toCustomerCategory(): CustomerCategory` — `Person → Individual`, `Organization → Business`.
- `public static function deriveFrom(array $attributes): self` — the ack-1 heuristic, exactly as spec
  §8.1: `customer_category === 'business'` → organization; any of `vat_number`, `company_legal_name`,
  `business_registration_number`, `credit_limit`, `payment_terms` non-null → organization;
  `type IN ('supplier','both')` → organization; else person.
  This same function is what the M1 migration backfill and the M4 importer must agree with — the
  migration cannot call app code (see M1.2), so pin the agreement with a Unit test that runs the SQL
  arms and the PHP arms over the same table of cases.

**⚠️ Heuristic hazard, verified — the reviewer must see this.** The first arm is not inert.
`2026_03_09_100001_add_customer_category_to_partners.php:27-30` already backfilled
`customer_category = 'business'` for **every** partner of `type IN ('customer','both')` existing then. In
any tenant DB provisioned before 2026-03-09 that arm classifies *every legacy customer* — walk-ins
included — as an organization. **Resolution (orchestrator amendment to ack 1, 2026-08-29 — binding):**
arm 1 trusts `customer_category = 'business'` **only for rows created on/after 2026-03-09**
(`partners.created_at >= '2026-03-09'`), i.e. rows where a user or an import set it deliberately; rows
older than that were bulk-backfilled and fall through to the data arms (vat_number / company_legal_name /
business_registration_number / credit_limit / payment_terms / type ∈ {supplier, both}). `deriveFrom()`
takes `created_at` as part of `$attributes` and applies the same date guard, so the PHP and SQL arms stay
one rule. Still operator-correctable; state the blast radius in the M1 register with a per-local-tenant
row count under each arm; M3's nature filter is how an operator finds and fixes the rest.

**M1.2 Migration** `apps/api/database/migrations/tenant/2026_08_29_1000XX_add_party_kind_to_partners.php`
(pick the next free timestamp). Self-guarding + idempotent + PG-safe, in this order:
1. `party_kind` `varchar(20)` **with `->default('person')`**, `after('customer_category')`, guarded by
   `Schema::hasColumn`.
2. Backfill with **SQL only** (a migration that boots domain classes is fragile under `tenants:migrate`):
   the organization arms first as `DB::table('partners')->whereNull('party_kind')->where(...)->update(...)`,
   then a final `whereNull('party_kind')->update(['party_kind' => 'person'])`.
3. `SET NOT NULL` (guarded by a "no NULLs remain" probe), then **`DROP DEFAULT`** — spec §8.1: the default
   exists only for the backfill, so a later insert that forgets `party_kind` fails loudly. Then the PG
   `CHECK (party_kind IN ('person','organization'))` as `DROP CONSTRAINT IF EXISTS` + `ADD` (the
   idempotent shape the customer_category migration uses) plus `COMMENT ON COLUMN`.
4. New nullable columns: `legal_form varchar(50)`; `preferred_locale varchar(10)` (R-B; mirror
   `2025_11_30_214948_add_personal_info_to_tenants_table.php:27`, but **nullable with no default** —
   NULL means "inherit the company default"); and the four OQ1 person columns `date_of_birth date`,
   `gender varchar(10)`, `national_id varchar(50)`, `mobile varchar(50)`.
5. Normalized contact points (R-C): `phone_normalized varchar(30)`, `email_normalized varchar(255)`.
6. Indexes, driver-branched — on pgsql use raw `CREATE INDEX IF NOT EXISTS` (Blueprint has no
   `hasIndex`): `(tenant_id, company_id, party_kind)`, `(tenant_id, phone_normalized)`,
   `(tenant_id, email_normalized)`.
7. **Backfill `email_normalized` in SQL** (`lower(trim(email))`) — it is pure SQL and safe. **Do NOT
   backfill `phone_normalized` in the migration**; it needs the normalizer. Ship an idempotent console
   command `partners:backfill-normalized-contact-points` instead, chunked, and record it in
   `owes_parent` as a staging step. Dedup itself is Phase 4 — do not touch `PartnerService`'s matching
   ladder in this phase.
- `down()` reverses in mirror order, dropping the constraint first.
- Tests (red first): each heuristic arm lands where spec §8.1 says; a second run is a no-op; an insert
  with no `party_kind` then fails; the CHECK rejects a third value.

**M1.3 `ContactPointNormalizer` (R-C), three implementations, one fixture.**
- **No phone library exists in this repo** — verified: zero `libphonenumber` hits in
  `apps/api/composer.json`, `apps/pos/package.json`, `apps/web/package.json`; the only normalizer is
  Loyalty's digit-strip (`LoyaltyMember.php:112-118`). A dependency would put byte-identity at the mercy
  of two independently-versioned ports — **do not add one**; write a deterministic table-driven one.
- Server: `apps/api/app/Shared/Domain/Validation/ContactPointNormalizer.php`, beside and shaped like
  `CountryTaxNumberRules.php` (static, pure, never throws, returns `null` when it cannot normalize).
  `normalizePhone(?string $raw, ?string $defaultRegion): ?string` — keep a leading `+`, map a leading
  `00` to `+`, strip every other non-digit, and when there is no `+` prefix the default region's dialling
  code after removing at most one national trunk `0`. Region table at minimum TN/FR/IT/DE/SA/GB/MA/DZ,
  default region = the company `country_code`. `normalizeEmail(?string $raw): ?string` = trim + lowercase.
- Device: `apps/pos/src/lib/customer/contactPointNormalizer.ts`. Web: `apps/web/src/lib/contactPointNormalizer.ts`.
  Byte-identical output required.
- **Shared fixture:** `apps/api/tests/Fixtures/Party/contact-point-normalizer-cases.json`, read by the
  PHPUnit test and by both Vitest suites. Cross-app fixture precedent to copy:
  `apps/pos/src/lib/fiscal/payloads/__tests__/RefundReceiptV4Payload.parity.test.ts:34` (`path.resolve`
  across the monorepo into `apps/api/tests/Fixtures/`). Cover at least: TN local `20 123 456`,
  `+216 20 123 456`, `0021620123456`, FR `06 12 34 56 78`, an already-E.164 value, an unnormalizable
  short string, empty, and an email with surrounding whitespace and mixed case.
- **DO NOT change the seed of `hashCustomerUuid`** (`apps/pos/src/components/customers/customerAttachUtils.ts:26-39`).
  It content-hashes `tenant|company|name|phone|email`; swapping in the normalized phone re-mints the
  `client_customer_uuid` of every already-enqueued pending customer, which is a data hazard against the
  server's `client_customer_uuid` uniqueness (`PosPendingCustomerController.php:44,124,191`). Spec §5.4
  lists that file as a normalizer site, but it belongs with the Phase-4 dedup work. Note the deferral
  in `owes_parent`.

**M1.4 Model + DTO.** `apps/api/app/Modules/Partner/Domain/Partner.php` — add the new columns to
`$fillable` (around `:102`); cast `party_kind` → `PartyKind`, `gender` → the gender enum,
`date_of_birth` → `date` in `casts()` (`:147-151`). `PartnerData`
(`apps/api/app/Modules/Partner/Application/DTOs/PartnerData.php:20-60` constructor, `fromModel` from
`:63`) gains `party_kind`, `legal_form`, `preferred_locale`, `date_of_birth`, `gender`, `national_id`,
`mobile`. Then `php artisan typescript:transform` and commit.

**Gender enum — judgement call, flag it.** `App\Modules\Contact\Domain\Enums\Gender` exists
(`apps/api/app/Modules/Contact/Domain/Enums/Gender.php:7-12`, `male|female|other`) but importing it from
Partner crosses a module boundary (rule 6). Declare
`App\Modules\Partner\Domain\Enums\PartyGender` with the same three values **and** a Unit test asserting
`PartyGender::values() === Gender::values()` so they cannot drift. If the reviewer argues for promoting
one enum to `App\Shared\Domain\Enums`, take the reviewer's call and say so.

**M1 browser/API gate** (`e2e/session-h/m1-*.spec.ts`, :8011): after migrating the worktree DB,
`GET /partners?per_page=5` and assert every row carries a non-null `party_kind` in the documented value
set; `POST /partners` with a body that omits `party_kind` still succeeds at this milestone (the required
rule lands in M2) but the row comes back with a derived value; screenshot the customers list rendering
unchanged (M1 must be invisible to the UI).

**M1 review lenses:** `tenancy-authz`, `imports`.

---

### M2 — the five write paths, derived `customer_category`, V2 events, seeders

**M2.1 The five enforcement points (spec §8.3 items 1–5), each verified:**

1. **`CreatePartnerRequest`** (`apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php`):
   `party_kind` **required** `new Enum(PartyKind::class)` beside `'type'` at `:68`; the
   `required_without` phone/email pair on `:78-79` (spec §5.3 — the back office rises to the till's
   standard, `PosPendingCustomerController.php:166-169` is the reference); the four person columns
   `prohibited_unless:party_kind,person`; `legal_form` `prohibited_unless:party_kind,organization`;
   **`vat_number` prohibited when `party_kind = person`** per OQ7 — so the spec §7.5 escape hatch ("or a
   `person` who declares themselves fiscally registered") is **overridden by OQ7 and must not be
   implemented**. `customer_category` is **removed from the accepted input** — it is derived from now on.
   Extend `prepareForValidation()` (`:38-48`, already normalizing the VAT number) to also write
   `phone_normalized` / `email_normalized`.
2. **`UpdatePartnerRequest`** (`.../UpdatePartnerRequest.php`): `party_kind` `sometimes`; `name`
   `sometimes|required` (`:84` — today `'sometimes','string'` lets a caller blank it); forbid clearing
   both contact channels at once; the same `prohibited_unless` set; drop `customer_category` from the
   accepted input (`:138`); same `prepareForValidation()` extension (`:49-64`).
3. **`PosPendingCustomerController`** (`apps/api/app/Modules/POS/Presentation/Controllers/PosPendingCustomerController.php:82-94`):
   the `Partner::query()->create([...])` gains `'party_kind' => PartyKind::Person` — the till only ever
   mints persons. The validation block at `:163-171` stays as it is (it is already the reference).
4. **`ImportType::Parties` + `PartiesRowMapper`** — deferred to **M4**, so M4 is not blocked by a
   half-done contract here. Say so in the M2 register.
5. **`PartnerService::upsertWithTypeMerge`** (`apps/api/app/Modules/Partner/Application/Services/PartnerService.php:54-107`):
   the `Partner::updateOrCreate` payload at `:88-104` gains `party_kind` (from `$data`, else
   `PartyKind::deriveFrom($data)`) and the derived `customer_category`. **Do not touch the matching
   ladder at `:67-69`** — the dedup rework is Phase 4.

**M2.2 Every `Partner` insert must supply `party_kind`** — once M1 drops the column default, a create
that omits it throws. Verified census of every write path (`grep -rn "Partner::create\|Partner::query()->create\|Partner::updateOrCreate\|Partner::firstOrCreate" apps/api/app`):
`PartnerController.php:215`, `PartnerService.php:88`, `PosPendingCustomerController.php:82`,
`MarketplaceOrderService.php:222` (supplier → organization) and `:234` (customer → derive),
`CartConversionService.php:87` (the literal `'Unknown Supplier'` → organization). The last three bypass
every FormRequest.

**Route them through the service (R-A).** Widen `App\Shared\Contracts\PartnerServiceInterface`
(`apps/api/app/Shared/Contracts/PartnerServiceInterface.php:12-38`) with a `createPartner(string $tenantId,
string $companyId, array $data)` seam and move all four non-service call sites onto it, so
`PartnerService` is the single place that (a) requires `party_kind`, (b) derives `customer_category`,
(c) dispatches the V2 events. This also repairs the rule-6 violation of POS, Marketplace and Cart
constructing a Partner model directly. Lock it with a **grep-guard test** failing on any
`Partner::create(` / `Partner::query()->create(` outside `PartnerService` and the factory — technique
precedent: the D16 no-live-lookup guard at `PosCoreReceiptProjection.php:87-92,361-366`.

**M2.3 Derived `customer_category` — there are TWO wire boundaries, not one.** The spec (§1.5, §8.1)
names only `PosCustomerMirrorResource.php:41`. Verified, there is a second, and it is *directly* a
sealed-payload input:
- `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:42` —
  `'customer_category' => $this->customer_category?->value` (the device mirror; consumed via
  `PosCustomerSyncController.php:59`).
- `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:242` —
  `'customer_category' => $partner->customer_category?->value` inside the DEPOSIT_RECEIPT `customer`
  block that this service seals server-side.
Both must read `$partner->party_kind->toCustomerCategory()->value`. A third path derives at write time
(M2.1/M2.2) so the stored column can never disagree; add an invariant test that no partner row can hold
an incoherent `(party_kind, customer_category)` pair.

**Sealed-bytes analysis the register MUST contain (fiscal-pos lens).** Deriving flips
`customer.customer_category` from `null` to `'individual'` / `'business'` on **future** DEPOSIT_RECEIPT
and ACCOUNT_CHARGE / ACCOUNT_PAYMENT events. Prove all five of:
1. **No existing sealed event changes** — the change is at emission time only; nothing rewrites stored bytes.
2. **The key set is untouched** on both sides: server `FiscalPayloadConstraintValidator.php:701` (deposit
   customer), `:1725` and `:1951` (account charge / payment customer); device `FiscalEventEngine.ts:2144,2185`.
3. **The `'business'` literal is byte-identical** to the value the coherence rule expects —
   `FiscalPayloadConstraintValidator.php:1941-1943` ↔ `FiscalEventEngine.ts:2095-2097`
   (`b2b_facture_draft_requested requires customer.customer_category=business`). Note that the spec cites
   this rule at `:1725-1729`; at `a33b01354` `:1725` is the ACCOUNT_CHARGE key set and the rule itself is
   at `:1941-1943`. Cite the real line.
4. **The device stays at v67** and its `customer_category` column stays `TEXT` nullable
   (`apps/pos/src/lib/db/migrations.ts:1177,2163`).
5. **A PHPUnit test that seals a payload for an `organization` partner and asserts the canonical bytes
   are byte-identical** to the same payload built with a literal `'business'` — i.e. the derivation adds
   no whitespace, no casing drift, no key reordering.
If any of the five fails — **STOP** (owner gate).

**M2.4 Events (R-A).** Rule 8: `PartnerCreated` / `PartnerUpdated` / `PartnerDeleted`
(`apps/api/app/Modules/Partner/Domain/Events/`) are frozen — never edited. Add
`PartnerCreatedV2` / `PartnerUpdatedV2` carrying the V1 fields **plus** `partyKind`, `legalForm`,
`preferredLocale` (event names `partner.created.v2` / `partner.updated.v2`), extending the same
`App\Shared\Domain\Events\DomainEvent` base and implementing `getAuditPayload()` the way
`PartnerCreated.php:38-50` does. Dispatch them from `PartnerService`, not from the controller
(`PartnerController.php:230,310,382`) — that is the whole point of R-A: import-, POS-, marketplace- and
cart-created parties emit nothing today. `PartnerDeleted` needs no V2 (it carries no party attributes)
but its dispatch moves to the service too, and its listener registration
(`app/Providers/EventServiceProvider.php:108-109` → `CloseOwnershipsOnPartnerDeleted`) must keep working —
prove it with a test. Also add `ContactCreated` / `ContactUpdated` / `ContactDeleted` under
`app/Modules/Contact/Domain/Events/`, dispatched from `ContactService` (`ContactService.php:18-20,28`);
the controller's bare `$contact->delete()` (`ContactController.php:217`) moves behind a
`ContactService::delete()`. **These events must have no listeners yet** — they are the CRM's future
subscription seam (assessment §4 R-A), not a feature.
These are Laravel domain events (`event(...)` + `Event::listen`), NOT Spatie event-sourcing aggregates —
verified: no Spatie projector or aggregate registration exists for `Partner`. Do not add one.

**M2.5 Seeders and factories.** All must set `party_kind` or they break the moment the default drops.
Verified census (paths under `apps/api/database/`): `factories/PartnerFactory.php:33-60` — default
`Organization`, pairing with the existing `'type' => Both` default at `:51`, plus `person()` /
`organization()` states beside `customer()` / `supplier()` / `both()`; `seeders/DemoPharmacySeeder.php:827,839,1624`;
`seeders/DemoTenantSeeder.php:967`; `seeders/CoffeeShopSeeder.php:1085,1099`;
`seeders/TunisianParapharmacySeeder.php:322,348,366,383`; `seeders/TaxRecoverabilityTestDataSeeder.php:131,146`.
`seeders/ParapharmacySeeder.php:1229,1241,1256` and `seeders/DatabaseSeeder.php:375-405` go through the
factory and are covered by its default — confirm, don't assume. Give the demo tenants a realistic mix
(walk-in persons + organizations) so M3's badge and filter have something to show.

**M2 browser/API gate** (`e2e/session-h/m2-*.spec.ts`, :8011): (i) `POST /partners` without `party_kind`
→ 422 naming `party_kind`; (ii) `POST /partners` with `party_kind=person` **and** a `vat_number` → 422
(OQ7); (iii) `POST /partners` with `party_kind=person` and neither phone nor email → 422; (iv) create an
organization, then `GET /pos/customers/sync` (find the route in `POS/Presentation/routes.php`) and assert
the mirror row's `customer_category = 'business'` while the request never sent one; (v) `POST` the POS
pending-customer endpoint and assert the created partner is `party_kind = person`.

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
`/purchases/suppliers/new`, no default on `/sales/customers/new` (carry Phase-1 M2's behaviour across).

**M3.2 Affordance gates — implement the §7.5 table against `party_kind`.** In
`apps/web/src/features/partners/PartnerForm.tsx` (832 lines at `a33b01354`; Phase 1 moves these):
- The B2B section render gate at `:667-676` (`customerCategory === 'business'` →
  `partyKind === 'organization'`); the section itself is
  `apps/web/src/features/partners/components/**B2BFieldsSection.tsx**` (`:19` is its signature) — note
  the Phase-1 brief cites it at `features/partners/B2BFieldsSection.tsx`, which **does not exist**; the
  real path has the `components/` segment.
- VAT / Matricule fiscal (`:556-565`) → organization only.
- Tax status + exemption block (`:604-663`, `tax_status` register at `:611-616`) → organization only.
- Phase 1's NULL-heuristic escape in the B2B gate is **removed** here: `party_kind` is NOT NULL after
  M1, so the heuristic has no remaining input. Deleting it is the correct outcome, not a regression —
  say so in the register.
- **New person panel**, rendered only when `partyKind === 'person'`: `date_of_birth`, `gender`,
  `national_id`, `mobile` (OQ1). CIN/`national_id` is **never required** (ruling). New i18n block
  `sales:partners.person.*`, EN/FR/AR.
- **New `legal_form` select**, organization only, options `sarl` / `suarl` / `sa` / `sas` /
  `personne_physique` / `association`, i18n-labelled, nullable. Values are a static list in the feature's
  constants — there is no country-defaults source for legal forms today; note that in the register so
  the seeded-settings rule is not silently violated later.
- **New `preferred_locale` select** (R-B), both kinds, nullable, options `fr-TN` / `ar-TN` / `fr-FR` /
  `en`, with an explicit "inherit company default" empty option.
- Wire `CreditLimitWarning.tsx` if Phase 1 has not already (`apps/web/src/features/partners/components/CreditLimitWarning.tsx`).

**M3.3 List page.** `apps/web/src/features/partners/PartnerListPage.tsx` (460 lines): a **Nature badge**
column (design tokens, no hardcoded colours) and a **nature filter** beside the existing status
`FilterTabs` (`:211-215,253`). Backend filter support: add `party_kind` to `$filterConfig` in
`apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:69-110` — read the filter
trait's `applyFilters` to pick the right `type` (the block currently uses `computed`, `boolean`, `text`
and `range`; use whichever exact-match type the trait already supports rather than inventing one). Phase
1 replaces this file's hand-rolled `Partner` interface with the generated `PartnerData`; build on that,
do not reintroduce a local interface.

**M3.4 `AddPartnerModal` — ordering hazard.** `apps/web/src/components/organisms/AddPartnerModal/AddPartnerModal.tsx`
posts to `/partners` at `:213`. Once M2 makes `party_kind` required, **every inline add 422s** unless this
modal sends it. It must ship a Nature control in the same wave — this is not optional polish. Phase 1
already rebuilt its payload against `CreatePartnerRequest`; extend that payload, do not rebuild it again.

**M3.5 Arabic i18n — the structural fix (ack 2), with one spec correction.**
- **`apps/web/src/locales/ar/crm.json` does not exist** (verified: 34 AR namespace files, no `crm.json`),
  and `apps/web/src/lib/i18n.ts:428` hardwires `crm: enCrm` inside the **ar** resource block — so every
  CRM screen renders English inside an RTL layout. Create the file (mirroring `en/crm.json`'s
  `companies` / `contacts` / `title` keys, minus whatever Phase 1 M3 deletes with the Companies surface)
  and wire it at `:428` exactly as `frCrm` is wired at `:250`.
- **The spec's stronger claim is stale — do not act on it.** §7.2 says the shallow `sales` merge makes
  the B2B section "render as raw key strings with no English fallback". At `a33b01354` the AR block
  spreads `...enSales.partners` *before* `...arSales.partners` (`i18n.ts:302-303`) and deep-merges
  `countLabels`/`empty`/`messages`/`types`/`validation` (`:305-322`), so the sub-blocks AR lacks
  (`b2b`, `bankAccounts`, `contacts`, `paymentTerms`, `taxInfo`) fall back to **English**, not raw keys —
  and a key-by-key diff shows AR complete for every sub-block it does define. The real defect is
  "English inside RTL". Do not restructure the sales merge; author the missing AR sub-blocks plus the new
  `nature` / `person` / `legalForm` / `preferredLocale` blocks in EN, FR and AR in the same commits.
- Machine-authored AR needs a native read (ack 2, B-7 caveat): list every AR string you author in the
  lane report under a heading the owner can review in one pass.

**M3 browser gate** (`e2e/session-h/m3-*.spec.ts`, :5174 → :8011): (i) `/sales/customers/new` — choose
*Individual*: the person panel appears, the B2B block, VAT field and tax-status block are all absent;
submit and `GET` the partner on :8011 asserting `party_kind=person` and a derived
`customer_category=individual`; (ii) choose *Company*: B2B block, VAT, tax status and `legal_form` all
appear, the person panel does not; (iii) `/sales/customers` — the Nature badge renders for a seeded
person and a seeded organization, and the nature filter narrows the list (assert the request carries the
filter and the row count changes); (iv) open the inline Add-partner modal from a document screen, submit
with Nature = Individual → 201, not 422; (v) switch the UI to Arabic and screenshot a CRM screen plus the
partner form showing Arabic labels, RTL, and no raw `sales:partners.…` key text. Screenshots for all five.

**M3 review lenses:** `frontend-conventions`, `tenancy-authz`.

---

### M4 — Parties import: optional `party_kind` + `legal_form` + derivation warning

- `apps/api/app/Modules/Import/Domain/Enums/ImportType.php`: add `'party_kind'` and `'legal_form'` to the
  `self::Parties` optional-column list (`:109-122`) and to its validation rules (`:169-180`) —
  `'party_kind' => ['nullable','in:person,organization']`, `'legal_form' => ['nullable','string','max:50']`.
  **Touch only the `Parties` arm.** The `Partners` case (`:110` required, `:124` optional, `:182` rules)
  is Session G's retirement lane — leave it, and note anything you find there for Session G.
- `apps/api/app/Modules/Import/Services/PartiesRowMapper.php:15-29` — `toPartnerData()` maps `party_kind`
  and `legal_form` through, and when `party_kind` is absent or blank it calls `PartyKind::deriveFrom()`
  (the M1 helper — one derivation, three consumers: migration arms, importer, service).
- **Result-workbook warning when derived**, non-blocking: from `ImportService::importParty()`
  (`apps/api/app/Modules/Import/Services/ImportService.php:438-447`) call
  `ImportService::addRowWarning($row, 'party_kind_derived', <detail naming the arm that fired>)`
  (`:93-99` — warnings never affect validity, success, failed-row counts or the failed-rows export).
  Mirror the message style of the existing Parties warnings in `PartiesBalancesPhase.php:243-262`.
- **Templates:** `getOptionalColumns()` feeds template/sample generation in
  `Import/Presentation/Controllers/ImportController.php` and `Import/Services/MigrationWizardService.php`.
  Confirm both pick the new columns up automatically; if either hand-lists the Parties columns, fix it.
  Session G owns the CSV+XLSX locale templates (R8) — on a collision, record it in `owes_parent` rather
  than editing Session G's files.
- Tests: extend the existing Parties import tests (`grep -rn 'ImportType::Parties' apps/api/tests/Feature/Import`)
  — explicit `party_kind` honoured verbatim; absent column derives per arm; invalid value → 422 naming
  `party_kind`; the warning present on derived rows and absent on supplied ones; `legal_form` persisted.

**M4 browser/API gate** (`e2e/session-h/m4-*.spec.ts`, :8011): upload a small Parties CSV through the
real import endpoints — one row with `party_kind=organization`, one with `party_kind` blank and a
`tax_id` (must derive to organization), one bare name+type=customer row (must derive to person) — then
`GET` the three partners and assert their `party_kind`, and `GET` the job's rows and assert the
`party_kind_derived` warning is on exactly the two derived rows. Reuse the fixture/upload helper the
existing import e2e specs use; if none exists, substitute a PHPUnit Feature test at the HTTP layer and
say so — do not skip the assertion.

**M4 review lenses:** `imports`.

---

**Lane done when:** M1–M4 ACCEPT; `pnpm typecheck && pnpm lint` clean in `apps/web`; `pnpm typecheck` +
`pnpm vitest run src/lib/customer` clean in `apps/pos`; PHPUnit by path green; PHPStan level 8 + Pint clean
on every touched PHP file; `php tools/feature-lane-manifest-check.php` and
`apps/web/tools/audit-tanstack-keys.mjs` green; `packages/shared/types/generated.d.ts` regenerated and
committed; YAML updated; **branch unmerged and unpushed**.

---

## §4 — Reporting

Per milestone: register in `docs/handoff/reviews/session-h-phase2/<M-id>-r<n>.md` (the script writes it),
YAML updated with status / commit SHA / verdict path / fix_rounds. At the end of the lane append a
≤25-line report to `docs/sessions/session-H-party-model-2026-08-29/LANE-REPORT-h2-party-kind.md`: files
changed, tests run with results, the AR strings you authored (flagged for native review), anything for
Session G, and `owes_parent` — at minimum the `partners:backfill-normalized-contact-points` staging run,
the `hashCustomerUuid` normalization deferral, and any i18n baseline re-pin.

## §5 — STOP conditions specific to this wave

1. Any change to a sealed payload **key set**, on either side.
2. Any canonical-bytes **value** change beyond the intended `customer.customer_category` derivation of
   §2 M2.3 — and even that stops if any of its five proofs fails.
3. Any need for a **POS device migration** or a schema bump past v67, or any change to the
   `SALE_RECEIPT` / `ACCOUNT_CHARGE` / `DEPOSIT_RECEIPT` key sets or the `b2b_facture_draft_requested`
   coherence rule (spec §1.5/§8.5 say none is needed — a contrary finding is an architecture
   contradiction, not a fix round).
4. Any need for an i18n baseline re-pin (parent-owned at promotion — record it in `owes_parent`).
5. Discovering that Phase 1 is **not** merged into the base you were given.
6. Any pull toward Phase 3 (facture escalation), Phase 4 (dedup ladder, `pos_receipt_party_links`,
   contacts tab, tombstone merge) or Phase 5 (device contacts). In particular do **not** touch
   `PartnerService`'s matching ladder (`PartnerService.php:67-69`) or `customerAttachUtils.hashCustomerUuid`.
7. Any owner-gated question, or fix rounds exhausted (4) on any milestone.
