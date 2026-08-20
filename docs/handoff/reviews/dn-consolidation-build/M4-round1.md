# Adversarial Merge-Gate Register — M4 (retirement + `consolidation_frequency`), round 1

> Round 1 was first attempted on 2026-08-19 and died as a tool error (machine sleep), leaving a stub.
> This file is that round, run from scratch against the same range. Nothing from the stub was reused.

**Range reviewed:** `864657dd9..2143af2e7` (M4 commits `b796c9b4f`, `38fdd18f9`, `4d747ce41`, `2143af2e7`), against the M4 row of `docs/handoff/CODEX-DISPATCH-dn-consolidation-build-2026-08-12.md:115`, spec §3.4 + §5 (`SPEC-dn-consolidation-billing-2026-08-11.md:710-729` and `:783-800`), and the amended preflight gate at `docs/handoff/progress/dn-consolidation-build.progress.yaml:21-46`.
**Lenses:** frontend-conventions, general.
**Everything below was re-derived from code and re-run locally. No claim in `2143af2e7`'s handback or YAML was taken on trust.**

---

## 0. What the contract required, and what the code actually does

| Contract item | Verdict | Evidence |
|---|---|---|
| Route + page + component + barrel deleted **in one commit** | **MET** | All four land in `38fdd18f9`: `apps/web/src/routes/index.tsx` (lazy import at old `:48`, route block at old `:1259-1270`), `features/documents/DeliveryNoteConsolidationPage.tsx`, `features/documents/components/DeliveryNoteConsolidation.tsx`, `features/documents/components/index.ts:29` |
| Grep proves zero remaining references | **MET for code, NOT met for i18n** | `grep -rni "consolidat" apps/web/src` → zero hits on the deleted symbols or the retired path; `git grep DeliveryNoteConsolidation -- ':!docs'` → only the three surviving **backend** test classes (`DeliveryNoteConsolidationTest/…AccessControlTest/…ConcurrencyTest`), which test the retained endpoint. **But 10 i18n leaves per locale that only the deleted UI consumed are still shipped and unrecorded — finding 3.** |
| No partner migration in the diff | **MET** | `git diff --name-only 864657dd9..2143af2e7 -- '*database/migrations*'` → 0. Whole-branch: only `2026_08_18_000001_add_delivery_note_uninvoiced_index.php` and `…_000002_create_delivery_note_billing_marks_table.php`; neither string-matches `partners` |
| `CreatePartnerRequest` relaxation **only** | **MET** | Whole-branch `git diff --stat 60df88a01..2143af2e7 -- apps/api/app/Modules/Partner/` is exactly `CreatePartnerRequest.php | 1 -` — the `Rule::requiredIf` at `CreatePartnerRequest.php:95`. `Rule` is still imported and still used (`:50`, `:61`), so no dead import |
| `UpdatePartnerRequest` still accepts null (§6.1 regression) | **MET** | `UpdatePartnerRequest.php:98-102` = `['sometimes','nullable', new Enum(ConsolidationFrequency::class)]`, untouched; `B2BPartnerTest::test_update_keeps_consolidation_frequency_optional_and_nullable` pins it. **I ran it: 18 passed / 77 assertions.** |
| No other request widened | **MET** | See the whole-branch Partner-module stat above; no other FormRequest in the branch touches `consolidation_frequency` |
| `invoice_consolidation` re-labelled en + fr | **MET** | `apps/web/src/locales/en/sales.json:117-118` / `fr/sales.json:117-118`; rendered at `B2BFieldsSection.tsx:208` and `:211-213`; both consumed, neither one-sided. `ar/sales.json` has no `partners.b2b` block at all — a pre-existing gap, not introduced here |
| §5 "no schema change" | **MET** | Column, `ConsolidationFrequency` enum and `PartnerData` projection all intact; `packages/shared/types/generated.d.ts:1802` still carries `consolidation_frequency` |
| No data loss on edit | **MET (verified, not assumed)** | `PartnerForm.tsx:398-428` builds `cleaned` from `PartnerFormData`, which no longer carries the key, so the PATCH body **omits** it; `UpdatePartnerRequest`'s `sometimes` therefore preserves a stored `weekly`. The form does **not** silently null it |
| Retirement covered by a test | **NOT MET — finding 1** | The milestone's only retirement test was written RED in `b796c9b4f` and **deleted** in the GREEN commit `38fdd18f9` |

---

### 1. **P2 — CONFIRMED — `b796c9b4f:apps/web/src/routes/DeliveryNoteConsolidationRoute.gates.test.tsx:47-55`, deleted by `38fdd18f9`** — the milestone's RED test asserted something false, and was deleted instead of corrected; the retirement now has **zero** regression coverage, and the handback narrates the gap as intent

`b796c9b4f` (the RED commit) rewrote the route gate test to `it('falls through to the dashboard even when Sales and invoice creation are enabled')`, asserting `findByText('dashboard fallback')` for `/inventory/delivery-notes/consolidate`. `38fdd18f9` then deleted the whole file. A red test removed by the commit that was supposed to turn it green is a CLAUDE rule 2 inversion, and it removed the only executable statement of what retirement means.

**The assertion was also factually wrong, which is why it could not go green.** `routes/index.tsx:1259` still declares `path="delivery-notes/:id"`, and React Router matches the literal segment `consolidate` to `:id`. I proved this rather than inferring it: a throwaway probe rendering `<AppRoutes />` at `/inventory/delivery-notes/consolidate` with `Dashboard` and the delivery-notes barrel stubbed printed `PROBE_BODY_START>>>DN DETAIL PAGE RENDERED<<<PROBE_BODY_END` and `queryByText('dashboard fallback')` was `null` (probe file removed; `git status` is clean apart from this register).

The handback records this as *"The generic UUID detail route is intentionally left to handle an arbitrary literal path"* (`HANDBACK-dn-consolidation-build-2026-08-12.md`, `2143af2e7` hunk). That sentence is not false, but it omits that the milestone's own RED test asserted the opposite outcome and was deleted rather than satisfied — and "handle" is doing work the code does not do (finding 2).

**Failure scenario:** a later merge or revert re-introduces the lazy import and the route block. Nothing in the suite fails; the design-system audit re-flags the component only if the component file also returns; the manifest drift check is already red at base for unrelated admin routes, so it will not be read as a signal. The retirement silently un-happens.

**Fix directive:** restore a route-level test at `apps/web/src/routes/` that asserts what actually renders at `/inventory/delivery-notes/consolidate` (the delivery-note detail surface, and specifically **not** a consolidation surface), so re-introducing the page fails a test.

---

### 2. **P2 — CONFIRMED — `apps/api/app/Modules/Document/Presentation/routes.php:48-50` (compare `:43-44`) reached from `apps/web/src/routes/index.tsx:1259` → `apps/web/src/features/documents/delivery-notes/DeliveryNoteDetailPage.tsx:58-64`** — the retired URL now resolves to the detail page and issues `GET /documents/consolidate`, which is a **500** on PostgreSQL, not a not-found

Per finding 1 the retired path falls into `delivery-notes/:id` with `id = 'consolidate'`. The detail page unconditionally fires `api.get('/documents/${id}')` (`DeliveryNoteDetailPage.tsx:61`, `enabled` only checks tenant/company/non-empty id). Server side:

- `Route::post('/documents/{document}/revert', …)->whereUuid('document')` — `routes.php:43-44` **has** the constraint;
- `Route::get('/documents/{document}', [DocumentController::class, 'showAny'])` — `routes.php:48-50` **does not**;
- `showAny` goes straight to `Document::forCompany($companyId)->…->find($document)` (`DocumentController.php:172-174`);
- `Document` uses `HasUuids` (`Document.php:111`) over a `uuid` primary key (`2025_11_30_080000_create_documents_table.php:14`).

So `where "id" = 'consolidate'` against a `uuid` column raises `SQLSTATE[22P02] invalid text representation` → 500, not the `NOT_FOUND` branch at `DocumentController.php:176-183`. This is the exact pitfall the repo already ledgers (validate before comparing a non-UUID against a PG `uuid` column) and the reason the sibling route five lines above carries `whereUuid`.

**Note on why the wave's preflight cannot see this:** the default PHPUnit path is SQLite (the ledgered `InventoryGlCompositeRootTest` residuals are "default-SQLite fixture reds"), and SQLite compares the text happily and returns `null` → a clean 404. The divergence is PostgreSQL-only; no green run in this wave contradicts the finding.

Severity is P2 rather than P1 because the page had zero inbound links (R10 §8, re-verified: zero hits repo-wide), so only a re-visited URL reaches it — but it is a logged, alertable server error on a path the wave deliberately created, and the handback claims the path is "handled".

**Fix directive:** add `->whereUuid('document')` to `routes.php:48` (one line, the same guard as `:44`) — or, if that is judged outside the M4 row, record it verbatim in `findings:` with this `file:line` and an owning ticket, rather than leaving the handback's "handled" claim standing.

---

### 3. **P2 — CONFIRMED — `apps/web/src/locales/en/sales.json:649-655, 670-674` and `fr/sales.json:649-655, 670-674`** — 10 i18n leaves per locale (20 strings) whose only consumers were the deleted components are still shipped, and are not recorded anywhere

Surviving-with-consumers: `deliveryNotes.consolidation.billingRefusal.*` (16 references across `ToBillPage.tsx:75-138` and `PartnerDeliveryNotesTab.tsx:213-273`) — correctly kept.

Orphaned (zero references anywhere in `apps/web/src`, verified per-key): `deliveryNotes.consolidation.title` ("Consolidate Delivery Notes" / "Regrouper les bons de livraison"), `.description`, `.noDeliveryNotes`, `.noDeliveryNotesDescription`, `.selected`, `.invoiceTotal`, `.createInvoice`, `.errors.differentPartners`, `.errors.differentCurrencies`, `.errors.invalidSelection`.

The M4 row's exit criterion is *"Grep proves zero remaining references"*, and the brief's own retirement list names the barrel and the component — but the retired copy is the part a future builder is most likely to resurrect by accident (it still reads as live product vocabulary: "Consolidate Delivery Notes", "Create Invoice", and the same-partner/same-currency error strings whose guard was **not** lifted into View B). No lint gate covers unused i18n keys (`pnpm lint` = eslint + `audit:keys` (TanStack) + `audit:design-system` + `audit:quantity` + the RuleTesters), so nothing else will ever surface these.

**Fix directive:** delete the 10 leaves from both `en` and `fr` (keep `billingRefusal.*`), or record them explicitly in the YAML `findings:` as retained-but-dead with the owning follow-on.

---

### 4. **P3 — CONFIRMED — `apps/web/src/features/documents/hooks/useDeliveryNotes.ts:83, 246, 265` and `apps/web/src/features/documents/api/deliveryNotes.ts:167`** — the retirement leaves four exported FE symbols with no production consumer, and a backend endpoint with no FE consumer

`useInvoiceableDeliveryNotes` (`:83`), `getInvoiceableDeliveryNotes` (`deliveryNotes.ts:167`, `GET /delivery-notes/invoiceable`), `groupDeliveryNotesByPartner` (`:246`) and `calculateConsolidationTotals` (`:265`) are now referenced **only** by tests (`hooks/__tests__/deliveryNotesTenantScope.test.tsx:162,201,249`, `api/deliveryNotes.test.ts:21-26`). Spec §3.4 invited lifting the grouping helper and the same-partner/same-currency guard into View B *"if still useful"*; they were not lifted, and the originals now have no caller. Their tests stay green forever, which is the failure mode worth naming: coverage that certifies dead code.

Recorded rather than blocking — deleting them is a judgement call about the `/delivery-notes/invoiceable` endpoint's future, which is outside the M4 row. It belongs in the M5 report.

---

### 5. **P3 — CONFIRMED — `38fdd18f9^:apps/web/src/features/documents/DeliveryNoteConsolidation.billingRefusal.test.tsx:174`** — the only test asserting the **French** refusal copy was deleted with the component, and no surviving test replaces it

The deleted case was `it('renders the no-artifact and selective-retry recovery in French')`. Deleting a component test with its component is correct, but the fr strings it pinned (`billingRefusal.guarantee`, `.removeAndRetry`, `fr/sales.json:656-669`) are still live product copy on two surfaces. The nearest surviving fr assertion is `PartnerDeliveryNotesTab.test.tsx:373` ("renders the owner-ruled neutral legacy label in English and French"), which covers the lane **badge**, not the refusal region. Net: the fr side of the OI-8 refusal surface is now unasserted.

---

### 6. **P3 — CONFIRMED — `docs/architecture/frontend.md:96`** — `- \`DeliveryNoteConsolidationPage\` - DN to invoice` still lists the deleted page in the architecture doc CLAUDE.md points builders at ("React patterns and components"). One-line deletion. (`docs/handoff/design-system-sweep-progress.md:434-436,517` and `docs/api/testing.md:56` also mention the name; the first is a historical progress log and the second refers to the surviving **backend** test class — both correctly left alone.)

---

### 7. **P3 — CONFIRMED — `docs/handoff/progress/dn-consolidation-build.progress.yaml:28-29` vs `apps/api/app/Modules/.../CopiesDocumentData.php:309-310`** — the "smaller PHPStan failure set" is not an M4 effect; the **pin itself is stale**

The handback claims *"the owner-expected two C-3 findings no longer reproduce, so the failure set is smaller"*. I audited the mechanism rather than accepting the improvement:

- `CopiesDocumentData.php` is untouched on the whole branch (`git diff --stat 60df88a01..2143af2e7 --` on that path is empty);
- `phpstan.neon` gains exactly one line on the branch — `:34`, an **added** rule (`DeliveryNoteBillingWritesOnlyViaClaimService`, M1); `ForbidHardcodedBcmathScale` is still registered;
- there is **no** `ignoreErrors` block in `phpstan.neon` and no edit to any rule class (`app/PHPStan/` diff = one file added, zero modified);
- `./vendor/bin/phpstan analyse app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php` at HEAD → `[OK] No errors`.

So this is **not** an evasion (no alias table, no suppression, no baseline absorption) — but it means the pinned `expected_inherited_residuals` entries for `:309`/`:310` never reproduced, and the differential gate is being compared against an inaccurate pin. M5 must re-derive the pin rather than inherit it.

---

### 8. **P3 — `apps/web/src/features/partners/components/B2BFieldsSection.tsx:208` vs `:211-213`** — the new help text restates the label verbatim ("Billed periodically" / "This customer is billed periodically."), and is not linked to the checkbox via `aria-describedby`

Literal compliance with spec §5's stated new meaning, so not a deviation. Two small improvements available: say what the classification *does* (it drives the To-bill "billed periodically" filter and nothing else — the one fact that stops it being read as automation), and wire `aria-describedby="…"` so a screen reader reads the help with the control. Recorded only.

---

## Gates I ran myself (nothing below is quoted from the handback)

| Gate | Result |
|---|---|
| `pnpm --filter @autoerp/web lint` | **0 errors** (6463 inherited warnings). `audit:keys` Gate C **0 new / 0 stale**; `audit:design-system` **728 acknowledged / 0 new / 0 stale**; `audit:quantity` **0**; all three eslint-rule RuleTesters pass |
| `pnpm --filter @autoerp/web typecheck` | clean |
| `vitest run src/features/partners src/routes` (default pool) | **17 files / 147 tests passed** — matches the claimed 147/147 |
| `vitest run src/features/documents/to-bill src/features/documents/delivery-notes src/features/documents/api` (the C9/M2/M3 surfaces) | **5 files / 33 tests passed** |
| `php artisan test tests/Feature/Partner/B2BPartnerTest.php` | **18 passed / 77 assertions**, incl. both new regressions |
| `phpstan analyse CreatePartnerRequest.php` and `CopiesDocumentData.php` | `[OK] No errors` on both |
| `pint --test` on both changed PHP files | `{"result":"pass"}` |
| `node scripts/factory/gen-route-manifest.mjs` regenerated into a temp dir, diffed | the three `/inventory/delivery-notes*` rows are **identical** to the committed manifest (line offsets only); the whole-file drift is the inherited admin-route generator defect, present at the pin and unchanged by M4 |
| Deleted-test arithmetic behind the "724/726" claim | 7 cases deleted (3 + 3 + 1) minus 2 added = −5, and 729→724 / 731→726. Internally consistent |

I did **not** re-run the whole-repo `§6.3` sweep or React Doctor; those remain executor-recorded evidence for M5 to re-derive (and see finding 7 for why the pin they are compared against needs re-deriving).

---

## Bypasses attempted that FAILED (I tried to break or exonerate the code and could not)

| Attempt | Result |
|---|---|
| Baseline absorption — did `4d747ce41` use the design-system baseline to swallow new debt? | No. Six entries removed, **zero added**; every removal maps to genuinely deleted code (two `DeliveryNoteConsolidation.tsx` checkboxes, three of its buttons, the `consolidation_frequency` `<select>`). `audit:design-system` reports **0 stale**, which is the independent proof the removed lines are gone rather than moved |
| Indirection that defeats a detector (alias table, renamed-equivalent literal, suppression comment containing a detector keyword) | None. `git diff 864657dd9..2143af2e7 -- apps/web apps/api | grep '^+' | grep -i 'eslint-disable\|ts-ignore\|ts-expect-error\|skip('` → empty |
| Hand-edited route manifest hiding a route the generator would still emit | No — regenerated it myself, DN rows byte-identical |
| Silent data loss: does an edit of a `weekly` partner now null the column? | No — the key is absent from the PATCH body (`PartnerForm.tsx:398-428`) and `UpdatePartnerRequest.php:99` is `sometimes` |
| A second widened request slipped in beside `CreatePartnerRequest` | No — whole-branch Partner-module diff is one deletion |
| A partner migration hidden outside `apps/api/database/migrations/tenant/` | No — zero migration files in the range; the two branch migrations don't mention `partners` |
| C9 / M2 / M3 regression via the retirement | No — `useConsolidateDeliveryNotes` (`useDeliveryNotes.ts:196-231`) and its seven scoped invalidations are untouched; the to-bill + partner-tab suites are green |
| Dead-Tailwind interpolation on the touched lines (`hover:${token}`, `${token}/50`) | None — the one added class list is `mt-1 text-sm ${colorTokens.text.subtle}` (`B2BFieldsSection.tsx:211`), a complete static token; the RuleTester gate passes |
| Raw i18n key strings or untranslated copy on the touched surface | None — both new keys resolve in en and fr; the PartnerForm tests assert the rendered English strings |
| Owner rules: OQ-11 hidden-not-disabled, dead controls, guarantees copy | Clean. This milestone **removes** a control whose backend never existed (the frequency selector had no scheduler, no job, no reader), which is OQ-11 executed rather than violated; the replacement copy claims no automation |

---

## Disposition

The substance of the M4 row is delivered and honestly built: the route, page, component and barrel go in one commit (`38fdd18f9`), code references are genuinely zero, the relaxation is confined to `CreatePartnerRequest` with the §6.1 Update regression proven by a test I ran, there is no partner migration and no schema change, the en/fr re-label is neutral about automation, and the M2/M3/C9 surfaces are untouched and green. The two mechanisms an evasive diff would use — baseline absorption and detector-defeating indirection — are both provably absent, and the one metric that improved (PHPStan) improved because the pin was wrong, not because anything was suppressed.

What blocks is smaller than any of that, and cheap: the milestone deleted its own failing test instead of correcting it, so **retirement is now the one thing in this wave with no executable proof** — and the assertion that was deleted (`falls through to the dashboard`) is false, which I demonstrated by rendering the route: the retired URL lands on the delivery-note detail page and fires `GET /documents/consolidate` at a route that, unlike its sibling five lines above, carries no `whereUuid` — a PostgreSQL 500 that the SQLite-default test path structurally cannot show. Add the 20 orphaned strings the deleted UI left behind, which no gate in this repository can ever see, and the "grep proves zero remaining references" criterion is met in code but not in copy. Findings 1 and 3 must be closed or explicitly recorded; finding 2 must be fixed with its one-line route constraint or disclosed verbatim in `findings:` in place of the handback's "handled". Findings 4–8 may ship recorded, and 4, 5 and 7 belong in the M5 report.

VERDICT: CHANGES-REQUIRED
